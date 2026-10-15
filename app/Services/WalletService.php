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
    public function __construct(private PaystackService $paystack, private PaymentService $payments)
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
    public function createVirtualAccount1(Wallet $wallet, Patient $patient, string $preferredBank = 'wema-bank'): array
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

    /**
     * Virtual accounts need Paystack Dedicated Virtual Accounts to be enabled on
     * the live business account. Until then they are switched off — the previous
     * placeholder generated made-up account numbers, which is unsafe.
     */
    public function createVirtualAccount(Wallet $wallet, Patient $patient, string $preferredBank = 'wema-bank'): array
    {
        throw new \DomainException('Bank transfer funding is coming soon. Please fund your wallet by card for now.');
    }

    public function initializeCheckout(Wallet $wallet, Patient $patient, float $amount): array
    {
        return $this->payments->startWalletFunding($wallet, $patient, $amount);
    }

    /** Verify a top-up that belongs to this patient's wallet, then credit it once. */
    public function verifyCheckout(string $reference, Wallet $wallet): array
    {
        $exists = WalletTransaction::where('reference', $reference)->where('wallet_id', $wallet->id)->exists();
        if (!$exists) {
            throw new \DomainException('Transaction not found.');
        }

        return $this->payments->completeWalletFunding($reference, $this->payments->verifyWithPaystack($reference));
    }

    public function handleWebhook(array $payload): void
    {
        $this->payments->handleWebhook($payload);
    }
}
