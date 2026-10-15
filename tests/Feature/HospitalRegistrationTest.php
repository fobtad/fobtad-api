<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HospitalRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return array_replace_recursive([
            'hospital' => [
                'name' => 'Lekki Care Hospital', 'facility_type' => 'private',
                'email' => 'info@lekkicare.test', 'phone' => '0800', 'address' => 'Admiralty Way, Lekki',
                'licence_number' => 'MDCN/H/123', 'latitude' => 6.44, 'longitude' => 3.47,
            ],
            'first_name' => 'Ngozi', 'last_name' => 'Obi', 'email' => 'ngozi@lekkicare.test',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ], $overrides);
    }

    public function test_registration_creates_pending_hospital_and_signs_in(): void
    {
        $res = $this->postJson('/api/v1/hospital/register', $this->registration())
            ->assertCreated()
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonPath('data.user.hospital.status', 'pending');

        $this->withToken($res->json('data.token'))->getJson('/api/v1/hospital/dashboard')->assertOk();
    }

    public function test_registration_validates_duplicates_and_password(): void
    {
        $this->postJson('/api/v1/hospital/register', $this->registration())->assertCreated();

        $this->postJson('/api/v1/hospital/register', $this->registration(['password_confirmation' => 'nope']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['hospital.email', 'email', 'password']);
    }

    public function test_bookings_go_to_nearest_active_hospital_only(): void
    {
        $patient = Patient::create(['first_name' => 'T', 'last_name' => 'A', 'email' => 'p@t.test', 'phone' => '01', 'password' => Hash::make('x')]);
        $ikeja   = Hospital::create(['name' => 'Ikeja', 'email' => 'i@t.test', 'phone' => '1', 'address' => 'Ikeja', 'status' => 'active', 'latitude' => 6.60, 'longitude' => 3.35]);
        $lekki   = Hospital::create(['name' => 'Lekki', 'email' => 'l@t.test', 'phone' => '2', 'address' => 'Lekki', 'status' => 'active', 'latitude' => 6.44, 'longitude' => 3.47]);
        Hospital::create(['name' => 'Closer but pending', 'email' => 'c@t.test', 'phone' => '3', 'address' => 'VI', 'status' => 'pending', 'latitude' => 6.43, 'longitude' => 3.49]);

        Sanctum::actingAs($patient);

        $id = $this->postJson('/api/v1/bookings/emergency', ['latitude' => 6.43, 'longitude' => 3.49, 'incident_type' => 'accident'])
            ->assertCreated()->json('data.booking_id');
        $this->assertSame($lekki->id, \App\Models\Booking::find($id)->hospital_id);

        $id = $this->postJson('/api/v1/bookings/emergency', ['latitude' => 6.61, 'longitude' => 3.34, 'incident_type' => 'accident'])
            ->assertCreated()->json('data.booking_id');
        $this->assertSame($ikeja->id, \App\Models\Booking::find($id)->hospital_id);
    }

    public function test_suspended_hospital_cannot_sign_in_or_use_portal(): void
    {
        $token = $this->postJson('/api/v1/hospital/register', $this->registration())->json('data.token');
        Hospital::first()->update(['status' => 'suspended']);

        $this->withToken($token)->getJson('/api/v1/hospital/dashboard')->assertForbidden();
        $this->postJson('/api/v1/hospital/login', ['email' => 'ngozi@lekkicare.test', 'password' => 'secret123'])->assertForbidden();
    }

    public function test_approval_page_requires_key_and_approves(): void
    {
        config(['services.fobtad.setup_key' => 'right-key']);
        $this->postJson('/api/v1/hospital/register', $this->registration())->assertCreated();
        $hospital = Hospital::first();

        $this->post('/api/setup/hospitals', ['key' => 'wrong'])->assertForbidden();
        $this->post('/api/setup/hospitals', ['key' => 'right-key'])->assertOk()->assertSee('Lekki Care Hospital');
        $this->post("/api/setup/hospitals/{$hospital->id}/status", ['key' => 'wrong', 'status' => 'active'])->assertNotFound();
        $this->post("/api/setup/hospitals/{$hospital->id}/status", ['key' => 'right-key', 'status' => 'active'])->assertOk();

        $this->assertSame('active', $hospital->fresh()->status);
    }
}
