<?php

namespace Tests\Feature;

use App\Models\Ambulance;
use App\Models\Booking;
use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\Paramedic;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HospitalPortalTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;
    private HospitalUser $staff;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hospital = $this->makeHospital('a');
        $this->staff    = $this->makeStaff($this->hospital, 'a');
        $this->patient  = Patient::create([
            'first_name' => 'Taiwo', 'last_name' => 'Adebayo',
            'email' => 'p@test.test', 'phone' => '+2340000000001',
            'password' => Hash::make('secret123'),
        ]);
    }

    public function test_hospital_login_returns_token_and_me_works(): void
    {
        $token = $this->postJson('/api/v1/hospital/login', [
            'email' => 'staff-a@test.test', 'password' => 'secret123',
        ])->assertOk()->json('data.token');

        $this->withToken($token)->getJson('/api/v1/hospital/me')
            ->assertOk()
            ->assertJsonPath('data.hospital.id', $this->hospital->id);
    }

    public function test_patient_token_cannot_use_hospital_routes(): void
    {
        Sanctum::actingAs($this->patient);

        $this->getJson('/api/v1/hospital/dashboard')->assertForbidden();
        $this->getJson('/api/v1/hospital/bookings')->assertForbidden();
    }

    public function test_hospital_token_cannot_use_patient_routes(): void
    {
        Sanctum::actingAs($this->staff);

        $this->getJson('/api/v1/bookings')->assertForbidden();
        $this->getJson('/api/v1/provider/jobs')->assertForbidden();
    }

    public function test_cannot_assign_another_hospitals_crew(): void
    {
        $other     = $this->makeHospital('b');
        $booking   = $this->makeBooking();
        $paramedic = $this->makeParamedic($other, 'x');
        $ambulance = $this->makeAmbulance($other, 'OTHER-1');

        Sanctum::actingAs($this->staff);

        $this->putJson("/api/v1/hospital/bookings/{$booking->id}/accept", [
            'paramedic_id' => $paramedic->id,
            'ambulance_id' => $ambulance->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['paramedic_id']);

        $this->assertSame('pending', $booking->fresh()->status);
    }

    public function test_full_booking_lifecycle_marks_and_frees_crew(): void
    {
        $booking   = $this->makeBooking();
        $paramedic = $this->makeParamedic($this->hospital, 'y');
        $ambulance = $this->makeAmbulance($this->hospital, 'OWN-1');
        // An older finished job with a higher id must not hide the active one.
        $this->makeBooking(['status' => 'completed', 'paramedic_id' => $paramedic->id, 'ambulance_id' => $ambulance->id]);

        Sanctum::actingAs($this->staff);

        $this->putJson("/api/v1/hospital/bookings/{$booking->id}/accept", [
            'paramedic_id' => $paramedic->id,
            'ambulance_id' => $ambulance->id,
        ])->assertOk()->assertJsonPath('data.status', 'assigned');

        $this->assertSame('on_trip', $paramedic->fresh()->status);
        $this->assertSame('on_trip', $ambulance->fresh()->status);

        // Busy crew can't be double-booked.
        $second = $this->makeBooking();
        $this->putJson("/api/v1/hospital/bookings/{$second->id}/accept", [
            'paramedic_id' => $paramedic->id,
            'ambulance_id' => $ambulance->id,
        ])->assertStatus(422);

        // Paramedics list shows the vehicle while on a trip.
        $this->getJson('/api/v1/hospital/paramedics')
            ->assertOk()
            ->assertJsonPath('data.data.0.active_booking.ambulance.plate_number', 'OWN-1');

        $this->putJson("/api/v1/hospital/bookings/{$booking->id}/status", ['status' => 'arrived'])
            ->assertNotFound(); // must go en_route first

        $this->putJson("/api/v1/hospital/bookings/{$booking->id}/status", ['status' => 'en_route'])
            ->assertOk()->assertJsonPath('data.status', 'en_route');
        $this->putJson("/api/v1/hospital/bookings/{$booking->id}/status", ['status' => 'arrived'])
            ->assertOk()->assertJsonPath('data.status', 'arrived');
        $this->putJson("/api/v1/hospital/bookings/{$booking->id}/complete")
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $paramedic->refresh();
        $this->assertSame('available', $paramedic->status);
        $this->assertSame(1, $paramedic->total_trips);
        $this->assertSame('available', $ambulance->fresh()->status);
        $this->assertNotNull($booking->fresh()->trip->arrived_at);
    }

    public function test_patient_cancel_frees_assigned_crew(): void
    {
        $booking   = $this->makeBooking();
        $paramedic = $this->makeParamedic($this->hospital, 'z');
        $ambulance = $this->makeAmbulance($this->hospital, 'OWN-2');

        Sanctum::actingAs($this->staff);
        $this->putJson("/api/v1/hospital/bookings/{$booking->id}/accept", [
            'paramedic_id' => $paramedic->id, 'ambulance_id' => $ambulance->id,
        ])->assertOk();

        Sanctum::actingAs($this->patient);
        $this->putJson("/api/v1/bookings/{$booking->id}/cancel")->assertOk();

        $this->assertSame('available', $paramedic->fresh()->status);
        $this->assertSame('available', $ambulance->fresh()->status);
    }

    public function test_bookings_list_search_and_summary(): void
    {
        $this->makeBooking(['pickup_address' => '22 Allen Ave, Ikeja']);
        $this->makeBooking(['type' => 'mortuary']);

        Sanctum::actingAs($this->staff);

        $this->getJson('/api/v1/hospital/bookings?search=Allen')
            ->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/hospital/bookings?type=mortuary')
            ->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/hospital/bookings/summary')
            ->assertOk()->assertJsonPath('data.pending', 2);
    }

    public function test_dashboard_and_reports_respond(): void
    {
        $this->makeBooking();
        Sanctum::actingAs($this->staff);

        $this->getJson('/api/v1/hospital/dashboard')
            ->assertOk()->assertJsonPath('data.stats.pending', 1);

        foreach (['week', 'month', 'year'] as $period) {
            $this->getJson("/api/v1/hospital/reports?period={$period}")
                ->assertOk()->assertJsonPath('data.kpis.total_trips', 1);
        }
    }

    public function test_paramedic_and_fleet_crud_with_new_fields(): void
    {
        Sanctum::actingAs($this->staff);

        $id = $this->postJson('/api/v1/hospital/paramedics', [
            'first_name' => 'Ngozi', 'last_name' => 'Obi', 'email' => 'ngozi@test.test',
            'phone' => '+2348000000000', 'role' => 'senior_paramedic', 'shift' => 'night', 'status' => 'standby',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/hospital/paramedics/{$id}", ['shift' => 'morning'])
            ->assertOk()->assertJsonPath('data.shift', 'morning');

        $amb = $this->postJson('/api/v1/hospital/fleet', [
            'plate_number' => 'LAG-123', 'make' => 'Toyota', 'model' => 'HiAce',
            'year' => 2022, 'type' => 'advanced', 'mileage_km' => 1000,
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/hospital/fleet/{$amb}", ['status' => 'maintenance'])
            ->assertOk()->assertJsonPath('data.flagged_for_maintenance', true);
    }

    public function test_settings_update_and_password_change(): void
    {
        Sanctum::actingAs($this->staff);

        $this->putJson('/api/v1/hospital/profile', ['website' => 'https://example.test', 'facility_type' => 'private'])
            ->assertOk()->assertJsonPath('data.facility_type', 'private');

        $this->putJson('/api/v1/hospital/password', [
            'current_password' => 'wrong', 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertStatus(422);
    }

    // ── helpers ──

    private function makeHospital(string $key): Hospital
    {
        return Hospital::create([
            'name' => "Hospital {$key}", 'email' => "h-{$key}@test.test",
            'phone' => '0800', 'address' => 'Lagos', 'status' => 'active',
        ]);
    }

    private function makeStaff(Hospital $hospital, string $key): HospitalUser
    {
        return HospitalUser::create([
            'hospital_id' => $hospital->id, 'first_name' => 'Staff', 'last_name' => $key,
            'email' => "staff-{$key}@test.test", 'password' => Hash::make('secret123'),
            'role' => 'admin', 'is_active' => true,
        ]);
    }

    private function makeParamedic(Hospital $hospital, string $key): Paramedic
    {
        return Paramedic::create([
            'hospital_id' => $hospital->id, 'first_name' => 'Medic', 'last_name' => $key,
            'email' => "medic-{$key}@test.test", 'phone' => '0801', 'status' => 'available',
        ]);
    }

    private function makeAmbulance(Hospital $hospital, string $plate): Ambulance
    {
        return Ambulance::create([
            'hospital_id' => $hospital->id, 'plate_number' => $plate,
            'type' => 'standard', 'status' => 'available',
        ]);
    }

    private function makeBooking(array $overrides = []): Booking
    {
        return Booking::create([
            'patient_id' => $this->patient->id, 'hospital_id' => $this->hospital->id,
            'type' => 'emergency', 'status' => 'pending', 'pickup_address' => 'Victoria Island',
            ...$overrides,
        ]);
    }
}
