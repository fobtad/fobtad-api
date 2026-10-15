<?php
// app/Http/Controllers/Provider/TowTruckController.php
namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\TowTruck;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Provider vehicles (tow trucks for now; other vehicle types come with rides). */
class TowTruckController extends Controller
{
    private const TYPES    = ['flatbed', 'wheel_lift', 'heavy_recovery'];
    // on_job is set by the job flow, not by hand.
    private const STATUSES = ['available', 'maintenance', 'off_duty'];

    public function index(Request $request)
    {
        $trucks = TowTruck::where('provider_id', $request->user()->provider_id)
            ->with('activeBooking.driver:id,first_name,last_name')
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->type, fn($q, $type) => $q->where('type', $type))
            ->when($request->search, function ($q, $search) {
                $q->where(fn($q) => $q
                    ->where('plate_number', 'like', "%{$search}%")
                    ->orWhere('make', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%"));
            })
            ->latest()
            ->paginate(min((int) $request->input('per_page', 20), 100));

        return response()->json(['success' => true, 'data' => $trucks]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'plate_number'    => 'required|string|max:20|unique:tow_trucks,plate_number',
            'make'            => 'nullable|string|max:50',
            'model'           => 'nullable|string|max:50',
            'year'            => 'nullable|integer|min:1980|max:' . (date('Y') + 1),
            'type'            => ['required', Rule::in(self::TYPES)],
            'capacity_tonnes' => 'nullable|numeric|min:0|max:100',
            'status'          => ['sometimes', Rule::in(self::STATUSES)],
            'fuel_level'      => 'sometimes|integer|min:0|max:100',
            'last_service_at' => 'nullable|date',
            'mileage_km'      => 'nullable|integer|min:0',
        ]);

        $truck = TowTruck::create([
            ...$data,
            'flagged_for_maintenance' => ($data['status'] ?? null) === 'maintenance',
            'provider_id'             => $request->user()->provider_id,
        ]);

        return response()->json(['success' => true, 'message' => 'Vehicle added.', 'data' => $truck], 201);
    }

    public function update(Request $request, $id)
    {
        $truck = TowTruck::where('provider_id', $request->user()->provider_id)->findOrFail($id);

        $data = $request->validate([
            'plate_number'    => ['sometimes', 'string', 'max:20', Rule::unique('tow_trucks', 'plate_number')->ignore($truck->id)],
            'make'            => 'nullable|string|max:50',
            'model'           => 'nullable|string|max:50',
            'year'            => 'nullable|integer|min:1980|max:' . (date('Y') + 1),
            'type'            => ['sometimes', Rule::in(self::TYPES)],
            'capacity_tonnes' => 'nullable|numeric|min:0|max:100',
            'status'          => ['sometimes', Rule::in(self::STATUSES)],
            'fuel_level'      => 'sometimes|integer|min:0|max:100',
            'last_service_at' => 'nullable|date',
            'mileage_km'      => 'nullable|integer|min:0',
        ]);

        if (isset($data['status'])) {
            if ($truck->status === 'on_job') {
                return response()->json([
                    'success' => false,
                    'message' => 'This vehicle is on a job. Complete the job first.',
                ], 422);
            }
            // Keep the legacy flag in sync with the status the portal uses.
            $data['flagged_for_maintenance'] = $data['status'] === 'maintenance';
        }

        $truck->update($data);

        return response()->json(['success' => true, 'message' => 'Vehicle updated.', 'data' => $truck]);
    }

    public function destroy(Request $request, $id)
    {
        $truck = TowTruck::where('provider_id', $request->user()->provider_id)->findOrFail($id);

        if ($truck->status === 'on_job') {
            return response()->json([
                'success' => false,
                'message' => 'This vehicle is on a job and cannot be removed right now.',
            ], 422);
        }

        $truck->delete();
        return response()->json(['success' => true, 'message' => 'Vehicle removed.']);
    }
}
