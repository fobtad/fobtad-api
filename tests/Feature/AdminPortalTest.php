<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\Earning;
use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\Patient;
use App\Models\Payout;
use App\Models\PlatformSetting;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPortalTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'super_admin'): Admin
    {
        return Admin::create(['first_name' => 'A', 'last_name' => $role, 'email' => "{$role}@fobtad.test", 'password' => Hash::make('secret12345'), 'role' => $role]);
    }

    private function patient(): Patient
    {
        return Patient::create(['first_name' => 'P', 'last_name' => 'A', 'email' => 'p@t.test', 'phone' => '01', 'password' => 'x']);
    }

    public function test_login_and_only_admins_can_use_admin_routes(): void
    {
        $this->admin();
        $token = $this->postJson('/api/v1/admin/login', ['email' => 'super_admin@fobtad.test', 'password' => 'secret12345'])
            ->assertOk()->json('data.token');
        $this->withToken($token)->getJson('/api/v1/admin/dashboard')->assertOk();

        Sanctum::actingAs($this->patient());
        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();

        $hospital = Hospital::create(['name' => 'H', 'email' => 'h@t.test', 'phone' => '1', 'address' => 'x', 'status' => 'active']);
        Sanctum::actingAs(HospitalUser::create(['hospital_id' => $hospital->id, 'first_name' => 'S', 'last_name' => 'A', 'email' => 's@t.test', 'password' => 'x', 'role' => 'admin']));
        $this->getJson('/api/v1/admin/bookings')->assertForbidden();
    }

    public function test_dashboard_flags_unassigned_bookings_and_admin_can_reassign(): void
    {
        $patient = $this->patient();
        $booking = Booking::create(['patient_id' => $patient->id, 'type' => 'emergency', 'status' => 'pending', 'pickup_address' => 'VI']);
        $hospital = Hospital::create(['name' => 'H', 'email' => 'h@t.test', 'phone' => '1', 'address' => 'x', 'status' => 'pending']);

        Sanctum::actingAs($this->admin('admin'));
        $this->getJson('/api/v1/admin/dashboard')->assertJsonPath('data.bookings.unassigned', 1);

        $this->putJson("/api/v1/admin/bookings/{$booking->id}/reassign", ['hospital_id' => $hospital->id])->assertStatus(422); // not approved
        $this->putJson("/api/v1/admin/hospitals/{$hospital->id}/status", ['status' => 'active'])->assertOk();
        $this->putJson("/api/v1/admin/bookings/{$booking->id}/reassign", ['hospital_id' => $hospital->id])->assertOk();

        $this->assertSame($hospital->id, $booking->fresh()->hospital_id);
        $this->getJson('/api/v1/admin/bookings?unassigned=1')->assertJsonPath('data.total', 0);
    }

    public function test_refund_goes_to_wallet_and_is_capped(): void
    {
        $patient = $this->patient();
        $wallet  = Wallet::create(['patient_id' => $patient->id, 'balance' => 0, 'currency' => 'NGN', 'status' => 'active']);
        $booking = Booking::create(['patient_id' => $patient->id, 'type' => 'emergency', 'status' => 'completed', 'pickup_address' => 'VI', 'fare' => 10000, 'amount_paid' => 10000, 'payment_status' => 'paid']);

        Sanctum::actingAs($this->admin('admin'));
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/refund", ['amount' => 6000, 'reason' => 'Late arrival'])->assertOk();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/refund", ['amount' => 5000, 'reason' => 'Again'])->assertStatus(422);
        $this->getJson("/api/v1/admin/bookings/{$booking->id}")->assertJsonPath('data.refundable', 4000);

        $this->assertEquals(6000, $wallet->fresh()->balance);
    }

    public function test_payout_paid_or_rejected(): void
    {
        $hospital = Hospital::create(['name' => 'H', 'email' => 'h@t.test', 'phone' => '1', 'address' => 'x', 'status' => 'active']);
        $patient  = $this->patient();
        $booking  = Booking::create(['patient_id' => $patient->id, 'hospital_id' => $hospital->id, 'type' => 'emergency', 'status' => 'completed', 'pickup_address' => 'VI', 'fare' => 10000]);
        $payout   = Payout::create(['reference' => 'PO-1', 'payee_type' => 'hospital', 'payee_id' => $hospital->id, 'amount' => 9000, 'bank_name' => 'GTB', 'account_number' => '0123456789', 'account_name' => 'H']);
        Earning::create(['booking_id' => $booking->id, 'payee_type' => 'hospital', 'payee_id' => $hospital->id, 'gross' => 10000, 'commission_percent' => 10, 'commission' => 1000, 'net' => 9000, 'payout_id' => $payout->id]);

        Sanctum::actingAs($this->admin('support'));
        $this->putJson("/api/v1/admin/payouts/{$payout->id}", ['status' => 'paid'])->assertForbidden();

        Sanctum::actingAs($this->admin('admin'));
        $this->getJson('/api/v1/admin/payouts')->assertJsonPath('data.data.0.payee_name', 'H');
        $this->putJson("/api/v1/admin/payouts/{$payout->id}", ['status' => 'rejected'])->assertStatus(422); // note required
        $this->putJson("/api/v1/admin/payouts/{$payout->id}", ['status' => 'rejected', 'admin_note' => 'Wrong account'])->assertOk();

        $this->assertNull(Earning::first()->payout_id); // back to available
        $this->putJson("/api/v1/admin/payouts/{$payout->id}", ['status' => 'paid'])->assertNotFound(); // already processed
    }

    public function test_settings_only_super_admin_and_partial_rates_merge(): void
    {
        Sanctum::actingAs($this->admin('admin'));
        $this->putJson('/api/v1/admin/settings', ['commission_percent' => 12])->assertForbidden();

        Sanctum::actingAs($this->admin('super_admin'));
        $this->putJson('/api/v1/admin/settings', [
            'commission_percent' => 12,
            'rates' => ['flatbed' => ['base' => 9000, 'per_km' => 450, 'floor' => 14000]],
        ])->assertOk();

        $this->assertEquals(12, PlatformSetting::get('commission_percent'));
        $this->assertEquals(9000, PlatformSetting::get('rates')['flatbed']['base']);
        $this->assertEquals(5000, PlatformSetting::get('rates')['advanced']['base']); // untouched

        $this->putJson('/api/v1/admin/settings', ['rates' => ['bogus' => ['base' => 1, 'per_km' => 1, 'floor' => 1]]])->assertStatus(422);
    }

    public function test_setup_page_creates_first_admin_as_super_admin(): void
    {
        config(['services.fobtad.setup_key' => 'k']);
        $form = ['first_name' => 'O', 'last_name' => 'O', 'email' => 'o@fobtad.test', 'password' => 'longpassword1'];

        $this->post('/api/setup/admin', [...$form, 'key' => 'bad'])->assertForbidden();
        $this->post('/api/setup/admin', [...$form, 'key' => 'k'])->assertCreated();
        $this->post('/api/setup/admin', [...$form, 'email' => 'b@fobtad.test', 'key' => 'k'])->assertCreated();

        $this->assertSame('super_admin', Admin::where('email', 'o@fobtad.test')->value('role'));
        $this->assertSame('admin', Admin::where('email', 'b@fobtad.test')->value('role'));
    }
}
