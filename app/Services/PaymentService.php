<?php
// app/Services/PaymentService.php
namespace App\Services;

use App\Models\Booking;
use App\Models\Earning;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * All money movement goes through here so every path is atomic and idempotent:
 *  - settle(): at trip end, take what the wallet can cover; the rest is due by card
 *  - startCardPayment()/completeCardPayment(): Paystack for the amount still due
 *  - startWalletFunding()/completeWalletFunding(): Paystack top-ups
 *  - recordEarning(): once fully paid, credit the hospital/provider net of commission
 * Paystack confirmations (app verify or webhook) are checked for status, amount
 * and currency, and applied at most once under a row lock.
 */
class PaymentService
{
    public function __construct(private PaystackService $paystack)
    {
    }

    // ── Booking settlement ──

    /** Charge the wallet for a finished (or fee-bearing cancelled) booking. */
    public function settle(Booking $booking): void
    {
        DB::transaction(function () use ($booking) {
            $booking = Booking::lockForUpdate()->find($booking->id);

            if (!$booking->fare || $booking->fare <= 0) {
                $booking->update(['payment_status' => 'waived']);
                return;
            }

            $due = $booking->amount_due;
            if ($due <= 0) {
                $this->markPaidIfComplete($booking);
                return;
            }

            $wallet = Wallet::where('patient_id', $booking->patient_id)->lockForUpdate()->first();
            $take   = $wallet && $wallet->status === 'active' ? min((float) $wallet->balance, $due) : 0;

            if ($take > 0) {
                $reference = 'BKW-' . strtoupper(Str::random(12));
                $before    = (float) $wallet->balance;

                $wallet->update(['balance' => round($before - $take, 2)]);
                $wallet->transactions()->create([
                    'type'           => 'debit',
                    'amount'         => $take,
                    'balance_before' => $before,
                    'balance_after'  => round($before - $take, 2),
                    'reference'      => $reference,
                    'description'    => "Payment for booking {$booking->reference}",
                    'source'         => 'booking_payment',
                    'status'         => 'success',
                    'meta'           => ['booking_id' => $booking->id],
                ]);

                Payment::create([
                    'booking_id' => $booking->id,
                    'patient_id' => $booking->patient_id,
                    'reference'  => $reference,
                    'amount'     => $take,
                    'method'     => 'wallet',
                    'currency'   => 'NGN',
                    'status'     => 'success',
                    'channel'    => 'wallet',
                    'paid_at'    => now(),
                ]);

                $booking->amount_paid = round($booking->amount_paid + $take, 2);
            }

            $booking->payment_status = $booking->amount_due <= 0 ? 'paid' : ($booking->amount_paid > 0 ? 'partially_paid' : 'unpaid');
            $booking->save();

            $this->markPaidIfComplete($booking);
        });
    }

    /** Start a Paystack checkout for whatever is still owed on a booking. */
    public function startCardPayment(Booking $booking, Patient $patient): array
    {
        $due = $booking->amount_due;

        if (!in_array($booking->status, ['completed', 'cancelled']) || $due <= 0) {
            throw new \DomainException('Nothing is due on this booking.');
        }

        $reference = 'PAY-' . strtoupper(Str::random(12));

        $result = $this->paystack->initializePayment($patient->email, $due, $reference, [
            'type'              => 'booking_payment',
            'booking_id'        => $booking->id,
            'booking_reference' => $booking->reference,
        ]);

        if (!($result['status'] ?? false)) {
            throw new \RuntimeException($result['message'] ?? 'Could not start the payment.');
        }

        Payment::create([
            'booking_id' => $booking->id,
            'patient_id' => $patient->id,
            'reference'  => $reference,
            'amount'     => $due,
            'method'     => 'card',
            'currency'   => 'NGN',
            'status'     => 'pending',
        ]);

        return ['payment_url' => $result['data']['authorization_url'], 'reference' => $reference, 'amount' => $due];
    }

    /** Apply a confirmed Paystack charge to its pending booking payment. Safe to call repeatedly. */
    public function completeCardPayment(string $reference, array $tx): Payment
    {
        return DB::transaction(function () use ($reference, $tx) {
            $payment = Payment::where('reference', $reference)->lockForUpdate()->firstOrFail();

            if ($payment->status === 'success') {
                return $payment;
            }

            $this->assertCharge($tx, (float) $payment->amount);

            $payment->update([
                'status'             => 'success',
                'paystack_reference' => $tx['reference'] ?? $reference,
                'channel'            => $tx['channel'] ?? 'card',
                'paystack_response'  => $tx,
                'paid_at'            => now(),
            ]);

            $booking = Booking::lockForUpdate()->find($payment->booking_id);

            // Already covered (e.g. two checkouts both paid): refund to the wallet.
            $overpaid = round(max(0, $payment->amount - $booking->amount_due), 2);
            if ($overpaid > 0) {
                $this->creditWallet($booking->patient_id, $overpaid, "Overpayment refund for booking {$booking->reference}", 'refund', "RFD-{$payment->reference}");
            }

            $booking->amount_paid    = round($booking->amount_paid + $payment->amount - $overpaid, 2);
            $booking->payment_status = $booking->amount_due <= 0 ? 'paid' : 'partially_paid';
            $booking->save();

            $this->markPaidIfComplete($booking);

            return $payment->fresh();
        });
    }

