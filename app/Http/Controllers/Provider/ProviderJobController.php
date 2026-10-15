<?php
// app/Http/Controllers/Provider/ProviderJobController.php
namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Driver;
use App\Models\TowTruck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Job lifecycle for providers:
 *   pending → assigned (driver + vehicle picked) → en_route → arrived → completed
 * or pending → cancelled (declined).
 * Driver and vehicle are marked on_job while assigned and freed on completion.
 */
class ProviderJobController extends Controller
{
    private const RELATIONS = ['patient', 'driver', 'towTruck', 'trip'];

    public function index(Request $request)
    {
        $statuses = $request->filled('status') ? explode(',', $request->status) : null;

        $jobs = $this->query($request)
            ->with(self::RELATIONS)
            ->when($statuses, fn($q) => $q->whereIn('status', $statuses))
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('reference', 'like', "%{$search}%")
                        ->orWhere('pickup_address', 'like', "%{$search}%")
                        ->orWhere('incident_type', 'like', "%{$search}%")
                        ->orWhereHas('patient', fn($p) => $p
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%"));
                });
            })
            ->latest()
            ->paginate(min((int) $request->input('per_page', 15), 100));

        return response()->json(['success' => true, 'data' => $jobs]);
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
        return response()->json([
            'success' => true,
            'data'    => $this->query($request)->with(self::RELATIONS)->findOrFail($id),
        ]);
    }

    public function accept(Request $request, $id)
    {
        $providerId = $request->user()->provider_id;

        $data = $request->validate([
            'driver_id'    => 'required|integer',
            'tow_truck_id' => 'required|integer',
        ]);

        $job = DB::transaction(function () use ($request, $id, $providerId, $data) {
            $job = $this->query($request)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->findOrFail($id);

            $driver = Driver::where('provider_id', $providerId)->lockForUpdate()->find($data['driver_id']);
            $truck  = TowTruck::where('provider_id', $providerId)->lockForUpdate()->find($data['tow_truck_id']);

            if (!$driver || !in_array($driver->status, ['available', 'standby'])) {
                throw ValidationException::withMessages(['driver_id' => 'This driver is not available.']);
            }

            if (!$truck || $truck->status !== 'available' || $truck->flagged_for_maintenance) {
                throw ValidationException::withMessages(['tow_truck_id' => 'This vehicle is not available.']);
            }

            $job->update([
                'status'       => 'assigned',
                'driver_id'    => $driver->id,
                'tow_truck_id' => $truck->id,
                'accepted_at'  => now(),
            ]);

            $driver->update(['status' => 'on_job']);
            $truck->update(['status' => 'on_job']);

            $job->trip()->updateOrCreate([], [
                'status'        => 'dispatched',
                'dispatched_at' => now(),
            ]);

            return $job;
        });

        return response()->json([
            'success' => true,
            'message' => 'Driver assigned and dispatched.',
            'data'    => $job->fresh(self::RELATIONS),
        ]);
    }

    public function decline(Request $request, $id)
    {
        $job = $this->query($request)
            ->where('status', 'pending')
            ->findOrFail($id);

        $job->update([
            'status'              => 'cancelled',
            'cancelled_at'        => now(),
            'cancellation_reason' => $request->input('reason', 'Declined by provider'),
        ]);

        return response()->json(['success' => true, 'message' => 'Job declined.']);
    }

    /** Move an assigned job along: assigned → en_route → arrived. */
    public function updateStatus(Request $request, $id)
    {
        $data = $request->validate([
            'status' => 'required|in:en_route,arrived',
        ]);

        $from = $data['status'] === 'en_route' ? ['accepted', 'assigned'] : ['en_route'];

        $job = $this->query($request)
            ->whereIn('status', $from)
            ->findOrFail($id);

        $job->update(['status' => $data['status']]);

        $job->trip()->updateOrCreate([], array_filter([
            'status'     => $data['status'],
            'arrived_at' => $data['status'] === 'arrived' ? now() : null,
        ]));

        return response()->json([
            'success' => true,
            'message' => $data['status'] === 'arrived' ? 'Driver has arrived.' : 'Driver is en route.',
            'data'    => $job->fresh(self::RELATIONS),
        ]);
    }

    public function complete(Request $request, $id)
    {
        $job = DB::transaction(function () use ($request, $id) {
            $job = $this->query($request)
                ->whereIn('status', Booking::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->findOrFail($id);

            $job->update([
                'status'       => 'completed',
                'completed_at' => now(),
            ]);

            $job->releaseCrew(countTrip: true);

            $trip = $job->trip;
            if ($trip) {
                $trip->update([
                    'status'           => 'completed',
                    'completed_at'     => now(),
                    'duration_minutes' => $trip->dispatched_at?->diffInMinutes(now()),
                ]);
            }

            return $job;
        });

        return response()->json([
            'success' => true,
            'message' => 'Job marked as completed.',
            'data'    => $job->fresh(self::RELATIONS),
        ]);
    }

    private function query(Request $request)
    {
        return Booking::where('provider_id', $request->user()->provider_id);
    }
}
