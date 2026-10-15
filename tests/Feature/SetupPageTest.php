<?php

namespace Tests\Feature;

use App\Models\HospitalUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupPageTest extends TestCase
{
    use RefreshDatabase;

    private array $form = [
        'hospital_name' => 'Test Hospital', 'hospital_email' => 'h@test.test',
        'hospital_phone' => '0800', 'hospital_address' => 'Lagos',
        'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@test.test',
        'password' => 'secret123',
    ];

    public function test_page_is_disabled_without_setup_key(): void
    {
        config(['services.fobtad.setup_key' => null]);

        $this->get('/api/setup/hospital-user')->assertNotFound();
        $this->post('/api/setup/hospital-user', [...$this->form, 'key' => ''])->assertNotFound();
    }

    public function test_wrong_key_is_rejected(): void
    {
        config(['services.fobtad.setup_key' => 'right-key']);

        $this->post('/api/setup/hospital-user', [...$this->form, 'key' => 'wrong'])->assertForbidden();
        $this->assertSame(0, HospitalUser::count());
    }

    public function test_creates_hospital_and_admin_who_can_log_in(): void
    {
        config(['services.fobtad.setup_key' => 'right-key']);

        $this->get('/api/setup/hospital-user')->assertOk()->assertSee('Create hospital login');
        $this->post('/api/setup/hospital-user', [...$this->form, 'key' => 'right-key'])->assertCreated();

        $this->postJson('/api/v1/hospital/login', ['email' => 'ada@test.test', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('data.user.hospital.name', 'Test Hospital');
    }
}
