<?php
// app/Http/Controllers/PaymentController.php
namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function __construct(private PaystackService $paystack) {}

    public function initialize(Request $request)
    {
        $data = $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'amount'     => 'required|numeric|min:100',
        ]);

        $booking = Booking::where('patient_id', $request->user()->id)
            ->findOrFail($data['booking_id']);

        $reference = 'PAY-' . strtoupper(Str::random(12));

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'patient_id' => $request->user()->id,
            'reference'  => $reference,
            'amount'     => $data['amount'],
            'currency'   => 'NGN',
            'status'     => 'pending',
        ]);

        $result = $this->paystack->initializePayment(
            $request->user()->email,
            $data['amount'],
            $reference,
            ['booking_id' => $booking->id, 'booking_reference' => $booking->reference]
        );

        if (!$result['status']) {
            return response()->json([
                'success' => false,
                'message' => 'Payment initialization failed.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'payment_url'       => $result['data']['authorization_url'],
                'reference'         => $reference,
                'paystack_reference'=> $result['data']['reference'],
            ],
        ]);
    }

    public function verify(Request $request, string $reference)
    {
        $payment = Payment::where('reference', $reference)
            ->where('patient_id', $request->user()->id)
            ->firstOrFail();

        $result = $this->paystack->verifyPayment($reference);

        if ($result['data']['status'] === 'success') {
            $payment->update([
                'status'             => 'success',
                'paystack_reference' => $result['data']['reference'],
                'channel'            => $result['data']['channel'],
                'paystack_response'  => $result['data'],
                'paid_at'            => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment verified successfully.',
                'data'    => $payment,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Payment verification failed.',
        ], 400);
    }
}