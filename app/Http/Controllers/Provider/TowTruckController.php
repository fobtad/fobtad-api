<?php
// app/Http/Controllers/Provider/TowTruckController.php
namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\TowTruck;
use Illuminate\Http\Request;

class TowTruckController extends Controller
{
    public function index(Request $request)
    {
        $trucks = TowTruck::where('provider_id', $request->user()->provider_id)
            ->latest()->paginate(20);
        return response()->json(['success' => true, 'data' => $trucks]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'plate_number' => 'required|string|unique:tow_trucks,plate_number',
            'make'         => 'sometimes|string',
            'model'        => 'sometimes|string',
            'year'         => 'sometimes|integer',
            'type'         => 'required|in:flatbed,wheel_lift,heavy_recovery',
        ]);

        $truck = TowTruck::create([
            ...$data,
            'provider_id' => $request->user()->provider_id,
        ]);

        return response()->json(['success' => true, 'message' => 'Truck added.', 'data' => $truck], 201);
    }

    public function update(Request $request, $id)
    {
        $truck = TowTruck::where('provider_id', $request->user()->provider_id)->findOrFail($id);

        $data = $request->validate([
            'status'                  => 'sometimes|in:available,on_job,maintenance',
            'flagged_for_maintenance' => 'sometimes|boolean',
        ]);

        $truck->update($data);
        return response()->json(['success' => true, 'message' => 'Truck updated.', 'data' => $truck]);
    }

    public function destroy(Request $request, $id)
    {
        $truck = TowTruck::where('provider_id', $request->user()->provider_id)->findOrFail($id);
        $truck->delete();
        return response()->json(['success' => true, 'message' => 'Truck removed.']);
    }
}