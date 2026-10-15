<?php
// app/Http/Controllers/Admin/AdminDashboardController.php
namespace App\Http\Controllers\Admin;

use App\Models\Booking;
use App\Models\Earning;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Provider;
use App\Models\Wallet;
use Illuminate\Http\Request;

class AdminDashboardController extends AdminController
{
    public function index(Request $request)
    {
        $statusCounts = fn($model) => $model::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $monthStart   = now()->startOfMonth();

        return response()->json([
            'success' => true,
            'data'    => [
                'patients'  => Patient::count(),
                'hospitals' => $statusCounts(Hospital::class),
                'providers' => $statusCounts(Provider::class),
                'bookings'  => [
                    'today'      => Booking::whereDate('created_at', today())->count(),
                    'active'     => Booking::whereIn('status', Booking::ACTIVE_STATUSES)->count(),
                    'pending'    => Booking::where('status', 'pending')->count(),
                    // Nobody can see these: no hospital/provider was available when booked.
                    'unassigned' => Booking::where('status', 'pending')->whereNull('hospital_id')->whereNull('provider_id')->count(),
                    'emergency_pending' => Booking::where('status', 'pending')->where('type', 'emergency')->count(),
                ],
                'money' => [
                    'collected_month'  => round(Payment::where('status', 'success')->where('paid_at', '>=', $monthStart)->sum('amount'), 2),
                    'commission_month' => round(Earning::where('created_at', '>=', $monthStart)->sum('commission'), 2),
                    'outstanding_due'  => round(Booking::whereIn('payment_status', ['unpaid', 'partially_paid'])
                        ->whereIn('status', ['completed', 'cancelled'])->whereNotNull('fare')
                        ->get()->sum(fn($b) => $b->amount_due), 2),
                    'payouts_requested'=> round(Payout::where('status', 'requested')->sum('amount'), 2),
                    'payouts_requested_count' => Payout::where('status', 'requested')->count(),
                    'wallet_balances'  => round(Wallet::sum('balance'), 2),
                ],
                'recent_bookings' => Booking::with(['patient:id,first_name,last_name', 'hospital:id,name', 'provider:id,company_name'])
                    ->latest()->take(8)->get(),
                'pending_approvals' => [
                    'hospitals' => Hospital::where('status', 'pending')->latest()->take(5)->get(['id', 'name', 'email', 'created_at']),
                    'providers' => Provider::where('status', 'pending')->latest()->take(5)->get(['id', 'company_name', 'email', 'created_at']),
                ],
            ],
        ]);
    }
}
