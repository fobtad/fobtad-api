<?php
// app/Http/Controllers/Hospital/HospitalDashboardController.php
namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;

class HospitalDashboardController extends Controller
{
    public function index(Request $request)
    {
        $hospital   = $request->user()->hospital;
        $hospitalId = $hospital->id;
        $bookings   = fn() => Booking::where('hospital_id', $hospitalId);

        $paramedicCounts = $hospital->paramedics()
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $ambulanceCounts = $hospital->ambulances()
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $tripsToday     = $bookings()->whereDate('created_at', today())->count();
        $tripsYesterday = $bookings()->whereDate('created_at', today()->subDay())->count();

        $completedToday = $bookings()
            ->where('status', 'completed')
            ->whereDate('completed_at', today())
            ->with('trip')
            ->get();

        $activeTrips = $bookings()
            ->whereIn('status', Booking::ACTIVE_STATUSES)
            ->with(['patient', 'paramedic', 'ambulance', 'trip'])
            ->oldest('accepted_at')
            ->get();

        $recentBookings = $bookings()
            ->with(['patient', 'paramedic', 'ambulance'])
            ->latest()
            ->take(6)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'stats' => [
                    'paramedics' => [
                        'total'     => $paramedicCounts->sum(),
                        'available' => (int) ($paramedicCounts['available'] ?? 0),
                        'on_trip'   => (int) ($paramedicCounts['on_trip'] ?? 0),
                        'standby'   => (int) ($paramedicCounts['standby'] ?? 0),
                        'off_duty'  => (int) ($paramedicCounts['off_duty'] ?? 0),
                    ],
                    'fleet' => [
                        'total'       => $ambulanceCounts->sum(),
                        'available'   => (int) ($ambulanceCounts['available'] ?? 0),
                        'on_trip'     => (int) ($ambulanceCounts['on_trip'] ?? 0),
                        'maintenance' => (int) ($ambulanceCounts['maintenance'] ?? 0),
                        'off_duty'    => (int) ($ambulanceCounts['off_duty'] ?? 0),
                    ],
                    'trips_today'       => $tripsToday,
                    'trips_yesterday'   => $tripsYesterday,
                    'completed_today'   => $completedToday->count(),
                    'pending'           => $bookings()->where('status', 'pending')->count(),
                    'pending_emergency' => $bookings()->where('status', 'pending')->where('type', 'emergency')->count(),
                    'active'            => $activeTrips->count(),
                ],
                'today' => [
                    'completed'             => $completedToday->count(),
                    'emergency'             => $bookings()->whereDate('created_at', today())->where('type', 'emergency')->count(),
                    'scheduled'             => $bookings()->whereDate('created_at', today())->whereIn('type', ['scheduled', 'recurring'])->count(),
                    'avg_response_minutes'  => self::averageResponseMinutes($completedToday),
                    'distance_km'           => round($completedToday->sum(fn($b) => $b->trip?->distance_km ?? 0), 1),
                ],
                'active_trips'    => $activeTrips,
                'recent_bookings' => $recentBookings,
                'hospital'        => $hospital,
            ],
        ]);
    }

    /**
     * Response time = booking created → crew arrived (falls back to crew assigned
     * when arrival wasn't recorded). Returns null when there's nothing to average.
     */
    public static function averageResponseMinutes($bookings): ?float
    {
        $minutes = $bookings
            ->map(function ($b) {
                $end = $b->trip?->arrived_at ?? $b->accepted_at;
                return $end ? $b->created_at->diffInSeconds($end) / 60 : null;
            })
            ->filter(fn($m) => $m !== null);

        return $minutes->isEmpty() ? null : round($minutes->avg(), 1);
    }
}
