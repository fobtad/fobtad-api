<?php
// app/Http/Controllers/Admin/AdminAuthController.php
namespace App\Http\Controllers\Admin;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminAuthController extends AdminController
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);

        $admin = Admin::where('email', $data['email'])->where('is_active', true)->first();

        if (!$admin || !Hash::check($data['password'], $admin->password)) {
            return response()->json(['success' => false, 'message' => 'Invalid email or password.'], 401);
        }

        $admin->update(['last_login_at' => now()]);

        return response()->json([
            'success' => true,
            'data'    => ['token' => $admin->createToken('admin-token')->plainTextToken, 'user' => $admin],
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['success' => true, 'data' => $request->user()]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['success' => true, 'message' => 'Logged out.']);
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password'         => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        $admin = $request->user();
        if (!Hash::check($data['current_password'], $admin->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Your current password is incorrect.',
                'errors'  => ['current_password' => ['Your current password is incorrect.']],
            ], 422);
        }

        $admin->update(['password' => Hash::make($data['password'])]);
        $admin->tokens()->where('id', '!=', $admin->currentAccessToken()->id)->delete();

        return response()->json(['success' => true, 'message' => 'Password changed.']);
    }

    // ── Admin team (super admins only) ──

    public function index(Request $request)
    {
        $this->authorizeAbility($request, 'settings');

        return response()->json(['success' => true, 'data' => Admin::orderBy('first_name')->get()]);
    }

    public function store(Request $request)
    {
        $this->authorizeAbility($request, 'settings');

        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'email'      => 'required|email|unique:admins,email',
            'role'       => ['required', Rule::in(['super_admin', 'admin', 'support'])],
            'password'   => ['required', Password::min(10)->letters()->numbers()],
        ]);

        $admin = Admin::create([...$data, 'password' => Hash::make($data['password'])]);

        return response()->json(['success' => true, 'message' => 'Admin added.', 'data' => $admin], 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeAbility($request, 'settings');
        $admin = Admin::findOrFail($id);

        $data = $request->validate([
            'role'      => ['sometimes', Rule::in(['super_admin', 'admin', 'support'])],
            'is_active' => 'sometimes|boolean',
        ]);

        if ($admin->id === $request->user()->id && (isset($data['role']) || ($data['is_active'] ?? true) === false)) {
            return response()->json(['success' => false, 'message' => "You can't change your own role or deactivate yourself."], 422);
        }

        $admin->update($data);
        if (($data['is_active'] ?? true) === false) {
            $admin->tokens()->delete();
        }

        return response()->json(['success' => true, 'message' => 'Admin updated.', 'data' => $admin]);
    }
}
