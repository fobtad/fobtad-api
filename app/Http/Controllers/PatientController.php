<?php
// app/Http/Controllers/PatientController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PatientController extends Controller
{
    public function show(Request $request)
    {
        return response()->json([
            'success' => true,
            'data'    => $request->user(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'first_name'                    => 'sometimes|string|max:100',
            'last_name'                     => 'sometimes|string|max:100',
            'phone'                         => 'sometimes|string|unique:patients,phone,' . $request->user()->id,
            'address'                       => 'sometimes|string',
            'lga'                           => 'sometimes|string',
            'state'                         => 'sometimes|string',
            'blood_group'                   => 'sometimes|string',
            'genotype'                      => 'sometimes|string',
            'allergies'                     => 'sometimes|string',
            'existing_conditions'           => 'sometimes|string',
            'emergency_contact_name'        => 'sometimes|string',
            'emergency_contact_phone'       => 'sometimes|string',
            'emergency_contact_relationship'=> 'sometimes|string',
        ]);

        $request->user()->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data'    => $request->user()->fresh(),
        ]);
    }
}