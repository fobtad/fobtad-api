<?php
// app/Http/Controllers/Hospital/HospitalAuthController.php
namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\HospitalUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class HospitalAuthController extends Controller
{
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

        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('hospital-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data'    => [
                'token' => $token,
                'user'  => [
                    'id'           => $user->id,
                    'first_name'   => $user->first_name,
                    'last_name'    => $user->last_name,
                    'email'        => $user->email,
                    'role'         => $user->role,
                    'hospital'     => $user->hospital,
                ],
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true, 'message' => 'Logged out.']);
    }
}