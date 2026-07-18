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

        $totalBookings     = Booking::where('hospital_id', $hospitalId)->count();
        $pendingBookings   = Booking::where('hospital_id', $hospitalId)->where('status', 'pending')->count();
        $activeBookings    = Booking::where('hospital_id', $hospitalId)->whereIn('status', ['accepted', 'assigned', 'en_route', 'arrived'])->count();
        $completedToday    = Booking::where('hospital_id', $hospitalId)->where('status', 'completed')->whereDate('completed_at', today())->count();
        $availableParamedics = $hospital->availableParamedics()->count();
        $availableAmbulances = $hospital->availableAmbulances()->count();

        $recentBookings = Booking::where('hospital_id', $hospitalId)
            ->with(['patient', 'paramedic', 'ambulance'])
            ->latest()
            ->take(10)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'stats' => [
                    'total_bookings'      => $totalBookings,
                    'pending'             => $pendingBookings,
                    'active'              => $activeBookings,
                    'completed_today'     => $completedToday,
                    'available_paramedics'=> $availableParamedics,
                    'available_ambulances'=> $availableAmbulances,
                ],
                'recent_bookings' => $recentBookings,
                'hospital'        => $hospital,
            ],
        ]);
    }
}