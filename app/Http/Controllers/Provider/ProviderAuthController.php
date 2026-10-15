<?php
// app/Http/Controllers/Provider/ProviderAuthController.php
namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\ProviderUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProviderAuthController extends Controller
{
    /**
     * Self-signup. The provider starts as `pending`: staff can sign in and set up
     * drivers and vehicles, but no jobs are routed to it until FOBTAD approves it.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'provider.company_name'   => 'required|string|max:150',
            'provider.business_type'  => ['required', Rule::in(['towing_company', 'vehicle_recovery', 'roadside_assistance', 'fleet_operator'])],
            'provider.services'       => 'sometimes|array|min:1',
            'provider.services.*'     => ['string', Rule::in(Provider::SERVICES)],
            'provider.email'          => 'required|email|unique:providers,email',
            'provider.phone'          => 'required|string|max:30',
            'provider.address'        => 'required|string|max:255',
            'provider.lga'            => 'nullable|string|max:100',
            'provider.latitude'       => 'nullable|numeric|between:-90,90',
            'provider.longitude'      => 'nullable|numeric|between:-180,180',
            'provider.cac_number'     => 'nullable|string|max:100',
            'provider.coverage_areas' => 'nullable|string|max:500',
            'first_name'              => 'required|string|max:100',
            'last_name'               => 'required|string|max:100',
            'email'                   => 'required|email|unique:provider_users,email',
            'phone'                   => 'nullable|string|max:30',
            'password'                => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [], [
            'provider.company_name' => 'company name', 'provider.business_type' => 'business type',
            'provider.email' => 'company email', 'provider.phone' => 'company phone',
            'provider.address' => 'company address',
        ]);

        $user = DB::transaction(function () use ($data) {
            $provider = Provider::create([
                ...$data['provider'],
                'services' => $data['provider']['services'] ?? ['towing'],
                'state'    => 'Lagos',
                'status'   => 'pending',
            ]);

            return ProviderUser::create([
                'provider_id'   => $provider->id,
                'first_name'    => $data['first_name'],
                'last_name'     => $data['last_name'],
                'email'         => $data['email'],
                'phone'         => $data['phone'] ?? null,
                'password'      => Hash::make($data['password']),
                'role'          => 'admin',
                'is_active'     => true,
                'last_login_at' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Registration received. FOBTAD will verify your company shortly.',
            'data'    => [
                'token' => $user->createToken('provider-token')->plainTextToken,
                'user'  => $this->userPayload($user),
            ],
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = ProviderUser::where('email', $data['email'])
            ->where('is_active', true)
            ->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
            ], 401);
        }

        if ($user->provider?->status === 'suspended') {
            return response()->json([
                'success' => false,
                'message' => 'This provider account has been suspended. Please contact FOBTAD.',
            ], 403);
        }

        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('provider-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data'    => [
                'token' => $token,
                'user'  => $this->userPayload($user),
            ],
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'success' => true,
            'data'    => $this->userPayload($request->user()),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true, 'message' => 'Logged out.']);
    }

    private function userPayload(ProviderUser $user): array
    {
        return [
            'id'         => $user->id,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'email'      => $user->email,
            'phone'      => $user->phone,
            'role'       => $user->role,
            'provider'   => $user->provider()->first(),
        ];
    }
}
