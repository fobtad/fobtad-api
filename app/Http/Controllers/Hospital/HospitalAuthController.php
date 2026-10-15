<?php
// app/Http/Controllers/Hospital/HospitalAuthController.php
namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Models\HospitalUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class HospitalAuthController extends Controller
{
    /**
     * Self-signup. The hospital starts as `pending`: staff can sign in and set up
     * paramedics and fleet, but no bookings are routed to it until FOBTAD approves it.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'hospital.name'           => 'required|string|max:150',
            'hospital.facility_type'  => ['required', Rule::in(['government', 'private', 'clinic', 'teaching'])],
            'hospital.email'          => 'required|email|unique:hospitals,email',
            'hospital.phone'          => 'required|string|max:30',
            'hospital.address'        => 'required|string|max:255',
            'hospital.lga'            => 'nullable|string|max:100',
            'hospital.latitude'       => 'nullable|numeric|between:-90,90',
            'hospital.longitude'      => 'nullable|numeric|between:-180,180',
            'hospital.licence_number' => 'required|string|max:100',
            'hospital.cac_number'     => 'nullable|string|max:100',
            'first_name'              => 'required|string|max:100',
            'last_name'               => 'required|string|max:100',
            'email'                   => 'required|email|unique:hospital_users,email',
            'phone'                   => 'nullable|string|max:30',
            'password'                => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [], [
            'hospital.name' => 'hospital name', 'hospital.facility_type' => 'facility type',
            'hospital.email' => 'hospital email', 'hospital.phone' => 'hospital phone',
            'hospital.address' => 'hospital address', 'hospital.licence_number' => 'licence number',
        ]);

        $user = DB::transaction(function () use ($data) {
            $hospital = Hospital::create([
                ...$data['hospital'],
                'state'  => 'Lagos',
                'status' => 'pending',
            ]);

            return HospitalUser::create([
                'hospital_id'   => $hospital->id,
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
            'message' => 'Registration received. FOBTAD will verify your hospital shortly.',
            'data'    => [
                'token' => $user->createToken('hospital-token')->plainTextToken,
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

        $user = HospitalUser::where('email', $data['email'])
            ->where('is_active', true)
            ->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
            ], 401);
        }

        if ($user->hospital?->status === 'suspended') {
            return response()->json([
                'success' => false,
                'message' => 'This hospital account has been suspended. Please contact FOBTAD.',
            ], 403);
        }

        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('hospital-token')->plainTextToken;

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

    private function userPayload(HospitalUser $user): array
    {
        return [
            'id'         => $user->id,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'email'      => $user->email,
            'phone'      => $user->phone,
            'role'       => $user->role,
            'hospital'   => $user->hospital()->first(),
        ];
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true, 'message' => 'Logged out.']);
    }
}