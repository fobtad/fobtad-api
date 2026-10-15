<?php
// app/Http/Controllers/Admin/AdminBookingController.php
namespace App\Http\Controllers\Admin;

use App\Models\Booking;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Oversight of every booking, plus the fixes ops needs: reassign, cancel, refund. */
class AdminBookingController extends AdminController
{
    private const LIST_RELATIONS = [
        'patient:id,first_name,last_name,phone', 'hospital:id,name', 'provider:id,company_name',
        'paramedic:id,first_name,last_name', 'driver:id,first_name,last_name',
    ];

    public function index(Request $request)
    {
        $statuses = $request->filled('status') ? explode(',', $request->status) : null;

        $bookings = Booking::with(self::LIST_RELATIONS)
            ->when($statuses, fn($q) => $q->whereIn('status', $statuses))
            ->when($request->type, fn($q, $t) => $q->where('type', $t))
            ->when($request->payment_status, fn($q, $p) => $q->where('payment_status', $p))
            ->when($request->boolean('unassigned'), fn($q) => $q->whereNull('hospital_id')->whereNull('provider_id'))
            ->when($request->hospital_id, fn($q, $id) => $q->where('hospital_id', $id))
            ->when($request->provider_id, fn($q, $id) => $q->where('provider_id', $id))
            ->when($request->patient_id, fn($q, $id) => $q->where('patient_id', $id))
            ->when($request->search, fn($q, $s) => $q->where(fn($q) => $q
                ->where('reference', 'like', "%{$s}%")
                ->orWhere('pickup_address', 'like', "%{$s}%")
                ->orWhereHas('patient', fn($p) => $p->where('first_name', 'like', "%{$s}%")
                    ->orWhere('last_name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"))))
            ->latest()
            ->paginate($this->perPage($request));

        return response()->json(['success' => true, 'data' => $bookings]);
    }

    public function show($id)
    {
        $booking = Booking::with([...self::LIST_RELATIONS, 'ambulance', 'towTruck', 'trip', 'payments', 'earning'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => [
            ...$booking->toArray(),
            'amount_due' => $booking->amount_due,
            'refundable' => $this->refundable($booking),
        ]]);
    }

    /** Send a pending booking to a specific (active) hospital or provider. */
    public function reassign(Request $request, $id)
    {
        $this->authorizeAbility($request, 'operations');

        $data = $request->validate([
            'hospital_id' => 'required_without:provider_id|nullable|integer',
            'provider_id' => 'required_without:hospital_id|nullable|integer',
        ]);

        $booking = Booking::whereIn('status', ['pending', 'cancelled'])->findOrFail($id);

        if ($booking->status === 'cancelled' && $booking->payment_status === 'paid') {
            return response()->json(['success' => false, 'message' => 'This booking was cancelled with a fee. Ask the patient to book again.'], 422);
        }

        if (!empty($data['hospital_id'])) {
            $target = Hospital::where('status', 'active')->find($data['hospital_id']);
            $update = ['hospital_id' => $target?->id, 'provider_id' => null];
        } else {
            $target = Provider::where('status', 'active')->find($data['provider_id']);
            $update = ['provider_id' => $target?->id, 'hospital_id' => null];
        }

        if (!$target) {
            return response()->json(['success' => false, 'message' => 'Choose an approved hospital or provider.'], 422);
        }

        $booking->update([...$update, 'status' => 'pending', 'cancelled_at' => null, 'cancellation_reason' => null, 'payment_status' => 'unpaid']);

        return response()->json(['success' => true, 'message' => 'Booking reassigned.', 'data' => $booking->fresh(self::LIST_RELATIONS)]);
    }

    public function cancel(Request $request, $id)
    {
        $this->authorizeAbility($request, 'operations');
        $reason = $request->validate(['reason' => 'required|string|max:255'])['reason'];

        $booking = Booking::whereIn('status', ['pending', ...Booking::ACTIVE_STATUSES])->findOrFail($id);
        $booking->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => "FOBTAD: {$reason}", 'payment_status' => 'waived']);
        $booking->releaseCrew();

        return response()->json(['success' => true, 'message' => 'Booking cancelled.']);
    }

    /** Refund part or all of what the patient paid, to their FOBTAD wallet. */
    public function refund(Request $request, $id)
    {
        $this->authorizeAbility($request, 'operations');

        $booking    = Booking::findOrFail($id);
        $refundable = $this->refundable($booking);

        if ($refundable <= 0) {
            return response()->json(['success' => false, 'message' => 'Nothing left to refund on this booking.'], 422);
        }

        $data = $request->validate([
            'amount' => "required|numeric|min:1|max:{$refundable}",
            'reason' => 'required|string|max:255',
        ]);

        DB::transaction(function () use ($booking, $data, $request) {
            $wallet = Wallet::lockForUpdate()->firstOrCreate(
                ['patient_id' => $booking->patient_id],
                ['balance' => 0, 'currency' => 'NGN', 'status' => 'active'],
            );
            $before = (float) $wallet->balance;
            $after  = round($before + $data['amount'], 2);

            $wallet->update(['balance' => $after]);
            $wallet->transactions()->create([
                'type' => 'credit', 'amount' => $data['amount'], 'balance_before' => $before, 'balance_after' => $after,
                'reference' => 'RFD-' . strtoupper(Str::random(12)),
                'description' => "Refund for booking {$booking->reference}: {$data['reason']}",
                'source' => 'refund', 'status' => 'success',
                'meta' => ['booking_id' => $booking->id, 'admin' => $request->user()->email],
            ]);
        });

        return response()->json(['success' => true, 'message' => 'Refund credited to the patient\'s wallet.']);
    }

    /** What the patient paid minus refunds already given for this booking. */
    private function refundable(Booking $booking): float
    {
        $refunded = WalletTransaction::where('source', 'refund')->where('meta->booking_id', $booking->id)->sum('amount');

        return round($booking->amount_paid - $refunded, 2);
    }

    public function patients(Request $request)
    {
        $patients = Patient::withCount('bookings')
            ->addSelect(['wallet_balance' => Wallet::select('balance')->whereColumn('patient_id', 'patients.id')->limit(1)])
            ->when($request->search, fn($q, $s) => $q->where(fn($q) => $q
                ->where('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")))
            ->latest()
            ->paginate($this->perPage($request));

        return response()->json(['success' => true, 'data' => $patients]);
    }
}
