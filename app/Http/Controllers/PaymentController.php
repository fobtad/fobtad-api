<?php
// app/Http/Controllers/PaymentController.php
namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;

/**
 * Patient side of booking payments. Amounts always come from the booking's
 * server-side fare — never from the client.
 */
class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    /** GET /bookings/{id}/payment — what's owed and what has been paid. */
    public function summary(Request $request, $id)
    {
        $booking = $this->booking($request, $id);

        return response()->json([
            'success' => true,
            'data'    => [
                'booking_id'     => $booking->id,
                'reference'      => $booking->reference,
                'fare'           => $booking->fare,
                'amount_paid'    => $booking->amount_paid,
                'amount_due'     => $booking->amount_due,
                'payment_status' => $booking->payment_status,
                'payments'       => $booking->payments()->where('status', 'success')->latest()
                    ->get(['reference', 'amount', 'method', 'channel', 'paid_at']),
            ],
        ]);
    }

    /** POST /bookings/{id}/pay — Paystack checkout for the amount still due. */
    public function pay(Request $request, $id)
    {
        try {
            $result = $this->payments->startCardPayment($this->booking($request, $id), $request->user());
        } catch (\DomainException | \RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    /** Legacy: POST /payments/initialize {booking_id}. Any client amount is ignored. */
    public function initialize(Request $request)
    {
        $data = $request->validate(['booking_id' => 'required|integer']);

        return $this->pay($request, $data['booking_id']);
    }

    /** GET /payments/verify/{reference} — confirm with Paystack and apply once. */
    public function verify(Request $request, string $reference)
    {
        $payment = Payment::where('reference', $reference)
            ->where('patient_id', $request->user()->id)
            ->firstOrFail();

        try {
            $payment = $this->payments->completeCardPayment($reference, $this->payments->verifyWithPaystack($reference));
        } catch (\DomainException | \RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        $booking = $payment->booking()->first();

        return response()->json([
            'success' => true,
            'message' => 'Payment confirmed. Thank you!',
            'data'    => [
                'reference'      => $payment->reference,
                'amount'         => $payment->amount,
                'amount_due'     => $booking->amount_due,
                'payment_status' => $booking->payment_status,
            ],
        ]);
    }

    /** GET /payments/callback — where Paystack returns the browser after checkout. */
    public function callback()
    {
        return response('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>FOBTAD</title></head>'
            . '<body style="font-family:system-ui,sans-serif;text-align:center;padding:48px 16px;color:#042C53">'
            . '<h2>Payment received</h2><p>You can return to the FOBTAD app.</p></body></html>');
    }

    private function booking(Request $request, $id): Booking
    {
        return Booking::where('patient_id', $request->user()->id)->findOrFail($id);
    }
}
