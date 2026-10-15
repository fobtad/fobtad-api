<?php
// app/Http/Controllers/EarningsController.php
namespace App\Http\Controllers;

use App\Models\Earning;
use App\Models\HospitalUser;
use App\Models\Payout;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Earnings and weekly payouts for both portals. The payee (hospital or
 * provider) is whichever organisation the signed-in staff member belongs to.
 */
class EarningsController extends Controller
{
    public function index(Request $request)
    {
        [$type, $org] = $this->payee($request);
        $earnings = Earning::where('payee_type', $type)->where('payee_id', $org->id);
        $payouts  = Payout::where('payee_type', $type)->where('payee_id', $org->id);

        $lastRequest = (clone $payouts)->where('status', '!=', 'rejected')->latest()->first();
        $interval    = (int) PlatformSetting::get('payout_interval_days');
        $nextAt      = $lastRequest ? $lastRequest->created_at->addDays($interval) : null;

        return response()->json([
            'success' => true,
            'data'    => [
                'available'          => round((clone $earnings)->whereNull('payout_id')->sum('net'), 2),
                'pending_payout'     => round((clone $payouts)->where('status', 'requested')->sum('amount'), 2),
                'paid_out'           => round((clone $payouts)->where('status', 'paid')->sum('amount'), 2),
                'lifetime_gross'     => round((clone $earnings)->sum('gross'), 2),
                'lifetime_commission'=> round((clone $earnings)->sum('commission'), 2),
                'lifetime_net'       => round((clone $earnings)->sum('net'), 2),
                'commission_percent' => (float) PlatformSetting::get('commission_percent'),
                'payout_interval_days' => $interval,
                'next_payout_at'     => $nextAt && $nextAt->isFuture() ? $nextAt->toIso8601String() : null,
                'bank'               => [
                    'bank_name'      => $org->bank_name,
                    'account_number' => $org->account_number,
                    'account_name'   => $org->account_name,
                ],
                'recent' => (clone $earnings)->with('booking:id,reference,type,incident_type,completed_at,cancelled_at')
                    ->latest()->take(15)->get(),
                'payouts' => (clone $payouts)->latest()->take(15)->get(),
            ],
        ]);
    }

    /** PUT /bank-account — where payouts are sent. Admins only. */
    public function updateBank(Request $request)
    {
        [, $org] = $this->payee($request);
        $this->ensureAdmin($request);

        $data = $request->validate([
            'bank_name'      => 'required|string|max:100',
            'account_number' => 'required|digits:10',
            'account_name'   => 'required|string|max:150',
        ]);

        $org->update($data);

        return response()->json(['success' => true, 'message' => 'Bank account saved.', 'data' => $data]);
    }

    /** POST /payouts — request everything available, at most once per payout interval. */
    public function requestPayout(Request $request)
    {
        [$type, $org] = $this->payee($request);
        $this->ensureAdmin($request);

        if (!$org->account_number) {
            return response()->json(['success' => false, 'message' => 'Add your bank account before requesting a payout.'], 422);
        }

        $interval = (int) PlatformSetting::get('payout_interval_days');

        $payout = DB::transaction(function () use ($type, $org, $request, $interval) {
            $recent = Payout::where('payee_type', $type)->where('payee_id', $org->id)
                ->where('status', '!=', 'rejected')
                ->where('created_at', '>', now()->subDays($interval))
                ->lockForUpdate()->exists();

            if ($recent) {
                abort(response()->json(['success' => false, 'message' => "You can request one payout every {$interval} days."], 422));
            }

            $earnings = Earning::where('payee_type', $type)->where('payee_id', $org->id)
                ->whereNull('payout_id')->lockForUpdate()->get();
            $amount = round($earnings->sum('net'), 2);

            if ($amount <= 0) {
                abort(response()->json(['success' => false, 'message' => 'There is nothing to pay out yet.'], 422));
            }

            $payout = Payout::create([
                'reference'      => 'PO-' . strtoupper(Str::random(10)),
                'payee_type'     => $type,
                'payee_id'       => $org->id,
                'amount'         => $amount,
                'status'         => 'requested',
                'bank_name'      => $org->bank_name,
                'account_number' => $org->account_number,
                'account_name'   => $org->account_name,
                'requested_by'   => $request->user()->email,
            ]);

            Earning::whereIn('id', $earnings->pluck('id'))->update(['payout_id' => $payout->id]);

            return $payout;
        });

        return response()->json(['success' => true, 'message' => 'Payout requested. FOBTAD will process it shortly.', 'data' => $payout], 201);
    }

    private function payee(Request $request): array
    {
        $user = $request->user();

        return $user instanceof HospitalUser ? ['hospital', $user->hospital] : ['provider', $user->provider];
    }

    private function ensureAdmin(Request $request): void
    {
        if ($request->user()->role !== 'admin') {
            abort(response()->json(['success' => false, 'message' => 'Only administrators can manage payouts.'], 403));
        }
    }
}
