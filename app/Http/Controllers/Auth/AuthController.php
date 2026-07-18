<?php
// app/Http/Controllers/Auth/AuthController.php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private OtpService $otpService) {}

    public function register(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'email'      => 'required|email|unique:patients,email',
            'phone'      => 'required|string|unique:patients,phone',
            'password'   => 'required|string|min:8',
        ]);

        $patient = Patient::create([
            ...$data,
            'password'    => Hash::make($data['password']),
            'is_verified' => false,
        ]);

        $code = $this->otpService->generate($patient->email, 'email_verification');
        $this->otpService->sendVerificationEmail($patient->email, $patient->first_name, $code);

        return response()->json([
            'success' => true,
            'message' => 'Account created. Check your email for a verification code.',
            'data'    => ['email' => $patient->email],
        ], 201);
    }

    public function verifyOtp(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|exists:patients,email',
            'otp'   => 'required|string|size:6',
        ]);

        $verified = $this->otpService->verify($data['email'], $data['otp'], 'email_verification');

        if (!$verified) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP.',
            ], 422);
        }

        $patient = Patient::where('email', $data['email'])->first();
        $patient->update([
            'is_verified'       => true,
            'email_verified_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully. You can now sign in.',
        ]);
    }

    public function resendOtp(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|exists:patients,email',
            'type'  => 'required|in:email_verification,password_reset',
        ]);

        $patient = Patient::where('email', $data['email'])->first();
        $code    = $this->otpService->generate($data['email'], $data['type']);

        if ($data['type'] === 'email_verification') {
            $this->otpService->sendVerificationEmail($data['email'], $patient->first_name, $code);
        } else {
            $this->otpService->sendPasswordResetEmail($data['email'], $patient->first_name, $code);
        }

        return response()->json([
            'success' => true,
            'message' => 'OTP resent successfully.',
        ]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $patient = Patient::where('email', $data['email'])->first();

        if (!$patient || !Hash::check($data['password'], $patient->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
            ], 401);
        }

        if (!$patient->is_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Please verify your email before signing in.',
                'action'  => 'verify_email',
            ], 403);
        }

        $token = $patient->createToken('patient-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data'    => [
                'token'   => $token,
                'patient' => [
                    'id'         => $patient->id,
                    'first_name' => $patient->first_name,
                    'last_name'  => $patient->last_name,
                    'email'      => $patient->email,
                    'phone'      => $patient->phone,
                    'is_verified'=> $patient->is_verified,
                ],
            ],
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|exists:patients,email',
        ]);

        $patient = Patient::where('email', $data['email'])->first();
        $code    = $this->otpService->generate($data['email'], 'password_reset');
        $this->otpService->sendPasswordResetEmail($data['email'], $patient->first_name, $code);

        return response()->json([
            'success' => true,
            'message' => 'Password reset code sent to your email.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|email|exists:patients,email',
            'otp'      => 'required|string|size:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $verified = $this->otpService->verify($data['email'], $data['otp'], 'password_reset');

        if (!$verified) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP.',
            ], 422);
        }

        Patient::where('email', $data['email'])
            ->update(['password' => Hash::make($data['password'])]);

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. You can now sign in.',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'success' => true,
            'data'    => $request->user(),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }
}