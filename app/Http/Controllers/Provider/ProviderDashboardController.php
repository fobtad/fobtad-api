<?php
// app/Http/Controllers/Provider/ProviderDashboardController.php
namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;

class ProviderDashboardController extends Controller
{
    public function index(Request $request)
    {
        $provider   = $request->user()->provider;
        $providerId = $provider->id;

        $totalJobs       = Booking::where('provider_id', $providerId)->count();
        $pendingJobs     = Booking::where('provider_id', $providerId)->where('status', 'pending')->count();
        $activeJobs      = Booking::where('provider_id', $providerId)->whereIn('status', ['accepted', 'assigned', 'en_route'])->count();
        $completedToday  = Booking::where('provider_id', $providerId)->where('status', 'completed')->whereDate('completed_at', today())->count();
        $availableDrivers= $provider->availableDrivers()->count();
        $availableTrucks = $provider->availableTrucks()->count();

        $recentJobs = Booking::where('provider_id', $providerId)
            ->with(['patient', 'driver', 'towTruck'])
            ->latest()
            ->take(10)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'stats' => [
                    'total_jobs'       => $totalJobs,
                    'pending'          => $pendingJobs,
                    'active'           => $activeJobs,
                    'completed_today'  => $completedToday,
                    'available_drivers'=> $availableDrivers,
                    'available_trucks' => $availableTrucks,
                ],
                'recent_jobs' => $recentJobs,
                'provider'    => $provider,
            ],
        ]);
    }
}