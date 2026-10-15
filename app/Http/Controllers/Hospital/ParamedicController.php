<?php
// app/Http/Controllers/Hospital/ParamedicController.php
namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\Paramedic;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ParamedicController extends Controller
{
    private const ROLES    = ['senior_paramedic', 'paramedic', 'emergency_technician'];
    private const SHIFTS   = ['morning', 'afternoon', 'night'];
    // on_trip is set by the booking flow, not by hand.
    private const STATUSES = ['available', 'standby', 'off_duty'];

    public function index(Request $request)
    {
        $paramedics = Paramedic::where('hospital_id', $request->user()->hospital_id)
            ->with('activeBooking.ambulance:id,plate_number')
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->search, function ($q, $search) {
                $q->where(fn($q) => $q
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('role', 'like', "%{$search}%"));
            })
            ->latest()
            ->paginate(min((int) $request->input('per_page', 20), 100));

        return response()->json(['success' => true, 'data' => $paramedics]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name'     => 'required|string|max:100',
            'last_name'      => 'required|string|max:100',
            'email'          => 'required|email|unique:paramedics,email',
            'phone'          => 'required|string|max:30',
            'role'           => ['sometimes', Rule::in(self::ROLES)],
            'shift'          => ['nullable', Rule::in(self::SHIFTS)],
            'status'         => ['sometimes', Rule::in(self::STATUSES)],
            'licence_number' => 'nullable|string|max:100',
        ]);

        $paramedic = Paramedic::create([
            ...$data,
            'hospital_id' => $request->user()->hospital_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Paramedic added.',
            'data'    => $paramedic,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $paramedic = Paramedic::where('hospital_id', $request->user()->hospital_id)
            ->findOrFail($id);

        $data = $request->validate([
            'first_name'     => 'sometimes|string|max:100',
            'last_name'      => 'sometimes|string|max:100',
            'email'          => ['sometimes', 'email', Rule::unique('paramedics', 'email')->ignore($paramedic->id)],
            'phone'          => 'sometimes|string|max:30',
            'role'           => ['sometimes', Rule::in(self::ROLES)],
            'shift'          => ['nullable', Rule::in(self::SHIFTS)],
            'status'         => ['sometimes', Rule::in(self::STATUSES)],
            'licence_number' => 'nullable|string|max:100',
        ]);

        if (isset($data['status']) && $paramedic->status === 'on_trip') {
            return response()->json([
                'success' => false,
                'message' => 'This paramedic is on a trip. Complete the booking first.',
            ], 422);
        }

        $paramedic->update($data);

        return response()->json(['success' => true, 'message' => 'Paramedic updated.', 'data' => $paramedic]);
    }

    public function destroy(Request $request, $id)
    {
        $paramedic = Paramedic::where('hospital_id', $request->user()->hospital_id)
            ->findOrFail($id);

        if ($paramedic->status === 'on_trip') {
            return response()->json([
                'success' => false,
                'message' => 'This paramedic is on a trip and cannot be removed right now.',
            ], 422);
        }

        $paramedic->delete();
        return response()->json(['success' => true, 'message' => 'Paramedic removed.']);
    }
}
