<?php
// app/Http/Controllers/BookingController.php
namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Hospital;
use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'data' => $bookings,
        ]);
    }

    public function show(Request $request, $id)
    {
        $booking = Booking::where('patient_id', $request->user()->id)
            ->with(['hospital', 'provider', 'paramedic', 'ambulance', 'driver', 'towTruck', 'trip', 'payment'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $booking,
        ]);
    }

    public function estimate(Request $request): JsonResponse
    {
        $request->validate([
            'pickup_lat' => 'required|numeric',
            'pickup_lng' => 'required|numeric',
            'destination_lat' => 'required|numeric',
            'destination_lng' => 'required|numeric',
            'ride_type' => 'required|in:standard,advanced,wheelchair',
        ]);

        $distanceKm = $this->haversine(
            $request->pickup_lat,
            $request->pickup_lng,
            $request->destination_lat,
            $request->destination_lng,
        );

        $rates = [
            'standard' => ['base' => 3000, 'per_km' => 250, 'floor' => 8000],
            'advanced' => ['base' => 5000, 'per_km' => 450, 'floor' => 15000],
            'wheelchair' => ['base' => 4000, 'per_km' => 320, 'floor' => 10000],
        ];

        $rate = $rates[$request->ride_type];
        $rawCost = $rate['base'] + ($distanceKm * $rate['per_km']);

        $minPrice = (int) round(max($rate['floor'], $rawCost * 0.9));
        $maxPrice = (int) round(max($rate['floor'], $rawCost * 1.1));

        return response()->json([
            'success' => true,
            'distance_km' => round($distanceKm, 2),
            'ride_type' => $request->ride_type,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'currency' => 'NGN',
            'formatted' => '₦' . number_format($minPrice) . ' – ₦' . number_format($maxPrice),
        ]);
    }

    public function emergency(Request $request)
    {
        $data = $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'incident_type' => 'required|string',
            'address' => 'sometimes|string',
        ]);

        $hospital = Hospital::where('status', 'active')->first();

        $booking = Booking::create([
            'patient_id' => $request->user()->id,
            'hospital_id' => $hospital?->id,
            'type' => 'emergency',
            'status' => 'pending',
            'pickup_address' => $data['address'] ?? 'Lagos, Nigeria',
            'pickup_latitude' => $data['latitude'],
            'pickup_longitude' => $data['longitude'],
            'incident_type' => $data['incident_type'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Emergency dispatched! Help is on the way.',
            'data' => [
                'booking_id' => $booking->id,
                'reference' => $booking->reference,
                'status' => $booking->status,
                'eta' => '8 minutes',
            ],
        ], 201);
    }

    public function scheduled(Request $request)
    {
        $data = $request->validate([
            'pickup_address' => 'required|string',
            'pickup_lat' => 'required|numeric',
            'pickup_lng' => 'required|numeric',
            'destination_address' => 'required|string',
            'destination_lat' => 'required|numeric',
            'destination_lng' => 'required|numeric',
            'scheduled_at' => 'required|date|after:now',
            'ride_type' => 'required|in:standard,advanced,wheelchair',
            'estimated_min_price' => 'sometimes|integer',
            'estimated_max_price' => 'sometimes|integer',
            'notes' => 'sometimes|string',
        ]);

        $hospital = Hospital::where('status', 'active')->first();

        $booking = Booking::create([
            'patient_id' => $request->user()->id,
            'hospital_id' => $hospital?->id,
            'type' => 'scheduled',
            'status' => 'pending',
            'pickup_address' => $data['pickup_address'],
            'pickup_latitude' => $data['pickup_lat'],
            'pickup_longitude' => $data['pickup_lng'],
            'destination_address' => $data['destination_address'],
            'destination_latitude' => $data['destination_lat'],
            'destination_longitude' => $data['destination_lng'],
            'scheduled_at' => $data['scheduled_at'],
            'ride_type' => $data['ride_type'],
            'estimated_min_price' => $data['estimated_min_price'] ?? null,
            'estimated_max_price' => $data['estimated_max_price'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Booking confirmed!',
            'data' => [
                'booking_id' => $booking->id,
                'reference' => $booking->reference,
                'status' => $booking->status,
            ],
        ], 201);
    }

    public function maternal(Request $request)
    {
        $data = $request->validate([
            'pickup_address' => 'required|string',
            'destination_address' => 'required|string',
            'scheduled_at' => 'required|date|after:now',
            'service_type' => 'required|string',
            'weeks_pregnant' => 'required|integer|min:1|max:45',
            'is_high_risk' => 'sometimes|boolean',
            'needs_wheelchair' => 'sometimes|boolean',
            'notes' => 'sometimes|string',
        ]);

        $hospital = Hospital::where('status', 'active')->first();

        $booking = Booking::create([
            'patient_id' => $request->user()->id,
            'hospital_id' => $hospital?->id,
            'type' => 'maternal',
            'status' => 'pending',
            'pickup_address' => $data['pickup_address'],
            'destination_address' => $data['destination_address'],
            'scheduled_at' => $data['scheduled_at'],
            'incident_type' => $data['service_type'],
            'weeks_pregnant' => $data['weeks_pregnant'],
            'is_high_risk' => $data['is_high_risk'] ?? false,
            'needs_wheelchair' => $data['needs_wheelchair'] ?? false,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Maternal care booking confirmed!',
            'data' => [
                'booking_id' => $booking->id,
                'reference' => $booking->reference,
                'status' => $booking->status,
            ],
        ], 201);
    }

    public function towing(Request $request)
    {
        $data = $request->validate([
            'pickup_address' => 'required|string',
            'destination_address' => 'required|string',
            'issue_type' => 'required|string',
            'truck_type' => 'required|in:flatbed,wheel_lift,heavy_recovery',
            'vehicle_make' => 'required|string',
            'vehicle_plate' => 'required|string',
            'notes' => 'sometimes|string',
        ]);

        $provider = Provider::where('status', 'active')->first();

        $booking = Booking::create([
            'patient_id' => $request->user()->id,
            'provider_id' => $provider?->id,
            'type' => 'towing',
            'status' => 'pending',
            'pickup_address' => $data['pickup_address'],
            'destination_address' => $data['destination_address'],
            'incident_type' => $data['issue_type'],
            'ride_type' => $data['truck_type'],
            'notes' => ($data['notes'] ?? '') . ' Vehicle: ' . $data['vehicle_make'] . ' ' . $data['vehicle_plate'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tow truck dispatched!',
            'data' => [
                'booking_id' => $booking->id,
                'reference' => $booking->reference,
                'status' => $booking->status,
                'eta' => '18 minutes',
            ],
        ], 201);
    }

    public function cancel(Request $request, $id)
    {
        $booking = Booking::where('patient_id', $request->user()->id)
            ->whereIn('status', ['pending', 'accepted'])
            ->findOrFail($id);

        $booking->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
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
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'sometimes|string|max:500',
        ]);

        $booking = Booking::where('patient_id', $request->user()->id)
            ->where('status', 'completed')
            ->findOrFail($id);

        $booking->update([
            'rating' => $data['rating'],
            'rating_comment' => $data['comment'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Rating submitted. Thank you!',
        ]);
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    // In BookingController
    public function location(Request $request, string $reference): JsonResponse
    {
        $booking = Booking::where('reference', $reference)
            ->where('patient_id', $request->user()->id)
            ->firstOrFail();

        // TODO: when drivers have a live location table, pull from there.
        // For now return the office coordinates as the vehicle's starting position.
        return response()->json([
            'success' => true,
            'data' => [
                'reference' => $booking->reference,
                'status' => $booking->status,
                'vehicle_lat' => 6.426405506305938,
                'vehicle_lng' => 3.4927516958304357,
                'destination_lat' => $booking->pickup_latitude,
                'destination_lng' => $booking->pickup_longitude,
                'eta_minutes' => 8,
            ],
        ]);
    }
}