<?php
// app/Http/Controllers/Provider/ProviderJobController.php
namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;

class ProviderJobController extends Controller
{
    public function index(Request $request)
    {
        $providerId = $request->user()->provider_id;

        $jobs = Booking::where('provider_id', $providerId)
            ->with(['patient', 'driver', 'towTruck'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(15);

        return response()->json(['success' => true, 'data' => $jobs]);
    }

    public function accept(Request $request, $id)
    {
        $data = $request->validate([
            'driver_id'    => 'required|exists:drivers,id',
            'tow_truck_id' => 'required|exists:tow_trucks,id',
        ]);

        $job = Booking::where('provider_id', $request->user()->provider_id)
            ->where('status', 'pending')
            ->findOrFail($id);

        $job->update([
            'status'       => 'accepted',
            'driver_id'    => $data['driver_id'],
            'tow_truck_id' => $data['tow_truck_id'],
            'accepted_at'  => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Job accepted and driver assigned.',
            'data'    => $job->fresh(['driver', 'towTruck']),
        ]);
    }

    public function decline(Request $request, $id)
    {
        $job = Booking::where('provider_id', $request->user()->provider_id)
            ->where('status', 'pending')
            ->findOrFail($id);

        $job->update([
            'status'              => 'cancelled',
            'cancelled_at'        => now(),
            'cancellation_reason' => $request->input('reason', 'Declined by provider'),
        ]);

        return response()->json(['success' => true, 'message' => 'Job declined.']);
    }

    public function complete(Request $request, $id)
    {
        $job = Booking::where('provider_id', $request->user()->provider_id)
            ->whereIn('status', ['accepted', 'en_route', 'assigned'])
            ->findOrFail($id);

        $job->update(['status' => 'completed', 'completed_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Job marked as completed.']);
    }
}