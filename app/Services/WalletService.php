<?php
// app/Services/WalletService.php
namespace App\Services;

use App\Models\Patient;
use App\Models\VirtualAccount;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Str;

class WalletService
{
    public function __construct(private PaystackService $paystack)
    {
    }

    // ── Create or get wallet ──
    public function getOrCreateWallet(Patient $patient): Wallet
    {
        return Wallet::firstOrCreate(
            ['patient_id' => $patient->id],
            ['balance' => 0.00, 'currency' => 'NGN', 'status' => 'active']
        );
    }

    // ── Create virtual account ──
    public function createVirtualAccount(Wallet $wallet, Patient $patient, string $preferredBank = 'wema-bank'): array
    {
        $customerResponse = $this->paystack->createCustomer($patient);

        if (!($customerResponse['status'] ?? false)) {
            $fetchResponse = $this->paystack->getCustomer($patient->email);
            $customerData = $fetchResponse['data'] ?? null;
        } else {
            $customerData = $customerResponse['data'] ?? null;
        }

        if (!$customerData) {
            throw new \Exception('Could not create or retrieve Paystack customer.');
        }

        $customerCode = $customerData['customer_code'];
        $response = $this->paystack->createDedicatedAccount($customerCode, $preferredBank);

        if (!($response['status'] ?? false)) {
            throw new \Exception($response['message'] ?? 'Failed to create virtual account.');
        }

        $accountData = $response['data'];
        $isPrimary = !$wallet->virtualAccounts()->exists();

        $virtualAccount = VirtualAccount::create([
            'wallet_id' => $wallet->id,
            'account_number' => $accountData['account_number'],
            'account_name' => $accountData['account_name'],
            'bank_name' => $accountData['bank']['name'],
            'bank_code' => $accountData['bank']['slug'] ?? null,
            'paystack_reference' => $accountData['id'] ?? null,
            'customer_code' => $customerCode,
            'is_primary' => $isPrimary,
            'status' => 'active',
        ]);

        return $virtualAccount->toArray();
    }

    // ── Initialize Paystack checkout funding ──
    public function initializeCheckout(Wallet $wallet, Patient $patient, float $amount): array
    {
        $reference = 'WLT-' . strtoupper(Str::random(12));

        $result = $this->paystack->initializePayment(
            $patient->email,
            $amount,
            $reference,
            [
                'wallet_id' => $wallet->id,
                'patient_id' => $patient->id,
                'type' => 'wallet_funding',
            ]
        );

        if (!($result['status'] ?? false)) {
            throw new \Exception($result['message'] ?? 'Payment initialization failed.');
        }

        // Create pending transaction
        $wallet->transactions()->create([
            'type' => 'credit',
            'amount' => $amount,
            'balance_before' => $wallet->balance,
            'balance_after' => $wallet->balance,
            'reference' => $reference,
            'description' => 'Wallet funding via Paystack',
            'source' => 'paystack_checkout',
            'status' => 'pending',
        ]);

        return [
            'payment_url' => $result['data']['authorization_url'],
            'reference' => $reference,
        ];
    }

    // ── Verify and credit wallet ──
    public function verifyCheckout(string $reference): array
    {
        $response = $this->paystack->verifyPayment($reference);

        if (!($response['status'] ?? false)) {
            throw new \Exception('Verification failed.');
        }

        $txData = $response['data'];

        if ($txData['status'] !== 'success') {
            throw new \Exception('Payment was not successful.');
        }

        $amount = $txData['amount'] / 100;

        // Find the transaction regardless of status
        $transaction = WalletTransaction::where('reference', $reference)->first();

        if (!$transaction) {
            throw new \Exception('Transaction not found.');
        }

        $wallet = $transaction->wallet;

        // ── Already credited — just return current balance ──
        if ($transaction->status === 'success') {
            return [
                'amount' => $amount,
                'balance' => $wallet->fresh()->balance,
            ];
        }

        // ── Credit wallet atomically ──
        \DB::transaction(function () use ($wallet, $transaction, $amount, $reference) {
            $balanceBefore = $wallet->balance;
            $balanceAfter = $balanceBefore + $amount;

            // Update wallet balance
            $wallet->increment('balance', $amount);

            // Update the existing pending transaction — no new insert
            $transaction->update([
                'status' => 'success',
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ]);
        });

        return [
            'amount' => $amount,
            'balance' => $wallet->fresh()->balance,
        ];
    }

    // ── Handle webhook ──
    public function handleWebhook(array $payload): void
    {
        $event = $payload['event'] ?? '';

        if (in_array($event, ['dedicatedaccount.assign.success', 'charge.success'])) {
            $data = $payload['data'];
            $amount = $data['amount'] / 100;
            $reference = $data['reference'];
            $customerCode = $data['customer']['customer_code'] ?? null;

            if (!$customerCode)
                return;

            $virtualAccount = VirtualAccount::where('customer_code', $customerCode)->first();
            if (!$virtualAccount)
                return;

            $wallet = $virtualAccount->wallet;

            // Idempotency — skip if already processed
            $exists = $wallet->transactions()->where('reference', $reference)->exists();
            if ($exists)
                return;

            $wallet->credit($amount, $reference, 'Bank transfer', 'bank_transfer');
        }
    }
}