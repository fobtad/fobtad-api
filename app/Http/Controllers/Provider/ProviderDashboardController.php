<?php
// app/Http/Controllers/Provider/ProviderDashboardController.php
namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hospital\HospitalDashboardController;
use App\Models\Booking;
use Illuminate\Http\Request;

class ProviderDashboardController extends Controller
{
    public function index(Request $request)
    {
        $provider   = $request->user()->provider;
        $providerId = $provider->id;
        $jobs       = fn() => Booking::where('provider_id', $providerId);

        $driverCounts = $provider->drivers()
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $vehicleCounts = $provider->towTrucks()
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $completedToday = $jobs()
            ->where('status', 'completed')
            ->whereDate('completed_at', today())
            ->with('trip')
            ->get();

        $activeJobs = $jobs()
            ->whereIn('status', Booking::ACTIVE_STATUSES)
            ->with(['patient', 'driver', 'towTruck', 'trip'])
            ->oldest('accepted_at')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'stats' => [
                    'drivers' => [
                        'total'     => $driverCounts->sum(),
                        'available' => (int) ($driverCounts['available'] ?? 0),
                        'on_job'    => (int) ($driverCounts['on_job'] ?? 0),
                        'standby'   => (int) ($driverCounts['standby'] ?? 0),
                        'off_duty'  => (int) ($driverCounts['off_duty'] ?? 0),
                    ],
                    'fleet' => [
                        'total'       => $vehicleCounts->sum(),
                        'available'   => (int) ($vehicleCounts['available'] ?? 0),
                        'on_job'      => (int) ($vehicleCounts['on_job'] ?? 0),
                        'maintenance' => (int) ($vehicleCounts['maintenance'] ?? 0),
                        'off_duty'    => (int) ($vehicleCounts['off_duty'] ?? 0),
                    ],
                    'jobs_today'      => $jobs()->whereDate('created_at', today())->count(),
                    'jobs_yesterday'  => $jobs()->whereDate('created_at', today()->subDay())->count(),
                    'completed_today' => $completedToday->count(),
                    'pending'         => $jobs()->where('status', 'pending')->count(),
                    'active'          => $activeJobs->count(),
                ],
                'today' => [
                    'completed'            => $completedToday->count(),
                    'avg_job_minutes'      => ($m = $completedToday->pluck('trip.duration_minutes')->filter()->avg()) ? round($m) : null,
                    'avg_response_minutes' => HospitalDashboardController::averageResponseMinutes($completedToday),
                    'distance_km'          => round($completedToday->sum(fn($b) => $b->trip?->distance_km ?? 0), 1),
                    'by_issue'             => $jobs()->whereDate('created_at', today())
                        ->selectRaw('incident_type, count(*) as total')->groupBy('incident_type')->pluck('total', 'incident_type'),
                ],
                'active_jobs' => $activeJobs,
                'recent_jobs' => $jobs()->with(['patient', 'driver', 'towTruck'])->latest()->take(6)->get(),
                'provider'    => $provider,
            ],
        ]);
    }
}
