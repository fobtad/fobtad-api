<?php
// app/Console/Commands/CreateHospitalUser.php
namespace App\Console\Commands;

use App\Models\Hospital;
use App\Models\HospitalUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Onboards a hospital portal login until the admin portal exists.
 *   php artisan fobtad:hospital-user
 */
class CreateHospitalUser extends Command
{
    protected $signature = 'fobtad:hospital-user
        {--hospital= : Existing hospital ID (omit to create a new hospital)}
        {--role=admin : admin, manager or staff}';

    protected $description = 'Create a hospital portal user (and optionally the hospital itself)';

    public function handle(): int
    {
        $hospital = $this->option('hospital')
            ? Hospital::findOrFail($this->option('hospital'))
            : $this->createHospital();

        $email = $this->ask('User email');
        if (HospitalUser::where('email', $email)->exists()) {
            $this->error('A hospital user with that email already exists.');
            return self::FAILURE;
        }

        $password = $this->secret('Password (leave blank to generate one)') ?: Str::password(12, symbols: false);

        $user = HospitalUser::create([
            'hospital_id' => $hospital->id,
            'first_name'  => $this->ask('First name'),
            'last_name'   => $this->ask('Last name'),
            'email'       => $email,
            'phone'       => $this->ask('Phone (optional)') ?: null,
            'password'    => Hash::make($password),
            'role'        => $this->option('role'),
            'is_active'   => true,
        ]);

        $this->info("Created {$user->role} {$user->email} for {$hospital->name} (hospital #{$hospital->id}).");
        $this->line("Password: {$password}");

        return self::SUCCESS;
    }

    private function createHospital(): Hospital
    {
        return Hospital::create([
            'name'      => $this->ask('Hospital name'),
            'email'     => $this->ask('Hospital email'),
            'phone'     => $this->ask('Hospital phone'),
            'address'   => $this->ask('Hospital address'),
            'lga'       => $this->ask('LGA (optional)') ?: null,
            'latitude'  => $this->ask('Latitude (optional)') ?: null,
            'longitude' => $this->ask('Longitude (optional)') ?: null,
            'status'    => 'active',
        ]);
    }
}
