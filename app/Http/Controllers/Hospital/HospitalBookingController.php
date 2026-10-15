<?php
// app/Http/Controllers/Hospital/HospitalBookingController.php
namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\Ambulance;
use App\Models\Booking;
use App\Models\Paramedic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Booking lifecycle for hospitals:
 *   pending → assigned (crew + ambulance picked) → en_route → arrived → completed
 * or pending → cancelled (declined).
 * Paramedic and ambulance are marked on_trip while assigned and freed on completion.
 */
class HospitalBookingController extends Controller
{
    private const RELATIONS = ['patient', 'paramedic', 'ambulance', 'trip'];

    public function index(Request $request)
    {
        $statuses = $request->filled('status') ? explode(',', $request->status) : null;

        $bookings = $this->query($request)
            ->with(self::RELATIONS)
            ->when($statuses, fn($q) => $q->whereIn('status', $statuses))
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('reference', 'like', "%{$search}%")
                        ->orWhere('pickup_address', 'like', "%{$search}%")
                        ->orWhereHas('patient', fn($p) => $p
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%"));
                });
            })
            ->latest()
            ->paginate(min((int) $request->input('per_page', 15), 100));

        return response()->json(['success' => true, 'data' => $bookings]);
    }

    /** Counts for the summary cards and tab badges. */
    public function summary(Request $request)
    {
        $counts = $this->query($request)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'success' => true,
            'data'    => [
                'today'     => $this->query($request)->whereDate('created_at', today())->count(),
                'pending'   => (int) ($counts['pending'] ?? 0),
                'active'    => collect(Booking::ACTIVE_STATUSES)->sum(fn($s) => (int) ($counts[$s] ?? 0)),
                'completed' => (int) ($counts['completed'] ?? 0),
                'cancelled' => (int) ($counts['cancelled'] ?? 0),
                'by_status' => $counts,
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $booking = $this->query($request)->with(self::RELATIONS)->findOrFail($id);

        return response()->json(['success' => true, 'data' => $booking]);
    }

    public function accept(Request $request, $id)
    {
        $hospitalId = $request->user()->hospital_id;

        $data = $request->validate([
            'paramedic_id' => 'required|integer',
            'ambulance_id' => 'required|integer',
        ]);

        $booking = DB::transaction(function () use ($request, $id, $hospitalId, $data) {
            $booking = $this->query($request)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->findOrFail($id);

            $paramedic = Paramedic::where('hospital_id', $hospitalId)
                ->lockForUpdate()
                ->find($data['paramedic_id']);

            $ambulance = Ambulance::where('hospital_id', $hospitalId)
                ->lockForUpdate()
                ->find($data['ambulance_id']);

            if (!$paramedic || !in_array($paramedic->status, ['available', 'standby'])) {
                throw ValidationException::withMessages([
                    'paramedic_id' => 'This paramedic is not available.',
                ]);
            }

            if (!$ambulance || $ambulance->status !== 'available' || $ambulance->flagged_for_maintenance) {
                throw ValidationException::withMessages([
                    'ambulance_id' => 'This ambulance is not available.',
                ]);
            }

            $booking->update([
                'status'       => 'assigned',
                'paramedic_id' => $paramedic->id,
                'ambulance_id' => $ambulance->id,
                'accepted_at'  => now(),
            ]);

            $paramedic->update(['status' => 'on_trip']);
            $ambulance->update(['status' => 'on_trip']);

            $booking->trip()->updateOrCreate([], [
                'status'        => 'dispatched',
                'dispatched_at' => now(),
            ]);

            return $booking;
        });

        return response()->json([
            'success' => true,
            'message' => 'Crew assigned and dispatched.',
            'data'    => $booking->fresh(self::RELATIONS),
        ]);
    }

    public function decline(Request $request, $id)
    {
        $booking = $this->query($request)
            ->where('status', 'pending')
            ->findOrFail($id);

        $booking->update([
            'status'              => 'cancelled',
            'cancelled_at'        => now(),
            'cancellation_reason' => $request->input('reason', 'Declined by hospital'),
        ]);

        return response()->json(['success' => true, 'message' => 'Booking declined.']);
    }

    /** Move an assigned booking along: assigned → en_route → arrived. */
    public function updateStatus(Request $request, $id)
    {
        $data = $request->validate([
            'status' => 'required|in:en_route,arrived',
        ]);

        $from = $data['status'] === 'en_route' ? ['accepted', 'assigned'] : ['en_route'];

        $booking = $this->query($request)
            ->whereIn('status', $from)
            ->findOrFail($id);

        $booking->update(['status' => $data['status']]);

        $booking->trip()->updateOrCreate([], array_filter([
            'status'     => $data['status'],
            'arrived_at' => $data['status'] === 'arrived' ? now() : null,
        ]));

        return response()->json([
            'success' => true,
            'message' => $data['status'] === 'arrived' ? 'Crew has arrived.' : 'Crew is en route.',
            'data'    => $booking->fresh(self::RELATIONS),
        ]);
    }

    public function complete(Request $request, $id)
    {
        $booking = DB::transaction(function () use ($request, $id) {
            $booking = $this->query($request)
                ->whereIn('status', Booking::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->findOrFail($id);

            $booking->update([
                'status'       => 'completed',
                'completed_at' => now(),
            ]);

            $booking->releaseCrew(countTrip: true);

            $trip = $booking->trip;
            if ($trip) {
                $trip->update([
                    'status'           => 'completed',
                    'completed_at'     => now(),
                    'duration_minutes' => $trip->dispatched_at?->diffInMinutes(now()),
                ]);
            }

            return $booking;
        });

        // Charge the patient's wallet now; anything left is paid by card in the app.
        app(\App\Services\PaymentService::class)->settle($booking);

        return response()->json([
            'success' => true,
            'message' => 'Booking marked as completed.',
            'data'    => $booking->fresh(self::RELATIONS),
        ]);
    }

    private function query(Request $request)
    {
        return Booking::where('hospital_id', $request->user()->hospital_id);
    }
}
