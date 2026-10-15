<?php

namespace Tests\Feature;

use App\Models\Ambulance;
use App\Models\Booking;
use App\Models\Earning;
use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\Paramedic;
use App\Models\Patient;
use App\Models\PlatformSetting;
use App\Models\VirtualAccount;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;
    private HospitalUser $staff;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => 'sk_test_fake']);

        $this->hospital = Hospital::create(['name' => 'H', 'email' => 'h@t.test', 'phone' => '1', 'address' => 'Lagos', 'status' => 'active', 'latitude' => 6.45, 'longitude' => 3.40]);
        $this->staff = HospitalUser::create(['hospital_id' => $this->hospital->id, 'first_name' => 'S', 'last_name' => 'A', 'email' => 's@t.test', 'password' => Hash::make('secret123'), 'role' => 'admin', 'is_active' => true]);
        $this->patient = Patient::create(['first_name' => 'P', 'last_name' => 'A', 'email' => 'p@t.test', 'phone' => '01', 'password' => Hash::make('x')]);
    }

    private array $verifyData = [];
    private bool $faked = false;

    /** Fake Paystack: initialize returns a checkout URL; verify returns $verify. */
    private function fakePaystack(array $verify = []): void
    {
        $this->verifyData = $verify;
        if ($this->faked) {
            return;
        }
        $this->faked = true;

        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.test/x', 'reference' => 'r']]),
            'api.paystack.co/transaction/verify/*'   => fn() => Http::response(['status' => true, 'data' => $this->verifyData]),
        ]);
    }

    private function fundWallet(float $amount): Wallet
    {
        return Wallet::create(['patient_id' => $this->patient->id, 'balance' => $amount, 'currency' => 'NGN', 'status' => 'active']);
    }

    /** Create a booking with a fare and walk it to completion through the portal. */
    private function completeBooking(float $fare): Booking
    {
        $booking   = Booking::create(['patient_id' => $this->patient->id, 'hospital_id' => $this->hospital->id, 'type' => 'emergency', 'status' => 'pending', 'pickup_address' => 'VI', 'fare' => $fare]);
        $paramedic = Paramedic::create(['hospital_id' => $this->hospital->id, 'first_name' => 'M', 'last_name' => (string) $booking->id, 'email' => "m{$booking->id}@t.test", 'phone' => '1', 'status' => 'available']);
        $ambulance = Ambulance::create(['hospital_id' => $this->hospital->id, 'plate_number' => "AMB{$booking->id}", 'type' => 'standard', 'status' => 'available']);

        Sanctum::actingAs($this->staff);
        $this->putJson("/api/v1/hospital/bookings/{$booking->id}/accept", ['paramedic_id' => $paramedic->id, 'ambulance_id' => $ambulance->id])->assertOk();
        $this->putJson("/api/v1/hospital/bookings/{$booking->id}/complete")->assertOk();

        return $booking->fresh();
    }

    public function test_fare_is_computed_by_server_and_ignores_client_estimate(): void
    {
        Sanctum::actingAs($this->patient);
        $trip = ['pickup_lat' => 6.43, 'pickup_lng' => 3.42, 'destination_lat' => 6.50, 'destination_lng' => 3.38];

        $quote = $this->postJson('/api/v1/bookings/estimate', [...$trip, 'ride_type' => 'advanced'])->assertOk()->json('fare');

        $id = $this->postJson('/api/v1/bookings/scheduled', [...$trip, 'pickup_address' => 'A', 'destination_address' => 'B',
            'scheduled_at' => now()->addDay()->toIso8601String(), 'ride_type' => 'advanced',
            'estimated_min_price' => 1, 'estimated_max_price' => 2])->assertCreated()->json('data.booking_id');

        $this->assertEquals($quote, Booking::find($id)->fare);
        $this->assertGreaterThanOrEqual(15000, $quote); // advanced floor
    }

    public function test_rates_and_commission_are_configurable(): void
    {
        PlatformSetting::put('rates', [...PlatformSetting::get('rates'), 'flatbed' => ['base' => 20000, 'per_km' => 0, 'floor' => 0]]);
        PlatformSetting::put('commission_percent', 15);

        Sanctum::actingAs($this->patient);
        \App\Models\Provider::create(['company_name' => 'T', 'email' => 't@t.test', 'phone' => '1', 'address' => 'x', 'status' => 'active', 'services' => ['towing']]);
        $id = $this->postJson('/api/v1/bookings/towing', ['pickup_address' => 'A', 'destination_address' => 'B', 'issue_type' => 'Breakdown',
            'truck_type' => 'flatbed', 'vehicle_make' => 'Kia', 'vehicle_plate' => 'X1'])->assertCreated()->json('data.booking_id');
        $this->assertEquals(20000, Booking::find($id)->fare);

        $this->fundWallet(50000);
        $booking = $this->completeBooking(10000);
        $this->assertEquals(1500, $booking->earning->commission);
        $this->assertEquals(8500, $booking->earning->net);
    }

    public function test_completion_charges_wallet_and_credits_hospital(): void
    {
        $wallet  = $this->fundWallet(20000);
        $booking = $this->completeBooking(12000);

        $this->assertSame('paid', $booking->payment_status);
        $this->assertEquals(8000, $wallet->fresh()->balance);
        $this->assertEquals(10800, Earning::where('booking_id', $booking->id)->value('net')); // 10% commission
    }

    public function test_shortfall_is_paid_by_card_once(): void
    {
        $this->fundWallet(5000);
        $booking = $this->completeBooking(12000);
        $this->assertSame('partially_paid', $booking->payment_status);
        $this->assertEquals(7000, $booking->amount_due);
        $this->assertNull($booking->earning);

        Sanctum::actingAs($this->patient);
        $this->fakePaystack();
        $ref = $this->postJson("/api/v1/bookings/{$booking->id}/pay", ['amount' => 1])->assertOk()
            ->assertJsonPath('data.amount', 7000)->json('data.reference');

        // Wrong amount from Paystack is rejected.
        $this->fakePaystack(['status' => 'success', 'amount' => 100, 'currency' => 'NGN', 'reference' => $ref]);
        $this->getJson("/api/v1/payments/verify/{$ref}")->assertStatus(400);

        $this->fakePaystack(['status' => 'success', 'amount' => 700000, 'currency' => 'NGN', 'reference' => $ref, 'channel' => 'card']);
        $this->getJson("/api/v1/payments/verify/{$ref}")->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->getJson("/api/v1/payments/verify/{$ref}")->assertOk(); // repeat is harmless

        $booking->refresh();
        $this->assertEquals(12000, $booking->amount_paid);
        $this->assertSame(1, Earning::where('booking_id', $booking->id)->count());
    }

    public function test_wallet_funding_credits_once_and_only_for_owner(): void
    {
        $this->fundWallet(0);
        Sanctum::actingAs($this->patient);
        $this->fakePaystack();
        $ref = $this->postJson('/api/v1/wallet/fund', ['amount' => 5000])->assertOk()->json('data.reference');

        $this->fakePaystack(['status' => 'success', 'amount' => 500000, 'currency' => 'NGN', 'reference' => $ref]);
        $this->getJson("/api/v1/wallet/verify/{$ref}")->assertOk()->assertJsonPath('data.balance', 5000);
        $this->getJson("/api/v1/wallet/verify/{$ref}")->assertOk()->assertJsonPath('data.balance', 5000);

        $other = Patient::create(['first_name' => 'O', 'last_name' => 'X', 'email' => 'o@t.test', 'phone' => '02', 'password' => 'x']);
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/wallet/verify/{$ref}")->assertStatus(400)->assertJsonMissingPath('data.balance');
    }

    public function test_webhook_requires_signature_and_completes_topup(): void
    {
        $wallet = $this->fundWallet(0);
        Sanctum::actingAs($this->patient);
        $this->fakePaystack();
        $ref = $this->postJson('/api/v1/wallet/fund', ['amount' => 2500])->json('data.reference');

        $body = json_encode(['event' => 'charge.success', 'data' => ['status' => 'success', 'amount' => 250000, 'currency' => 'NGN', 'reference' => $ref]]);

        $this->call('POST', '/api/v1/wallet/webhook', [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => 'bad', 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);
        $this->assertEquals(0, $wallet->fresh()->balance);

        $sig = hash_hmac('sha512', $body, 'sk_test_fake');
        $this->call('POST', '/api/v1/wallet/webhook', [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->call('POST', '/api/v1/wallet/webhook', [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertEquals(2500, $wallet->fresh()->balance);
    }

    public function test_fake_virtual_accounts_are_disabled_and_hidden(): void
    {
        $wallet = $this->fundWallet(0);
        VirtualAccount::create(['wallet_id' => $wallet->id, 'account_number' => '0121234567', 'account_name' => 'X', 'bank_name' => 'Wema Bank', 'paystack_reference' => 'MOCK-ABC', 'customer_code' => 'MOCK-CUSTOMER', 'is_primary' => true, 'status' => 'active']);

        Sanctum::actingAs($this->patient);
        $this->getJson('/api/v1/wallet')->assertOk()->assertJsonCount(0, 'data.virtual_accounts');
        $this->postJson('/api/v1/wallet/virtual-account')->assertStatus(400);
    }

    public function test_cancellation_fee_applies_only_after_assignment(): void
    {
        PlatformSetting::put('cancellation_fee', 2000);
        $wallet = $this->fundWallet(10000);
        Sanctum::actingAs($this->patient);

        $free = Booking::create(['patient_id' => $this->patient->id, 'hospital_id' => $this->hospital->id, 'type' => 'emergency', 'status' => 'pending', 'pickup_address' => 'VI', 'fare' => 9000]);
        $this->putJson("/api/v1/bookings/{$free->id}/cancel")->assertOk();
        $this->assertEquals(10000, $wallet->fresh()->balance);

        $charged = Booking::create(['patient_id' => $this->patient->id, 'hospital_id' => $this->hospital->id, 'type' => 'emergency', 'status' => 'assigned', 'pickup_address' => 'VI', 'fare' => 9000]);
        $this->putJson("/api/v1/bookings/{$charged->id}/cancel")->assertOk();
        $this->assertEquals(8000, $wallet->fresh()->balance);
        $this->assertSame('paid', $charged->fresh()->payment_status);
    }

    public function test_weekly_payout_request(): void
    {
        $this->fundWallet(30000);
        $this->completeBooking(10000);
        $this->completeBooking(10000);

        Sanctum::actingAs($this->staff);
        $this->getJson('/api/v1/hospital/earnings')->assertOk()->assertJsonPath('data.available', 18000);

        $this->postJson('/api/v1/hospital/payouts')->assertStatus(422); // no bank yet
        $this->putJson('/api/v1/hospital/bank-account', ['bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'H Hospital'])->assertOk();

        $this->postJson('/api/v1/hospital/payouts')->assertCreated()->assertJsonPath('data.amount', 18000);
        $this->getJson('/api/v1/hospital/earnings')->assertJsonPath('data.available', 0)->assertJsonPath('data.pending_payout', 18000);

        $this->completeBooking(10000);
        $this->postJson('/api/v1/hospital/payouts')->assertStatus(422); // one per week
    }
}
