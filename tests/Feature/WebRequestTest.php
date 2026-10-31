<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Ambulance;
use App\Models\Booking;
use App\Models\Hospital;
use App\Models\Paramedic;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebRequestTest extends TestCase
{
    use RefreshDatabase;

    private const REQUEST = [
        'name' => 'Ada Obi', 'phone' => '+234 803 555 1212', 'incident_type' => 'accident',
        'latitude' => 6.4474, 'longitude' => 3.4723, 'address' => 'Admiralty Way, Lekki',
    ];

    private function admin(string $role = 'admin'): Admin
    {
        return Admin::create(['first_name' => 'A', 'last_name' => $role, 'email' => "{$role}@fobtad.test", 'password' => Hash::make('secret12345'), 'role' => $role]);
    }

    private function hospital(string $name, float $lat, float $lng, int $crews): Hospital
    {
        $h = Hospital::create(['name' => $name, 'email' => str($name)->slug() . '@t.test', 'phone' => '1', 'address' => 'x', 'status' => 'active', 'latitude' => $lat, 'longitude' => $lng]);
        for ($i = 0; $i < $crews; $i++) {
            Ambulance::create(['hospital_id' => $h->id, 'plate_number' => "LAG-{$h->id}{$i}", 'type' => 'standard', 'status' => 'available']);
            Paramedic::create(['hospital_id' => $h->id, 'first_name' => 'P', 'last_name' => "{$h->id}{$i}", 'email' => "p{$h->id}{$i}@t.test", 'phone' => "0809{$h->id}{$i}", 'role' => 'paramedic', 'status' => 'available']);
        }

        return $h;
    }

    public function test_request_goes_to_admin_not_a_hospital_and_repeat_taps_do_not_duplicate(): void
    {
        $this->hospital('Near', 6.448, 3.473, 1);

        $token = $this->postJson('/api/v1/web/emergency', self::REQUEST)->assertCreated()->json('data.token');
        $again = $this->postJson('/api/v1/web/emergency', [...self::REQUEST, 'phone' => '08035551212'])->assertOk()->json('data.token');

        $this->assertSame($token, $again);
        $booking = Booking::sole();
        $this->assertNull($booking->hospital_id);
        $this->assertSame('web', $booking->source);
        $this->assertSame('08035551212', $booking->patient->phone);
        $this->getJson("/api/v1/web/requests/{$token}")->assertOk()->assertJsonPath('data.stage', 'received');

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/admin/dashboard')
            ->assertJsonPath('data.bookings.web_requests', 1)
            ->assertJsonPath('data.bookings.unassigned', 0);
        $this->getJson('/api/v1/admin/bookings?source=web')->assertJsonMissingPath('data.data.0.tracking_token');
    }

    public function test_validation_needs_a_nigerian_number_and_a_location(): void
    {
        $this->postJson('/api/v1/web/emergency', [...self::REQUEST, 'phone' => '12345'])->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->postJson('/api/v1/web/emergency', [...self::REQUEST, 'latitude' => null, 'longitude' => null, 'address' => null])
            ->assertStatus(422)->assertJsonValidationErrors('address');
        $this->getJson('/api/v1/web/requests/not-a-token')->assertNotFound();
    }

    public function test_admin_sees_nearest_free_hospital_recommended_and_dispatch_prices_the_trip(): void
    {
        $busy = $this->hospital('Nearest but no crew', 6.4475, 3.4724, 0);
        $free = $this->hospital('Free crew', 6.43, 3.42, 1);
        $this->hospital('Far', 6.60, 3.35, 2);
        $token = $this->postJson('/api/v1/web/emergency', self::REQUEST)->json('data.token');
        $booking = Booking::sole();

        Sanctum::actingAs($this->admin());
        $options = $this->getJson("/api/v1/admin/bookings/{$booking->id}/hospital-options")->assertOk();
        $this->assertSame($free->id, $options->json('data.recommended_id'));
        $this->assertSame($busy->id, $options->json('data.hospitals.0.id'));

        $this->putJson("/api/v1/admin/bookings/{$booking->id}/dispatch", ['hospital_id' => $free->id])->assertOk();
        $booking->refresh();
        $this->assertSame($free->id, $booking->hospital_id);
        $this->assertNotNull($booking->confirmed_at);
        $this->assertGreaterThan(0, $booking->fare);

        $this->getJson("/api/v1/web/requests/{$token}")->assertJsonPath('data.stage', 'sent')->assertJsonPath('data.hospital.name', 'Free crew');
    }

    public function test_fake_request_can_block_the_number_and_support_cannot_act(): void
    {
        $this->postJson('/api/v1/web/emergency', self::REQUEST);
        $booking = Booking::sole();

        Sanctum::actingAs($this->admin('support'));
        $this->putJson("/api/v1/admin/bookings/{$booking->id}/reject", ['reason' => 'fake', 'block' => true])->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->putJson("/api/v1/admin/bookings/{$booking->id}/reject", ['reason' => 'fake', 'block' => true])->assertOk();
        $this->assertSame('cancelled', $booking->fresh()->status);

        $this->postJson('/api/v1/web/emergency', self::REQUEST)->assertForbidden();

        $this->putJson("/api/v1/admin/patients/{$booking->patient_id}/unblock")->assertOk();
        $this->postJson('/api/v1/web/emergency', self::REQUEST)->assertCreated();
    }

    public function test_signing_up_in_the_app_frees_a_guest_number(): void
    {
        $this->postJson('/api/v1/web/emergency', self::REQUEST);

        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@example.com', 'phone' => '08035551212', 'password' => 'password123',
        ])->assertCreated();

        $this->assertSame(2, Patient::count());
        $this->assertSame('ada@example.com', Patient::where('phone', '08035551212')->value('email'));
    }
}
