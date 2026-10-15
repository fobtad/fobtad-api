<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Driver;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\ProviderUser;
use App\Models\TowTruck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProviderPortalTest extends TestCase
{
    use RefreshDatabase;

    private Provider $provider;
    private ProviderUser $staff;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->makeProvider('a', ['latitude' => 6.44, 'longitude' => 3.47]);
        $this->staff    = ProviderUser::create([
            'provider_id' => $this->provider->id, 'first_name' => 'Staff', 'last_name' => 'A',
            'email' => 'staff-a@test.test', 'password' => Hash::make('secret123'), 'role' => 'admin', 'is_active' => true,
        ]);
        $this->patient = Patient::create([
            'first_name' => 'Taiwo', 'last_name' => 'Adebayo', 'email' => 'p@test.test',
            'phone' => '+2340000000001', 'password' => Hash::make('secret123'),
        ]);
    }

    public function test_register_creates_pending_provider_and_signs_in(): void
    {
        $res = $this->postJson('/api/v1/provider/register', [
            'provider' => [
                'company_name' => 'Rapid Recovery', 'business_type' => 'towing_company',
                'email' => 'ops@rapid.test', 'phone' => '0800', 'address' => 'Ikeja',
            ],
            'first_name' => 'Femi', 'last_name' => 'Ade', 'email' => 'femi@rapid.test',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated()
            ->assertJsonPath('data.user.provider.status', 'pending')
            ->assertJsonPath('data.user.provider.services', ['towing']);

        $this->withToken($res->json('data.token'))->getJson('/api/v1/provider/dashboard')->assertOk();
    }

    public function test_tokens_cannot_cross_portals(): void
    {
        Sanctum::actingAs($this->patient);
        $this->getJson('/api/v1/provider/jobs')->assertForbidden();

        Sanctum::actingAs($this->staff);
        $this->getJson('/api/v1/hospital/bookings')->assertForbidden();
        $this->getJson('/api/v1/bookings')->assertForbidden();
    }

    public function test_towing_goes_to_nearest_active_provider(): void
    {
        $ikeja = $this->makeProvider('ikeja', ['latitude' => 6.60, 'longitude' => 3.35]);
        $this->makeProvider('pending', ['latitude' => 6.43, 'longitude' => 3.49, 'status' => 'pending']);

        Sanctum::actingAs($this->patient);
        $payload = ['pickup_address' => 'Lekki', 'destination_address' => 'Mechanic', 'issue_type' => 'Breakdown',
            'truck_type' => 'flatbed', 'vehicle_make' => 'Toyota', 'vehicle_plate' => 'ABC123'];

        $id = $this->postJson('/api/v1/bookings/towing', [...$payload, 'pickup_lat' => 6.43, 'pickup_lng' => 3.49])
            ->assertCreated()->json('data.booking_id');
        $this->assertSame($this->provider->id, Booking::find($id)->provider_id);

        $id = $this->postJson('/api/v1/bookings/towing', [...$payload, 'pickup_lat' => 6.61, 'pickup_lng' => 3.34])
            ->assertCreated()->json('data.booking_id');
        $this->assertSame($ikeja->id, Booking::find($id)->provider_id);
    }

    public function test_full_job_lifecycle_and_ownership(): void
    {
        $job    = $this->makeJob();
        $driver = $this->makeDriver($this->provider, 'd');
        $truck  = $this->makeTruck($this->provider, 'TOW-1');
        $this->makeJob(['status' => 'completed', 'driver_id' => $driver->id, 'tow_truck_id' => $truck->id]);

        $other = $this->makeProvider('b');
        $otherDriver = $this->makeDriver($other, 'x');

        Sanctum::actingAs($this->staff);

        $this->putJson("/api/v1/provider/jobs/{$job->id}/accept", ['driver_id' => $otherDriver->id, 'tow_truck_id' => $truck->id])
            ->assertStatus(422)->assertJsonValidationErrors(['driver_id']);

        $this->putJson("/api/v1/provider/jobs/{$job->id}/accept", ['driver_id' => $driver->id, 'tow_truck_id' => $truck->id])
            ->assertOk()->assertJsonPath('data.status', 'assigned');
        $this->assertSame('on_job', $driver->fresh()->status);

        $this->getJson('/api/v1/provider/drivers')
            ->assertJsonPath('data.data.0.active_booking.tow_truck.plate_number', 'TOW-1');

        $this->putJson("/api/v1/provider/jobs/{$job->id}/status", ['status' => 'en_route'])->assertOk();
        $this->putJson("/api/v1/provider/jobs/{$job->id}/status", ['status' => 'arrived'])->assertOk();
        $this->putJson("/api/v1/provider/jobs/{$job->id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');

        $driver->refresh();
        $this->assertSame('available', $driver->status);
        $this->assertSame(1, $driver->total_jobs);
        $this->assertSame('available', $truck->fresh()->status);
    }

    public function test_dashboard_reports_settings_and_fleet(): void
    {
        $this->makeJob();
        Sanctum::actingAs($this->staff);

        $this->patient->update(['blood_group' => 'O+', 'allergies' => 'Penicillin']);
        $dash = $this->getJson('/api/v1/provider/dashboard')->assertOk()->assertJsonPath('data.stats.pending', 1);
        $this->assertArrayNotHasKey('blood_group', $dash->json('data.recent_jobs.0.patient'));
        $jobs = $this->getJson('/api/v1/provider/jobs')->assertOk();
        $this->assertSame(['id', 'first_name', 'last_name', 'phone'], array_keys($jobs->json('data.data.0.patient')));
        $this->getJson('/api/v1/provider/jobs/summary')->assertOk()->assertJsonPath('data.pending', 1);
        $this->getJson('/api/v1/provider/reports?period=year')->assertOk()->assertJsonPath('data.kpis.total_jobs', 1);

        $this->putJson('/api/v1/provider/profile', ['coverage_areas' => 'Lekki, VI', 'latitude' => 6.5, 'longitude' => 3.4])
            ->assertOk()->assertJsonPath('data.coverage_areas', 'Lekki, VI');

        $id = $this->postJson('/api/v1/provider/trucks', ['plate_number' => 'LAG-9', 'type' => 'heavy_recovery', 'capacity_tonnes' => 12])
            ->assertCreated()->json('data.id');
        $this->putJson("/api/v1/provider/trucks/{$id}", ['status' => 'maintenance'])
            ->assertOk()->assertJsonPath('data.flagged_for_maintenance', true);

        $this->postJson('/api/v1/provider/drivers', ['first_name' => 'Tayo', 'last_name' => 'O', 'email' => 't@t.test', 'phone' => '1', 'shift' => 'night', 'status' => 'standby'])
            ->assertCreated()->assertJsonPath('data.shift', 'night');
    }

    public function test_suspended_provider_is_locked_out_and_approval_page_works(): void
    {
        config(['services.fobtad.setup_key' => 'k']);
        $this->provider->update(['status' => 'pending']);

        $this->post('/api/setup/providers', ['key' => 'k'])->assertOk()->assertSee('Provider a');
        $this->post("/api/setup/providers/{$this->provider->id}/status", ['key' => 'k', 'status' => 'suspended'])->assertOk();

        $this->postJson('/api/v1/provider/login', ['email' => 'staff-a@test.test', 'password' => 'secret123'])->assertForbidden();
        Sanctum::actingAs($this->staff);
        $this->getJson('/api/v1/provider/dashboard')->assertForbidden();
    }

    public function test_patient_tracking_shows_tow_driver(): void
    {
        $job    = $this->makeJob(['pickup_latitude' => 6.43, 'pickup_longitude' => 3.49]);
        $driver = $this->makeDriver($this->provider, 'e');
        $truck  = $this->makeTruck($this->provider, 'TOW-2');

        Sanctum::actingAs($this->staff);
        $this->putJson("/api/v1/provider/jobs/{$job->id}/accept", ['driver_id' => $driver->id, 'tow_truck_id' => $truck->id])->assertOk();

        Sanctum::actingAs($this->patient);
        $this->getJson("/api/v1/bookings/{$job->reference}/location")
            ->assertOk()
            ->assertJsonPath('data.crew.role', 'Tow operator')
            ->assertJsonPath('data.vehicle.plate_number', 'TOW-2')
            ->assertJsonPath('data.responder.name', 'Provider a')
            ->assertJsonPath('data.vehicle_lat', 6.44);
    }

    // ── helpers ──

    private function makeProvider(string $key, array $overrides = []): Provider
    {
        return Provider::create([
            'company_name' => "Provider {$key}", 'email' => "p-{$key}@test.test", 'phone' => '0800',
            'address' => 'Lagos', 'status' => 'active', 'services' => ['towing'], ...$overrides,
        ]);
    }

    private function makeDriver(Provider $provider, string $key): Driver
    {
        return Driver::create([
            'provider_id' => $provider->id, 'first_name' => 'Driver', 'last_name' => $key,
            'email' => "driver-{$key}@test.test", 'phone' => '0801', 'status' => 'available',
        ]);
    }

    private function makeTruck(Provider $provider, string $plate): TowTruck
    {
        return TowTruck::create(['provider_id' => $provider->id, 'plate_number' => $plate, 'type' => 'flatbed', 'status' => 'available']);
    }

    private function makeJob(array $overrides = []): Booking
    {
        return Booking::create([
            'patient_id' => $this->patient->id, 'provider_id' => $this->provider->id,
            'type' => 'towing', 'status' => 'pending', 'pickup_address' => 'Lekki',
            'incident_type' => 'Breakdown', ...$overrides,
        ]);
    }
}
