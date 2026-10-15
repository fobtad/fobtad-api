<?php
// app/Http/Controllers/Admin/AdminFinanceController.php
namespace App\Http\Controllers\Admin;

use App\Models\Earning;
use App\Models\Hospital;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Provider;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Money: booking payments, wallet activity, commission and payouts. */
class AdminFinanceController extends AdminController
{
    public function summary(Request $request)
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to   = ($request->date('to') ?? now())->endOfDay();

        $payments = Payment::where('status', 'success')->whereBetween('paid_at', [$from, $to]);
        $earnings = Earning::whereBetween('created_at', [$from, $to]);

        return response()->json([
            'success' => true,
            'data'    => [
                'from'             => $from->toDateString(),
                'to'               => $to->toDateString(),
                'collected'        => round((clone $payments)->sum('amount'), 2),
                'collected_wallet' => round((clone $payments)->where('method', 'wallet')->sum('amount'), 2),
                'collected_card'   => round((clone $payments)->where('method', 'card')->sum('amount'), 2),
                'gross_earned'     => round((clone $earnings)->sum('gross'), 2),
                'commission'       => round((clone $earnings)->sum('commission'), 2),
                'owed_to_partners' => round(Earning::whereNull('payout_id')->sum('net'), 2),
                'payouts_paid'     => round(Payout::where('status', 'paid')->whereBetween('processed_at', [$from, $to])->sum('amount'), 2),
                'wallet_topups'    => round(WalletTransaction::where('source', 'paystack_checkout')->where('status', 'success')
                    ->whereBetween('updated_at', [$from, $to])->sum('amount'), 2),
                'refunds'          => round(WalletTransaction::where('source', 'refund')->whereBetween('created_at', [$from, $to])->sum('amount'), 2),
            ],
        ]);
    }

    public function payments(Request $request)
    {
        $payments = Payment::with(['booking:id,reference,type', 'patient:id,first_name,last_name,email'])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->method, fn($q, $m) => $q->where('method', $m))
            ->when($request->search, fn($q, $s) => $q->where('reference', 'like', "%{$s}%"))
            ->latest()
            ->paginate($this->perPage($request));

        return response()->json(['success' => true, 'data' => $payments]);
    }

    public function walletTransactions(Request $request)
    {
        $tx = WalletTransaction::with('wallet.patient:id,first_name,last_name,email')
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->source, fn($q, $s) => $q->where('source', $s))
            ->when($request->search, fn($q, $s) => $q->where('reference', 'like', "%{$s}%"))
            ->latest()
            ->paginate($this->perPage($request));

        return response()->json(['success' => true, 'data' => $tx]);
    }

    public function payouts(Request $request)
    {
        $payouts = Payout::withCount('earnings')
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->orderByRaw("case status when 'requested' then 0 else 1 end")
            ->latest()
            ->paginate($this->perPage($request));

        // Attach the payee's name for display.
        $names = [
            'hospital' => Hospital::whereIn('id', $payouts->where('payee_type', 'hospital')->pluck('payee_id'))->pluck('name', 'id'),
            'provider' => Provider::whereIn('id', $payouts->where('payee_type', 'provider')->pluck('payee_id'))->pluck('company_name', 'id'),
        ];
        $payouts->getCollection()->transform(fn($p) => [...$p->toArray(), 'payee_name' => $names[$p->payee_type][$p->payee_id] ?? null]);

        return response()->json(['success' => true, 'data' => $payouts]);
    }

    /** Mark a requested payout as paid (after the bank transfer) or reject it. */
    public function processPayout(Request $request, $id)
    {
        $this->authorizeAbility($request, 'operations');

        $data = $request->validate([
            'status'     => 'required|in:paid,rejected',
            'admin_note' => 'required_if:status,rejected|nullable|string|max:500',
        ]);

        $payout = DB::transaction(function () use ($id, $data, $request) {
            $payout = Payout::where('status', 'requested')->lockForUpdate()->findOrFail($id);

            $payout->update([
                'status'       => $data['status'],
                'admin_note'   => trim(($data['admin_note'] ?? '') . " — {$request->user()->email}", ' —'),
                'processed_at' => now(),
            ]);

            // Rejected: the earnings go back to the partner's available balance.
            if ($data['status'] === 'rejected') {
                Earning::where('payout_id', $payout->id)->update(['payout_id' => null]);
            }

            return $payout;
        });

        return response()->json(['success' => true, 'message' => $data['status'] === 'paid' ? 'Payout marked as paid.' : 'Payout rejected.', 'data' => $payout]);
    }
}
