<?php
// app/Http/Controllers/TripController.php
namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Http\Request;

class TripController extends Controller
{
    public function track(Request $request, $bookingId)
    {
        $booking = Booking::where('patient_id', $request->user()->id)
            ->with(['trip', 'paramedic', 'ambulance', 'driver', 'towTruck'])
            ->findOrFail($bookingId);

        // Mock tracking data until real GPS is integrated
        $trackingData = [
            'booking_reference' => $booking->reference,
            'status'            => $booking->status,
            'current_location'  => [
                'latitude'  => 6.5244,
                'longitude' => 3.3792,
            ],
            'eta_minutes'       => 8,
            'progress'          => 0.35,
        ];

        if ($booking->paramedic) {
            $trackingData['paramedic'] = [
                'name'      => $booking->paramedic->full_name,
                'phone'     => $booking->paramedic->phone,
                'rating'    => $booking->paramedic->rating,
                'photo'     => $booking->paramedic->photo,
            ];
        }

        if ($booking->ambulance) {
            $trackingData['vehicle'] = [
                'plate_number' => $booking->ambulance->plate_number,
                'type'         => $booking->ambulance->type,
            ];
        }

        if ($booking->driver) {
            $trackingData['driver'] = [
                'name'   => $booking->driver->full_name,
                'phone'  => $booking->driver->phone,
                'rating' => $booking->driver->rating,
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => $trackingData,
        ]);
    }
}