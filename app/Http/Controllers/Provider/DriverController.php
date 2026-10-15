<?php
// app/Http/Controllers/Provider/DriverController.php
namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DriverController extends Controller
{
    private const SHIFTS   = ['morning', 'afternoon', 'night'];
    // on_job is set by the job flow, not by hand.
    private const STATUSES = ['available', 'standby', 'off_duty'];

    public function index(Request $request)
    {
        $drivers = Driver::where('provider_id', $request->user()->provider_id)
            ->with('activeBooking.towTruck:id,plate_number')
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->search, function ($q, $search) {
                $q->where(fn($q) => $q
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%"));
            })
            ->latest()
            ->paginate(min((int) $request->input('per_page', 20), 100));

        return response()->json(['success' => true, 'data' => $drivers]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name'     => 'required|string|max:100',
            'last_name'      => 'required|string|max:100',
            'email'          => 'required|email|unique:drivers,email',
            'phone'          => 'required|string|max:30',
            'licence_number' => 'nullable|string|max:100',
            'shift'          => ['nullable', Rule::in(self::SHIFTS)],
            'status'         => ['sometimes', Rule::in(self::STATUSES)],
        ]);

        $driver = Driver::create([
            ...$data,
            'provider_id' => $request->user()->provider_id,
        ]);

        return response()->json(['success' => true, 'message' => 'Driver added.', 'data' => $driver], 201);
    }

    public function update(Request $request, $id)
    {
        $driver = Driver::where('provider_id', $request->user()->provider_id)->findOrFail($id);

        $data = $request->validate([
            'first_name'     => 'sometimes|string|max:100',
            'last_name'      => 'sometimes|string|max:100',
            'email'          => ['sometimes', 'email', Rule::unique('drivers', 'email')->ignore($driver->id)],
            'phone'          => 'sometimes|string|max:30',
            'licence_number' => 'nullable|string|max:100',
            'shift'          => ['nullable', Rule::in(self::SHIFTS)],
            'status'         => ['sometimes', Rule::in(self::STATUSES)],
        ]);

        if (isset($data['status']) && $driver->status === 'on_job') {
            return response()->json([
                'success' => false,
                'message' => 'This driver is on a job. Complete the job first.',
            ], 422);
        }

        $driver->update($data);

        return response()->json(['success' => true, 'message' => 'Driver updated.', 'data' => $driver]);
    }

    public function destroy(Request $request, $id)
    {
        $driver = Driver::where('provider_id', $request->user()->provider_id)->findOrFail($id);

        if ($driver->status === 'on_job') {
            return response()->json([
                'success' => false,
                'message' => 'This driver is on a job and cannot be removed right now.',
            ], 422);
        }

        $driver->delete();
        return response()->json(['success' => true, 'message' => 'Driver removed.']);
    }
}
