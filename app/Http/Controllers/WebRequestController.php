<?php
// app/Http/Controllers/WebRequestController.php
namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Patient;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Emergency requests from fobtad.com, no app or sign-in needed.
 *
 * There is no SMS code: the request is saved straight away and lands in the
 * admin console. FOBTAD calls the person back to confirm, then sends it to a
 * hospital. The person follows progress with the secret tracking token.
 */
class WebRequestController extends Controller
{
    public const EMERGENCY_LINE = '0902 664 9796';

    public const GUEST_EMAIL_DOMAIN = '@guest.fobtad.com';

    public const INCIDENTS = [
        'accident'   => 'Road accident',
        'collapse'   => 'Collapsed or unconscious',
        'breathing'  => 'Difficulty breathing',
        'chest_pain' => 'Chest pain',
        'bleeding'   => 'Heavy bleeding or injury',
        'labour'     => 'Pregnancy or labour',
        'other'      => 'Other emergency',
    ];

    /** Requests older than this can no longer be viewed with their link. */
    private const TRACKING_HOURS = 72;

    /** POST /v1/web/emergency */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|max:80',
            'phone'         => 'required|string|max:20',
            'incident_type' => 'required|in:' . implode(',', array_keys(self::INCIDENTS)),
            'latitude'      => 'nullable|numeric|between:-90,90|required_with:longitude',
            'longitude'     => 'nullable|numeric|between:-180,180|required_with:latitude',
            'address'       => 'nullable|string|max:255|required_without:latitude',
            'notes'         => 'nullable|string|max:500',
        ], [
            'address.required_without' => 'Share your location or type the address.',
        ]);

        $phone = static::normalisePhone($data['phone']);
        if (!$phone) {
            return response()->json([
                'success' => false,
                'message' => 'Enter a Nigerian phone number, for example 0803 123 4567.',
                'errors'  => ['phone' => ['Enter a Nigerian phone number, for example 0803 123 4567.']],
            ], 422);
        }

        $patient = static::findPatientByPhone($phone);

        if ($patient?->web_blocked_at) {
            return response()->json([
                'success' => false,
                'message' => 'We could not send this request online. Please call ' . self::EMERGENCY_LINE . ' now.',
            ], 403);
        }

        // A second tap (or a worried relative on the same phone) gets the open request, not a duplicate.
        $open = $patient ? Booking::where('patient_id', $patient->id)
            ->where('source', 'web')
            ->whereIn('status', ['pending', ...Booking::ACTIVE_STATUSES])
            ->where('created_at', '>=', now()->subHours(12))
            ->latest()->first() : null;

        if ($open) {
            return response()->json([
                'success' => true,
                'message' => 'We already have your request. FOBTAD will call you shortly.',
                'data'    => ['token' => $open->tracking_token, 'reference' => $open->reference],
            ]);
        }

        // The name typed may differ from the app account that owns this number (e.g. a relative's phone).
        $contact = $patient && strcasecmp(trim($patient->full_name), trim($data['name'])) !== 0
            ? "Requested by {$data['name']} on this number. " : '';

        $patient ??= static::createGuestPatient($data['name'], $phone);

        $booking = Booking::create([
            'patient_id'       => $patient->id,
            'tracking_token'   => Str::random(40),
            'type'             => 'emergency',
            'source'           => 'web',
            'status'           => 'pending',
            'pickup_address'   => $data['address'] ?? 'Shared GPS location',
            'pickup_latitude'  => $data['latitude'] ?? null,
            'pickup_longitude' => $data['longitude'] ?? null,
            'incident_type'    => self::INCIDENTS[$data['incident_type']],
            'notes'            => trim($contact . ($data['notes'] ?? '')) ?: null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Request received. FOBTAD will call you now.',
            'data'    => ['token' => $booking->tracking_token, 'reference' => $booking->reference],
        ], 201);
    }

    /** GET /v1/web/requests/{token} — what the person sees while they wait. */
    public function show(string $token): JsonResponse
    {
        $booking = $this->booking($token);
        $booking->load(['hospital', 'paramedic', 'ambulance', 'trip']);

        $stage = match (true) {
            $booking->status === 'cancelled'                          => 'closed',
            $booking->status === 'pending' && !$booking->hospital_id  => 'received',
            $booking->status === 'pending'                            => 'sent',
            in_array($booking->status, ['accepted', 'assigned'])     => 'assigned',
            default                                                   => $booking->status, // en_route, arrived, completed
        };

        $eta = null;
        if (in_array($stage, ['assigned', 'en_route']) && $booking->hospital?->latitude !== null && $booking->pickup_latitude !== null) {
            // Same rough estimate as the app: ~30 km/h in Lagos traffic plus a few minutes to get moving.
            $km  = \App\Models\Hospital::distanceKm($booking->hospital->latitude, $booking->hospital->longitude, $booking->pickup_latitude, $booking->pickup_longitude);
            $eta = (int) ceil($km / 30 * 60) + 3;
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'reference'      => $booking->reference,
                'stage'          => $stage,
                'incident_type'  => $booking->incident_type,
                'pickup_address' => $booking->pickup_address,
                'created_at'     => $booking->created_at,
                'hospital'       => $booking->hospital ? ['name' => $booking->hospital->name, 'phone' => $booking->hospital->phone] : null,
                'crew'           => $booking->paramedic ? ['name' => $booking->paramedic->first_name, 'phone' => $booking->paramedic->phone] : null,
                'vehicle'        => $booking->ambulance ? ['plate_number' => $booking->ambulance->plate_number] : null,
                'eta_minutes'    => $eta,
                'fare'           => $booking->fare,
                'amount_due'     => $booking->fare !== null ? $booking->amount_due : null,
                'payment_status' => $booking->payment_status,
                'emergency_line' => self::EMERGENCY_LINE,
            ],
        ]);
    }

    /** POST /v1/web/requests/{token}/pay — Paystack checkout once the trip is done. */
    public function pay(string $token, PaymentService $payments): JsonResponse
    {
        $booking = $this->booking($token);

        try {
            $result = $payments->startCardPayment($booking, $booking->patient);
        } catch (\DomainException | \RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    private function booking(string $token): Booking
    {
        return Booking::where('tracking_token', $token)
            ->where('source', 'web')
            ->where('created_at', '>=', now()->subHours(self::TRACKING_HOURS))
            ->firstOrFail();
    }

    /** "0803 123 4567", "+234 803 123 4567" or "2348031234567" → "08031234567"; null if not a Nigerian mobile. */
    public static function normalisePhone(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw);

        if (str_starts_with($digits, '234')) {
            $digits = '0' . substr($digits, 3);
        } elseif (strlen($digits) === 10) {
            $digits = '0' . $digits;
        }

        return preg_match('/^0[789][01]\d{8}$/', $digits) ? $digits : null;
    }

    /** Patients registered in the app may have saved their number in any format, so match on the last 10 digits. */
    public static function findPatientByPhone(string $phone): ?Patient
    {
        return Patient::where('phone', 'like', '%' . substr($phone, -10))
            ->orderByRaw('email like ?', ['%' . self::GUEST_EMAIL_DOMAIN]) // real app accounts first
            ->first();
    }

    /** A background account so the trip has a patient; they can claim it by signing up in the app later. */
    private static function createGuestPatient(string $name, string $phone): Patient
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return Patient::create([
            'first_name' => $parts[0],
            'last_name'  => $parts[1] ?? '',
            'email'      => "web-{$phone}" . self::GUEST_EMAIL_DOMAIN,
            'phone'      => $phone,
            'password'   => Hash::make(Str::random(40)),
        ]);
    }
}
