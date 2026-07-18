<?php
// app/Http/Controllers/BookingController.php
namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Hospital;
use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        $bookings = Booking::where('patient_id', $request->user()->id)
            ->with(['hospital', 'provider', 'paramedic', 'ambulance', 'driver', 'towTruck', 'payment'])
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $bookings,
        ]);
    }

    public function show(Request $request, $id)
    {
        $booking = Booking::where('patient_id', $request->user()->id)
            ->with(['hospital', 'provider', 'paramedic', 'ambulance', 'driver', 'towTruck', 'trip', 'payment'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $booking,
        ]);
    }

    public function emergency(Request $request)
    {
        $data = $request->validate([
            'latitude'      => 'required|numeric',
            'longitude'     => 'required|numeric',
            'incident_type' => 'required|string',
            'address'       => 'sometimes|string',
        ]);

        // Find nearest available hospital
        $hospital = Hospital::where('status', 'active')->first();

        $booking = Booking::create([
            'patient_id'        => $request->user()->id,
            'hospital_id'       => $hospital?->id,
            'type'              => 'emergency',
            'status'            => 'pending',
            'pickup_address'    => $data['address'] ?? 'Lagos, Nigeria',
            'pickup_latitude'   => $data['latitude'],
            'pickup_longitude'  => $data['longitude'],
            'incident_type'     => $data['incident_type'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Emergency dispatched! Help is on the way.',
            'data'    => [
                'booking_id' => $booking->id,
                'reference'  => $booking->reference,
                'status'     => $booking->status,
                'eta'        => '8 minutes',
            ],
        ], 201);
    }

    public function scheduled(Request $request)
    {
        $data = $request->validate([
            'pickup_address'      => 'required|string',
            'destination_address' => 'required|string',
            'scheduled_at'        => 'required|date|after:now',
            'ride_type'           => 'required|in:standard,advanced,wheelchair',
            'notes'               => 'sometimes|string',
        ]);

        $hospital = Hospital::where('status', 'active')->first();

        $booking = Booking::create([
            'patient_id'          => $request->user()->id,
            'hospital_id'         => $hospital?->id,
            'type'                => 'scheduled',
            'status'              => 'pending',
            'pickup_address'      => $data['pickup_address'],
            'destination_address' => $data['destination_address'],
            'scheduled_at'        => $data['scheduled_at'],
            'ride_type'           => $data['ride_type'],
            'notes'               => $data['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Booking confirmed!',
            'data'    => [
                'booking_id' => $booking->id,
                'reference'  => $booking->reference,
                'status'     => $booking->status,
            ],
        ], 201);
    }

    public function maternal(Request $request)
    {
        $data = $request->validate([
            'pickup_address'      => 'required|string',
            'destination_address' => 'required|string',
            'scheduled_at'        => 'required|date|after:now',
            'service_type'        => 'required|string',
            'weeks_pregnant'      => 'required|integer|min:1|max:45',
            'is_high_risk'        => 'sometimes|boolean',
            'needs_wheelchair'    => 'sometimes|boolean',
            'notes'               => 'sometimes|string',
        ]);

        $hospital = Hospital::where('status', 'active')->first();

        $booking = Booking::create([
            'patient_id'          => $request->user()->id,
            'hospital_id'         => $hospital?->id,
            'type'                => 'maternal',
            'status'              => 'pending',
            'pickup_address'      => $data['pickup_address'],
            'destination_address' => $data['destination_address'],
            'scheduled_at'        => $data['scheduled_at'],
            'incident_type'       => $data['service_type'],
            'weeks_pregnant'      => $data['weeks_pregnant'],
            'is_high_risk'        => $data['is_high_risk'] ?? false,
            'needs_wheelchair'    => $data['needs_wheelchair'] ?? false,
            'notes'               => $data['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Maternal care booking confirmed!',
            'data'    => [
                'booking_id' => $booking->id,
                'reference'  => $booking->reference,
                'status'     => $booking->status,
            ],
        ], 201);
    }

    public function towing(Request $request)
    {
        $data = $request->validate([
            'pickup_address'      => 'required|string',
            'destination_address' => 'required|string',
            'issue_type'          => 'required|string',
            'truck_type'          => 'required|in:flatbed,wheel_lift,heavy_recovery',
            'vehicle_make'        => 'required|string',
            'vehicle_plate'       => 'required|string',
            'notes'               => 'sometimes|string',
        ]);

        $provider = Provider::where('status', 'active')->first();

        $booking = Booking::create([
            'patient_id'          => $request->user()->id,
            'provider_id'         => $provider?->id,
            'type'                => 'towing',
            'status'              => 'pending',
            'pickup_address'      => $data['pickup_address'],
            'destination_address' => $data['destination_address'],
            'incident_type'       => $data['issue_type'],
            'ride_type'           => $data['truck_type'],
            'notes'               => ($data['notes'] ?? '') . ' Vehicle: ' . $data['vehicle_make'] . ' ' . $data['vehicle_plate'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tow truck dispatched!',
            'data'    => [
                'booking_id' => $booking->id,
                'reference'  => $booking->reference,
                'status'     => $booking->status,
                'eta'        => '18 minutes',
            ],
        ], 201);
    }

    public function cancel(Request $request, $id)
    {
        $booking = Booking::where('patient_id', $request->user()->id)
            ->whereIn('status', ['pending', 'accepted'])
            ->findOrFail($id);

        $booking->update([
            'status'              => 'cancelled',
            'cancelled_at'        => now(),
            'cancellation_reason' => $request->input('reason', 'Cancelled by patient'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Booking cancelled.',
        ]);
    }

    public function rate(Request $request, $id)
    {
        $data = $request->validate([
            'rating'  => 'required|integer|min:1|max:5',
            'comment' => 'sometimes|string|max:500',
        ]);

        $booking = Booking::where('patient_id', $request->user()->id)
            ->where('status', 'completed')
            ->findOrFail($id);

        $booking->update([
            'rating'         => $data['rating'],
            'rating_comment' => $data['comment'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Rating submitted. Thank you!',
        ]);
    }
}