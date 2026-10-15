<?php
// app/Http/Controllers/Provider/ProviderSettingsController.php
namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProviderSettingsController extends Controller
{
    public function profile(Request $request)
    {
        return response()->json(['success' => true, 'data' => $request->user()->provider]);
    }

    /** Company profile. Only provider admins can change it; status stays with FOBTAD admin. */
    public function updateProfile(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Only company administrators can edit the company profile.',
            ], 403);
        }

        $provider = $request->user()->provider;

        $data = $request->validate([
            'company_name'   => 'sometimes|string|max:150',
            'business_type'  => ['nullable', Rule::in(['towing_company', 'vehicle_recovery', 'roadside_assistance', 'fleet_operator'])],
            'services'       => 'sometimes|array|min:1',
            'services.*'     => ['string', Rule::in(Provider::SERVICES)],
            'email'          => ['sometimes', 'email', Rule::unique('providers', 'email')->ignore($provider->id)],
            'phone'          => 'sometimes|string|max:30',
            'address'        => 'sometimes|string|max:255',
            'lga'            => 'nullable|string|max:100',
            'state'          => 'sometimes|string|max:100',
            'latitude'       => 'nullable|numeric|between:-90,90',
            'longitude'      => 'nullable|numeric|between:-180,180',
            'cac_number'     => 'nullable|string|max:100',
            'coverage_areas' => 'nullable|string|max:500',
            'description'    => 'nullable|string|max:2000',
        ]);

        $provider->update($data);

        return response()->json(['success' => true, 'message' => 'Company profile updated.', 'data' => $provider]);
    }

    public function updateAccount(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'first_name' => 'sometimes|string|max:100',
            'last_name'  => 'sometimes|string|max:100',
            'email'      => ['sometimes', 'email', Rule::unique('provider_users', 'email')->ignore($user->id)],
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
