<?php
// app/Http/Controllers/Hospital/HospitalSettingsController.php
namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class HospitalSettingsController extends Controller
{
    public function profile(Request $request)
    {
        return response()->json(['success' => true, 'data' => $request->user()->hospital]);
    }

    /** Hospital profile. Only hospital admins can change it; status stays with FOBTAD admin. */
    public function updateProfile(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Only hospital administrators can edit the hospital profile.',
            ], 403);
        }

        $hospital = $request->user()->hospital;

        $data = $request->validate([
            'name'           => 'sometimes|string|max:150',
            'facility_type'  => ['nullable', Rule::in(['government', 'private', 'clinic', 'teaching'])],
            'email'          => ['sometimes', 'email', Rule::unique('hospitals', 'email')->ignore($hospital->id)],
            'phone'          => 'sometimes|string|max:30',
            'website'        => 'nullable|string|max:150',
            'address'        => 'sometimes|string|max:255',
            'lga'            => 'nullable|string|max:100',
            'latitude'       => 'nullable|numeric|between:-90,90',
            'longitude'      => 'nullable|numeric|between:-180,180',
            'state'          => 'sometimes|string|max:100',
            'licence_number' => 'nullable|string|max:100',
            'cac_number'     => 'nullable|string|max:100',
            'description'    => 'nullable|string|max:2000',
        ]);

        $hospital->update($data);

        return response()->json(['success' => true, 'message' => 'Hospital profile updated.', 'data' => $hospital]);
    }

    public function updateAccount(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'first_name' => 'sometimes|string|max:100',
            'last_name'  => 'sometimes|string|max:100',
            'email'      => ['sometimes', 'email', Rule::unique('hospital_users', 'email')->ignore($user->id)],
            'phone'      => 'nullable|string|max:30',
        ]);

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Account updated.',
            'data'    => $user->only(['id', 'first_name', 'last_name', 'email', 'phone', 'role']),
        ]);
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password'         => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $user = $request->user();

        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Your current password is incorrect.',
                'errors'  => ['current_password' => ['Your current password is incorrect.']],
            ], 422);
        }

        $user->update(['password' => Hash::make($data['password'])]);

        // Sign out every other session, keep this one.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(['success' => true, 'message' => 'Password changed.']);
    }
}
