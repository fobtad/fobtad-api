<?php
// app/Http/Controllers/Provider/ProviderReportController.php
namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hospital\HospitalDashboardController;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Job activity for the Earnings page. Money figures only use fares recorded on
 * jobs; commission and payouts wait on the revenue model.
 */
class ProviderReportController extends Controller
{
    private const PERIOD_DAYS = ['week' => 7, 'month' => 30, 'year' => 365];

    public function index(Request $request)
    {
        $request->validate(['period' => 'sometimes|in:week,month,year']);

        $period   = $request->input('period', 'month');
        $days     = self::PERIOD_DAYS[$period];
        $provider = $request->user()->provider;
        $now      = CarbonImmutable::now();
        $start    = $now->subDays($days)->startOfDay();
        $months   = $period === 'year' ? 12 : 6;

        $earliest = min($start->subDays($days), $now->subMonths($months - 1)->startOfMonth());
        $jobs = Booking::where('provider_id', $provider->id)
            ->where('created_at', '>=', $earliest)
            ->with(['trip', 'driver:id,first_name,last_name', 'towTruck:id,plate_number', 'patient:id,first_name,last_name'])
            ->get();

        $current   = $jobs->filter(fn($b) => $b->created_at >= $start && $b->status !== 'cancelled');
        $previous  = $jobs->filter(fn($b) => $b->created_at < $start && $b->created_at >= $start->subDays($days) && $b->status !== 'cancelled');
        $completed = $current->where('status', 'completed');

        return response()->json([
            'success' => true,
            'data'    => [
                'period' => $period,
                'kpis'   => [
                    'total_jobs'           => $current->count(),
                    'previous_jobs'        => $previous->count(),
                    'change_percent'       => $previous->count()
                        ? round(($current->count() - $previous->count()) / $previous->count() * 100, 1)
                        : null,
                    'completed_jobs'       => $completed->count(),
                    'gross_fares'          => round($completed->sum('fare'), 2),
                    'avg_job_minutes'      => ($m = $completed->pluck('trip.duration_minutes')->filter()->avg()) ? round($m) : null,
                    'avg_response_minutes' => HospitalDashboardController::averageResponseMinutes($completed),
                ],
                'by_month' => collect(range($months - 1, 0))->map(function ($i) use ($jobs, $now) {
                    $month = $now->subMonths($i)->startOfMonth();
                    $items = $jobs->filter(fn($b) => $b->created_at->isSameMonth($month) && $b->status !== 'cancelled');
                    return [
                        'month'     => $month->format('M'),
                        'year'      => (int) $month->format('Y'),
                        'jobs'      => $items->count(),
                        'completed' => $items->where('status', 'completed')->count(),
                        'fares'     => round($items->where('status', 'completed')->sum('fare'), 2),
                    ];
                })->values()->all(),
                'top_drivers' => $completed
                    ->filter(fn($b) => $b->driver)
                    ->groupBy('driver_id')
                    ->map(fn($items) => [
                        'name'   => $items->first()->driver->full_name,
                        'jobs'   => $items->count(),
                        'rating' => $items->whereNotNull('rating')->avg('rating') ? round($items->whereNotNull('rating')->avg('rating'), 1) : null,
                    ])
                    ->sortByDesc('jobs')->take(5)->values()->all(),
                'recent_completed' => $jobs->where('status', 'completed')
                    ->sortByDesc('completed_at')->take(10)
                    ->map(fn($b) => [
                        'id'           => $b->id,
                        'reference'    => $b->reference,
                        'issue'        => $b->incident_type,
                        'customer'     => $b->patient ? trim("{$b->patient->first_name} {$b->patient->last_name}") : null,
                        'driver'       => $b->driver?->full_name,
                        'vehicle'      => $b->towTruck?->plate_number,
                        'fare'         => $b->fare,
                        'completed_at' => $b->completed_at?->toIso8601String(),
                    ])->values()->all(),
            ],
        ]);
    }
}
