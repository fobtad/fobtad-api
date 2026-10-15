<?php
// app/Http/Controllers/Hospital/HospitalReportController.php
namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class HospitalReportController extends Controller
{
    private const PERIOD_DAYS = ['week' => 7, 'month' => 30, 'year' => 365];

    public function index(Request $request)
    {
        $request->validate(['period' => 'sometimes|in:week,month,year']);

        $period   = $request->input('period', 'month');
        $days     = self::PERIOD_DAYS[$period];
        $hospital = $request->user()->hospital;
        $now      = CarbonImmutable::now();
        $start    = $now->subDays($days)->startOfDay();
        $months   = $period === 'year' ? 12 : 6;

        // Everything the report needs, loaded once and aggregated in PHP so it
        // works the same on MySQL and SQLite.
        $earliest = min($start->subDays($days), $now->subMonths($months - 1)->startOfMonth());
        $bookings = Booking::where('hospital_id', $hospital->id)
            ->where('created_at', '>=', $earliest)
            ->with(['trip', 'paramedic:id,first_name,last_name', 'ambulance:id,plate_number', 'patient:id,first_name,last_name'])
            ->get();

        $notCancelled = $bookings->where('status', '!=', 'cancelled');
        $current      = $notCancelled->filter(fn($b) => $b->created_at >= $start);
        $previous     = $notCancelled->filter(fn($b) => $b->created_at < $start && $b->created_at >= $start->subDays($days));
        $completed    = $current->where('status', 'completed');

        $totalAmbulances = $hospital->ambulances()->count();
        $usedAmbulances  = $current->pluck('ambulance_id')->filter()->unique()->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'period' => $period,
                'kpis'   => [
                    'total_trips'          => $current->count(),
                    'previous_trips'       => $previous->count(),
                    'change_percent'       => $previous->count()
                        ? round(($current->count() - $previous->count()) / $previous->count() * 100, 1)
                        : null,
                    'completed_trips'      => $completed->count(),
                    'avg_response_minutes' => HospitalDashboardController::averageResponseMinutes($completed),
                    'active_paramedics'    => $hospital->paramedics()->where('status', '!=', 'off_duty')->count(),
                    'fleet_utilisation'    => $totalAmbulances ? round($usedAmbulances / $totalAmbulances * 100) : 0,
                ],
                'by_month'        => $this->byMonth($notCancelled, $now, $months),
                'by_day'          => $this->byDay($notCancelled, $now),
                'top_paramedics'  => $this->topParamedics($completed),
                'recent_activity' => $this->recentActivity($bookings, $hospital),
            ],
        ]);
    }

    private function byMonth($bookings, CarbonImmutable $now, int $months): array
    {
        return collect(range($months - 1, 0))->map(function ($i) use ($bookings, $now) {
            $month = $now->subMonths($i)->startOfMonth();
            $items = $bookings->filter(fn($b) => $b->created_at->isSameMonth($month));

            return [
                'month'     => $month->format('M'),
                'year'      => (int) $month->format('Y'),
                'emergency' => $items->where('type', 'emergency')->count(),
                'scheduled' => $items->whereIn('type', ['scheduled', 'recurring'])->count(),
                'maternal'  => $items->where('type', 'maternal')->count(),
                'mortuary'  => $items->where('type', 'mortuary')->count(),
                'total'     => $items->count(),
            ];
        })->values()->all();
    }

    private function byDay($bookings, CarbonImmutable $now): array
    {
        return collect(range(6, 0))->map(function ($i) use ($bookings, $now) {
            $day   = $now->subDays($i);
            $items = $bookings->filter(fn($b) => $b->created_at->isSameDay($day));

            return [
                'day'                  => $day->format('D'),
                'date'                 => $day->toDateString(),
                'trips'                => $items->count(),
                'avg_response_minutes' => HospitalDashboardController::averageResponseMinutes($items->where('status', 'completed')),
            ];
        })->values()->all();
    }

    private function topParamedics($completed): array
    {
        return $completed
            ->filter(fn($b) => $b->paramedic)
            ->groupBy('paramedic_id')
            ->map(fn($items) => [
                'id'                   => $items->first()->paramedic->id,
                'name'                 => $items->first()->paramedic->full_name,
                'trips'                => $items->count(),
                'rating'               => $items->whereNotNull('rating')->avg('rating')
                    ? round($items->whereNotNull('rating')->avg('rating'), 1) : null,
                'avg_response_minutes' => HospitalDashboardController::averageResponseMinutes($items),
            ])
            ->sortByDesc('trips')
            ->take(5)
            ->values()
            ->all();
    }

    /** Built from booking timestamps and fleet state (there's no audit log table yet). */
    private function recentActivity($bookings, $hospital): array
    {
        $events = collect();

        foreach ($bookings as $b) {
            $who = $b->patient ? trim("{$b->patient->first_name} {$b->patient->last_name}") : 'patient';

            $events->push(['type' => 'pending', 'at' => $b->created_at,
                'text' => "New " . str_replace('_', ' ', $b->type) . " request {$b->reference} from {$who}"]);

            if ($b->accepted_at && $b->paramedic) {
                $events->push(['type' => 'assigned', 'at' => $b->accepted_at,
                    'text' => "{$b->paramedic->full_name} assigned to {$b->reference}"
                        . ($b->ambulance ? " ({$b->ambulance->plate_number})" : '')]);
            }

            if ($b->completed_at) {
                $events->push(['type' => 'completed', 'at' => $b->completed_at,
                    'text' => "Trip {$b->reference} completed"]);
            }

            if ($b->cancelled_at) {
                $events->push(['type' => 'cancelled', 'at' => $b->cancelled_at,
                    'text' => "Booking {$b->reference} cancelled"]);
            }
        }

        foreach ($hospital->ambulances()->where('status', 'maintenance')->get() as $a) {
            $events->push(['type' => 'maintenance', 'at' => $a->updated_at,
                'text' => "{$a->plate_number} flagged for maintenance"]);
        }

        return $events->sortByDesc('at')->take(8)
            ->map(fn($e) => [...$e, 'at' => $e['at']->toIso8601String()])
            ->values()->all();
    }
}