    // ── Wallet funding ──

    public function startWalletFunding(Wallet $wallet, Patient $patient, float $amount): array
    {
        $reference = 'WLT-' . strtoupper(Str::random(12));

        $result = $this->paystack->initializePayment($patient->email, $amount, $reference, [
            'type'       => 'wallet_funding',
            'wallet_id'  => $wallet->id,
            'patient_id' => $patient->id,
        ]);

        if (!($result['status'] ?? false)) {
            throw new \RuntimeException($result['message'] ?? 'Payment initialization failed.');
        }

        $wallet->transactions()->create([
            'type'           => 'credit',
            'amount'         => $amount,
            'balance_before' => $wallet->balance,
            'balance_after'  => $wallet->balance,
            'reference'      => $reference,
            'description'    => 'Wallet funding via Paystack',
            'source'         => 'paystack_checkout',
            'status'         => 'pending',
        ]);

        return ['payment_url' => $result['data']['authorization_url'], 'reference' => $reference];
    }

    /** Credit a confirmed Paystack top-up exactly once. */
    public function completeWalletFunding(string $reference, array $tx): array
    {
        return DB::transaction(function () use ($reference, $tx) {
            $transaction = WalletTransaction::where('reference', $reference)->lockForUpdate()->firstOrFail();
            $wallet      = Wallet::lockForUpdate()->find($transaction->wallet_id);

            if ($transaction->status !== 'success') {
                $this->assertCharge($tx, (float) $transaction->amount);

                $before = (float) $wallet->balance;
                $after  = round($before + $transaction->amount, 2);

                $wallet->update(['balance' => $after]);
                $transaction->update([
                    'status'         => 'success',
                    'balance_before' => $before,
                    'balance_after'  => $after,
                    'meta'           => ['channel' => $tx['channel'] ?? null, 'paystack_id' => $tx['id'] ?? null],
                ]);
            }

            return ['amount' => (float) $transaction->amount, 'balance' => (float) $wallet->fresh()->balance];
        });
    }

    private function creditWallet(int $patientId, float $amount, string $description, string $source, string $reference): void
    {
        $wallet = Wallet::lockForUpdate()->firstOrCreate(
            ['patient_id' => $patientId],
            ['balance' => 0, 'currency' => 'NGN', 'status' => 'active'],
        );
        $before = (float) $wallet->balance;
        $after  = round($before + $amount, 2);

        $wallet->update(['balance' => $after]);
        $wallet->transactions()->create([
            'type' => 'credit', 'amount' => $amount, 'balance_before' => $before, 'balance_after' => $after,
            'reference' => $reference, 'description' => $description, 'source' => $source, 'status' => 'success',
        ]);
    }

    // ── Earnings ──

    /** Once a booking is fully paid, credit the hospital/provider their share. */
    public function markPaidIfComplete(Booking $booking): void
    {
        if ($booking->payment_status !== 'paid' || !$booking->fare) {
            return;
        }

        [$type, $id] = $booking->hospital_id
            ? ['hospital', $booking->hospital_id]
            : ($booking->provider_id ? ['provider', $booking->provider_id] : [null, null]);

        if (!$type || Earning::where('booking_id', $booking->id)->exists()) {
            return;
        }

        $percent    = (float) PlatformSetting::get('commission_percent');
        $commission = round($booking->fare * $percent / 100, 2);

        Earning::create([
            'booking_id'         => $booking->id,
            'payee_type'         => $type,
            'payee_id'           => $id,
            'gross'              => $booking->fare,
            'commission_percent' => $percent,
            'commission'         => $commission,
            'net'                => round($booking->fare - $commission, 2),
        ]);
    }

    // ── Paystack checks ──

    /** Confirm with Paystack server-side, then apply. Used by the app's verify calls. */
    public function verifyWithPaystack(string $reference): array
    {
        $response = $this->paystack->verifyPayment($reference);

        if (!($response['status'] ?? false) || !isset($response['data'])) {
            throw new \RuntimeException('Could not verify this payment yet. Please try again shortly.');
        }

        return $response['data'];
    }

    /** Handle a signed Paystack webhook. */
    public function handleWebhook(array $payload): void
    {
        if (($payload['event'] ?? null) !== 'charge.success') {
            return;
        }

        $tx        = $payload['data'] ?? [];
        $reference = $tx['reference'] ?? '';

        if (str_starts_with($reference, 'WLT-')) {
            $this->completeWalletFunding($reference, $tx);
        } elseif (str_starts_with($reference, 'PAY-')) {
            $this->completeCardPayment($reference, $tx);
        }
    }

    private function assertCharge(array $tx, float $expectedNaira): void
    {
        if (($tx['status'] ?? null) !== 'success') {
            throw new \DomainException('Payment was not successful.');
        }

        if (strtoupper($tx['currency'] ?? 'NGN') !== 'NGN') {
            throw new \DomainException('Unexpected payment currency.');
        }

        if ((int) ($tx['amount'] ?? 0) !== (int) round($expectedNaira * 100)) {
            throw new \DomainException('Paid amount does not match the amount due.');
        }
    }
}
