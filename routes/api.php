<?php
// routes/api.php
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\Hospital\HospitalAuthController;
use App\Http\Controllers\Hospital\HospitalDashboardController;
use App\Http\Controllers\Hospital\HospitalBookingController;
use App\Http\Controllers\Hospital\HospitalReportController;
use App\Http\Controllers\Hospital\HospitalSettingsController;
use App\Http\Controllers\Hospital\ParamedicController;
use App\Http\Controllers\Hospital\AmbulanceController;
use App\Http\Controllers\Provider\ProviderAuthController;
use App\Http\Controllers\Provider\ProviderDashboardController;
use App\Http\Controllers\Provider\ProviderJobController;
use App\Http\Controllers\Provider\DriverController;
use App\Http\Controllers\Provider\TowTruckController;
use Illuminate\Support\Facades\Route;

// ── Health check ──
Route::get('/v1/health', fn() => response()->json([
    'success' => true,
    'message' => 'FOBTAD API is running.',
    'version' => '1.0.0',
    'timestamp' => now(),
]));

Route::get('/clear-cache', function () {
    Artisan::call('route:clear');
    Artisan::call('config:clear');
    Artisan::call('cache:clear');
    return 'Cache cleared';
});

Route::get('/run-migrate', function () {
    if (request()->get('key') !== 'fobtad2026')
        abort(403);
    Artisan::call('migrate', ['--force' => true]);
    return Artisan::output();
});


// ── Paystack webhook (no auth) ──
Route::post('/v1/wallet/webhook', [WalletController::class, 'webhook']);


// ── Patient auth ──
Route::prefix('v1/auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// ── Patient protected routes ──
Route::prefix('v1')->middleware(['auth:sanctum', 'user.type:patient'])->group(function () {
    // Auth
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Profile
    Route::get('/patient/profile', [PatientController::class, 'show']);
    Route::put('/patient/profile', [PatientController::class, 'update']);

    // Bookings
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::get('/bookings/{id}', [BookingController::class, 'show']);
    Route::post('/bookings/emergency', [BookingController::class, 'emergency']);
    Route::post('/bookings/scheduled', [BookingController::class, 'scheduled']);
    Route::post('/bookings/maternal', [BookingController::class, 'maternal']);
    Route::post('/bookings/towing', [BookingController::class, 'towing']);
    Route::put('/bookings/{id}/cancel', [BookingController::class, 'cancel']);
    Route::post('/bookings/{id}/rate', [BookingController::class, 'rate']);
    Route::post('/bookings/estimate', [BookingController::class, 'estimate']);
    Route::get('/bookings/{reference}/location', [BookingController::class, 'location']);

    // Tracking
    Route::get('/trips/{bookingId}/track', [TripController::class, 'track']);

    // Payments
    Route::post('/payments/initialize', [PaymentController::class, 'initialize']);
    Route::get('/payments/verify/{reference}', [PaymentController::class, 'verify']);


    // ── Wallet ──
    Route::get('/wallet', [WalletController::class, 'show']);
    Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
    Route::post('/wallet/virtual-account', [WalletController::class, 'createVirtualAccount']);
    Route::put('/wallet/virtual-account/{id}/primary', [WalletController::class, 'setPrimary']);
    Route::delete('/wallet/virtual-account/{id}', [WalletController::class, 'deleteVirtualAccount']);
    Route::post('/wallet/fund', [WalletController::class, 'initializeFunding']);
    Route::get('/wallet/verify/{reference}', [WalletController::class, 'verifyFunding']);
});

// ── Hospital portal auth ──
Route::prefix('v1/hospital')->group(function () {
    Route::post('/login', [HospitalAuthController::class, 'login']);
    Route::post('/logout', [HospitalAuthController::class, 'logout'])
        ->middleware(['auth:sanctum', 'user.type:hospital']);
});

// ── Hospital portal protected routes ──
Route::prefix('v1/hospital')->middleware(['auth:sanctum', 'user.type:hospital'])->group(function () {
    Route::get('/me', [HospitalAuthController::class, 'me']);
    Route::get('/dashboard', [HospitalDashboardController::class, 'index']);
    Route::get('/reports', [HospitalReportController::class, 'index']);

    // Profile & account
    Route::get('/profile', [HospitalSettingsController::class, 'profile']);
    Route::put('/profile', [HospitalSettingsController::class, 'updateProfile']);
    Route::put('/account', [HospitalSettingsController::class, 'updateAccount']);
    Route::put('/password', [HospitalSettingsController::class, 'updatePassword']);

    // Bookings
    Route::get('/bookings', [HospitalBookingController::class, 'index']);
    Route::get('/bookings/summary', [HospitalBookingController::class, 'summary']);
    Route::get('/bookings/{id}', [HospitalBookingController::class, 'show']);
    Route::put('/bookings/{id}/accept', [HospitalBookingController::class, 'accept']);
    Route::put('/bookings/{id}/decline', [HospitalBookingController::class, 'decline']);
    Route::put('/bookings/{id}/status', [HospitalBookingController::class, 'updateStatus']);
    Route::put('/bookings/{id}/complete', [HospitalBookingController::class, 'complete']);

    // Paramedics
    Route::get('/paramedics', [ParamedicController::class, 'index']);
    Route::post('/paramedics', [ParamedicController::class, 'store']);
    Route::put('/paramedics/{id}', [ParamedicController::class, 'update']);
    Route::delete('/paramedics/{id}', [ParamedicController::class, 'destroy']);

    // Fleet
    Route::get('/fleet', [AmbulanceController::class, 'index']);
    Route::post('/fleet', [AmbulanceController::class, 'store']);
    Route::put('/fleet/{id}', [AmbulanceController::class, 'update']);
    Route::delete('/fleet/{id}', [AmbulanceController::class, 'destroy']);
});

// ── Provider portal auth ──
Route::prefix('v1/provider')->group(function () {
    Route::post('/login', [ProviderAuthController::class, 'login']);
    Route::post('/logout', [ProviderAuthController::class, 'logout'])
        ->middleware(['auth:sanctum', 'user.type:provider']);
});

// ── Provider portal protected routes ──
Route::prefix('v1/provider')->middleware(['auth:sanctum', 'user.type:provider'])->group(function () {
    Route::get('/dashboard', [ProviderDashboardController::class, 'index']);

    // Jobs
    Route::get('/jobs', [ProviderJobController::class, 'index']);
    Route::put('/jobs/{id}/accept', [ProviderJobController::class, 'accept']);
    Route::put('/jobs/{id}/decline', [ProviderJobController::class, 'decline']);
    Route::put('/jobs/{id}/complete', [ProviderJobController::class, 'complete']);

    // Drivers
    Route::get('/drivers', [DriverController::class, 'index']);
    Route::post('/drivers', [DriverController::class, 'store']);
    Route::put('/drivers/{id}', [DriverController::class, 'update']);
    Route::delete('/drivers/{id}', [DriverController::class, 'destroy']);

    // Trucks
    Route::get('/trucks', [TowTruckController::class, 'index']);
    Route::post('/trucks', [TowTruckController::class, 'store']);
    Route::put('/trucks/{id}', [TowTruckController::class, 'update']);
    Route::delete('/trucks/{id}', [TowTruckController::class, 'destroy']);


});