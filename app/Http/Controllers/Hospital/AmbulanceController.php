<?php
// app/Http/Controllers/Hospital/AmbulanceController.php
namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\Ambulance;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AmbulanceController extends Controller
{
    private const TYPES    = ['standard', 'advanced', 'neonatal', 'wheelchair'];
    // on_trip is set by the booking flow, not by hand.
    private const STATUSES = ['available', 'maintenance', 'off_duty'];

    public function index(Request $request)
    {
        $ambulances = Ambulance::where('hospital_id', $request->user()->hospital_id)
            ->with('activeBooking.paramedic:id,first_name,last_name')
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

        return response()->json(['success' => true, 'data' => $ambulances]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'plate_number'    => 'required|string|max:20|unique:ambulances,plate_number',
            'make'            => 'nullable|string|max:50',
            'model'           => 'nullable|string|max:50',
            'year'            => 'nullable|integer|min:1980|max:' . (date('Y') + 1),
            'type'            => ['required', Rule::in(self::TYPES)],
            'status'          => ['sometimes', Rule::in(self::STATUSES)],
            'fuel_level'      => 'sometimes|integer|min:0|max:100',
            'last_service_at' => 'nullable|date',
            'mileage_km'      => 'nullable|integer|min:0',
        ]);

        $ambulance = Ambulance::create([
            ...$data,
            'flagged_for_maintenance' => ($data['status'] ?? null) === 'maintenance',
            'hospital_id'             => $request->user()->hospital_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ambulance added.',
            'data'    => $ambulance,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $ambulance = Ambulance::where('hospital_id', $request->user()->hospital_id)
            ->findOrFail($id);

        $data = $request->validate([
            'plate_number'    => ['sometimes', 'string', 'max:20', Rule::unique('ambulances', 'plate_number')->ignore($ambulance->id)],
            'make'            => 'nullable|string|max:50',
            'model'           => 'nullable|string|max:50',
            'year'            => 'nullable|integer|min:1980|max:' . (date('Y') + 1),
            'type'            => ['sometimes', Rule::in(self::TYPES)],
            'status'          => ['sometimes', Rule::in(self::STATUSES)],
            'fuel_level'      => 'sometimes|integer|min:0|max:100',
            'last_service_at' => 'nullable|date',
            'mileage_km'      => 'nullable|integer|min:0',
        ]);

        if (isset($data['status'])) {
            if ($ambulance->status === 'on_trip') {
                return response()->json([
                    'success' => false,
                    'message' => 'This ambulance is on a trip. Complete the booking first.',
                ], 422);
            }
            // Keep the legacy flag in sync with the status the portal uses.
            $data['flagged_for_maintenance'] = $data['status'] === 'maintenance';
        }

        $ambulance->update($data);

        return response()->json(['success' => true, 'message' => 'Ambulance updated.', 'data' => $ambulance]);
    }

    public function destroy(Request $request, $id)
    {
        $ambulance = Ambulance::where('hospital_id', $request->user()->hospital_id)
            ->findOrFail($id);

        if ($ambulance->status === 'on_trip') {
            return response()->json([
                'success' => false,
                'message' => 'This ambulance is on a trip and cannot be removed right now.',
            ], 422);
        }

        $ambulance->delete();
        return response()->json(['success' => true, 'message' => 'Ambulance removed.']);
    }
}
