<?php
// app/Http/Controllers/Provider/DriverController.php
namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    public function index(Request $request)
    {
        $drivers = Driver::where('provider_id', $request->user()->provider_id)
            ->latest()->paginate(20);
        return response()->json(['success' => true, 'data' => $drivers]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name'     => 'required|string',
            'last_name'      => 'required|string',
            'email'          => 'required|email|unique:drivers,email',
            'phone'          => 'required|string',
            'licence_number' => 'sometimes|string',
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
            'first_name' => 'sometimes|string',
            'last_name'  => 'sometimes|string',
            'phone'      => 'sometimes|string',
            'status'     => 'sometimes|in:available,on_job,off_duty',
        ]);

        $driver->update($data);
        return response()->json(['success' => true, 'message' => 'Driver updated.', 'data' => $driver]);
    }

    public function destroy(Request $request, $id)
    {
        $driver = Driver::where('provider_id', $request->user()->provider_id)->findOrFail($id);
        $driver->delete();
        return response()->json(['success' => true, 'message' => 'Driver removed.']);
    }
}