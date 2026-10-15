<?php
// app/Http/Controllers/WalletController.php
namespace App\Http\Controllers;

use App\Models\VirtualAccount;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WalletController extends Controller
{
    public function __construct(private WalletService $walletService)
    {
    }

    // ── GET /wallet ──
    public function show(Request $request)
    {
        $patient = $request->user();
        $wallet = $this->walletService->getOrCreateWallet($patient);

        $wallet->load([
            'virtualAccounts' => function ($q) {
                $q->where('status', 'active')
                    ->where('paystack_reference', 'not like', 'MOCK-%')
                    ->orderBy('is_primary', 'desc');
            }
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'balance' => $wallet->balance,
                'currency' => $wallet->currency,
                'status' => $wallet->status,
                'virtual_accounts' => $wallet->virtualAccounts,
            ],
        ]);
    }

    // ── GET /wallet/transactions ──
    public function transactions(Request $request)
    {
        $patient = $request->user();
        $wallet = $this->walletService->getOrCreateWallet($patient);

        $transactions = $wallet->transactions()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $transactions,
        ]);
    }

    // ── POST /wallet/virtual-account ── create new virtual account
    public function createVirtualAccount(Request $request)
    {
        $data = $request->validate([
            'preferred_bank' => 'sometimes|string|in:wema-bank,titan-paystack,access-bank',
        ]);

        $patient = $request->user();
        $wallet = $this->walletService->getOrCreateWallet($patient);

        try {
            $account = $this->walletService->createVirtualAccount(
                $wallet,
                $patient,
                $data['preferred_bank'] ?? 'wema-bank'
            );

            return response()->json([
                'success' => true,
                'message' => 'Virtual account created successfully.',
                'data' => $account,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    // ── PUT /wallet/virtual-account/{id}/primary ── set as primary
    public function setPrimary(Request $request, int $id)
    {
        $patient = $request->user();
        $wallet = $this->walletService->getOrCreateWallet($patient);

        $account = VirtualAccount::where('wallet_id', $wallet->id)
            ->findOrFail($id);

        // Remove primary from all others
        VirtualAccount::where('wallet_id', $wallet->id)
            ->update(['is_primary' => false]);

        $account->update(['is_primary' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Primary account updated.',
        ]);
    }

    // ── DELETE /wallet/virtual-account/{id} ──
    public function deleteVirtualAccount(Request $request, int $id)
    {
        $patient = $request->user();
        $wallet = $this->walletService->getOrCreateWallet($patient);

        $account = VirtualAccount::where('wallet_id', $wallet->id)
            ->findOrFail($id);

        if ($account->is_primary && $wallet->virtualAccounts()->count() > 1) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete primary account. Set another as primary first.',
            ], 400);
        }

        $account->update(['status' => 'inactive']);

        return response()->json([
            'success' => true,
            'message' => 'Virtual account removed.',
        ]);
    }

    // ── POST /wallet/fund ── initialize Paystack checkout
    public function initializeFunding(Request $request)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:100',
        ]);

        $patient = $request->user();
        $wallet = $this->walletService->getOrCreateWallet($patient);

        try {
            $result = $this->walletService->initializeCheckout(
                $wallet,
                $patient,
                $data['amount']
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    // ── GET /wallet/verify/{reference} ── verify Paystack checkout
    public function verifyFunding(Request $request, string $reference)
    {
        try {
            $wallet = $this->walletService->getOrCreateWallet($request->user());
            $result = $this->walletService->verifyCheckout($reference, $wallet);

            return response()->json([
                'success' => true,
                'message' => 'Wallet funded successfully.',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    // ── POST /wallet/webhook ── Paystack webhook
    public function webhook(Request $request)
    {
        // Verify Paystack signature
        $signature = $request->header('x-paystack-signature');
        $payload = $request->getContent();
        $expected = hash_hmac('sha512', $payload, config('services.paystack.secret_key'));

        if (!config('services.paystack.secret_key') || !$signature || !hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        try {
            $this->walletService->handleWebhook($request->all());
        } catch (\Exception $e) {
            Log::error('Wallet webhook error: ' . $e->getMessage());
        }

        return response()->json(['message' => 'Webhook received.']);
    }
}