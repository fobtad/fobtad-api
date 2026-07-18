<?php
// app/Http/Controllers/Hospital/ParamedicController.php
namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\Paramedic;
use Illuminate\Http\Request;

class ParamedicController extends Controller
{
    public function index(Request $request)
    {
        $paramedics = Paramedic::where('hospital_id', $request->user()->hospital_id)
            ->latest()->paginate(20);
        return response()->json(['success' => true, 'data' => $paramedics]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name'      => 'required|string',
            'last_name'       => 'required|string',
            'email'           => 'required|email|unique:paramedics,email',
            'phone'           => 'required|string',
            'licence_number'  => 'sometimes|string',
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
            'first_name'     => 'sometimes|string',
            'last_name'      => 'sometimes|string',
            'phone'          => 'sometimes|string',
            'licence_number' => 'sometimes|string',
            'status'         => 'sometimes|in:available,on_trip,off_duty',
        ]);

        $paramedic->update($data);

        return response()->json(['success' => true, 'message' => 'Paramedic updated.', 'data' => $paramedic]);
    }

    public function destroy(Request $request, $id)
    {
        $paramedic = Paramedic::where('hospital_id', $request->user()->hospital_id)
            ->findOrFail($id);
        $paramedic->delete();
        return response()->json(['success' => true, 'message' => 'Paramedic removed.']);
    }
}