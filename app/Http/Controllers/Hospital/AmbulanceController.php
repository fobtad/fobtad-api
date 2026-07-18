<?php
// app/Http/Controllers/Hospital/AmbulanceController.php
namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\Ambulance;
use Illuminate\Http\Request;

class AmbulanceController extends Controller
{
    public function index(Request $request)
    {
        $ambulances = Ambulance::where('hospital_id', $request->user()->hospital_id)
            ->latest()->paginate(20);
        return response()->json(['success' => true, 'data' => $ambulances]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'plate_number' => 'required|string|unique:ambulances,plate_number',
            'make'         => 'sometimes|string',
            'model'        => 'sometimes|string',
            'year'         => 'sometimes|integer',
            'type'         => 'required|in:standard,advanced,neonatal,wheelchair',
        ]);

        $ambulance = Ambulance::create([
            ...$data,
            'hospital_id' => $request->user()->hospital_id,
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
            'status'                  => 'sometimes|in:available,on_trip,maintenance',
            'fuel_level'              => 'sometimes|integer|min:0|max:100',
            'flagged_for_maintenance' => 'sometimes|boolean',
        ]);

        $ambulance->update($data);

        return response()->json(['success' => true, 'message' => 'Ambulance updated.', 'data' => $ambulance]);
    }

    public function destroy(Request $request, $id)
    {
        $ambulance = Ambulance::where('hospital_id', $request->user()->hospital_id)
            ->findOrFail($id);
        $ambulance->delete();
        return response()->json(['success' => true, 'message' => 'Ambulance removed.']);
    }
}