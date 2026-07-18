<?php
// app/Http/Controllers/Hospital/HospitalBookingController.php
namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;

class HospitalBookingController extends Controller
{
    public function index(Request $request)
    {
        $hospitalId = $request->user()->hospital_id;

        $bookings = Booking::where('hospital_id', $hospitalId)
            ->with(['patient', 'paramedic', 'ambulance'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->latest()
            ->paginate(15);

        return response()->json(['success' => true, 'data' => $bookings]);
    }

    public function accept(Request $request, $id)
    {
        $data = $request->validate([
            'paramedic_id' => 'required|exists:paramedics,id',
            'ambulance_id' => 'required|exists:ambulances,id',
        ]);

        $booking = Booking::where('hospital_id', $request->user()->hospital_id)
            ->where('status', 'pending')
            ->findOrFail($id);

        $booking->update([
            'status'       => 'accepted',
            'paramedic_id' => $data['paramedic_id'],
            'ambulance_id' => $data['ambulance_id'],
            'accepted_at'  => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Booking accepted and paramedic assigned.',
            'data'    => $booking->fresh(['paramedic', 'ambulance']),
        ]);
    }

    public function decline(Request $request, $id)
    {
        $booking = Booking::where('hospital_id', $request->user()->hospital_id)
            ->where('status', 'pending')
            ->findOrFail($id);

        $booking->update([
            'status'              => 'cancelled',
            'cancelled_at'        => now(),
            'cancellation_reason' => $request->input('reason', 'Declined by hospital'),
        ]);

        return response()->json(['success' => true, 'message' => 'Booking declined.']);
    }

    public function complete(Request $request, $id)
    {
        $booking = Booking::where('hospital_id', $request->user()->hospital_id)
            ->whereIn('status', ['en_route', 'arrived', 'assigned'])
            ->findOrFail($id);

        $booking->update([
            'status'       => 'completed',
            'completed_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Booking marked as completed.']);
    }
}