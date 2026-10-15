<?php
// app/Console/Commands/CreateAdmin.php
namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** php artisan fobtad:admin --role=super_admin */
class CreateAdmin extends Command
{
    protected $signature = 'fobtad:admin {--role=admin : super_admin, admin or support}';
    protected $description = 'Create a FOBTAD admin portal login';

    public function handle(): int
    {
        $email = $this->ask('Email');
        if (Admin::where('email', $email)->exists()) {
            $this->error('An admin with that email already exists.');
            return self::FAILURE;
        }

        $password = $this->secret('Password (leave blank to generate one)') ?: Str::password(14, symbols: false);

        Admin::create([
            'first_name' => $this->ask('First name'),
            'last_name'  => $this->ask('Last name'),
            'email'      => $email,
            'password'   => Hash::make($password),
            'role'       => $this->option('role'),
        ]);

        $this->info("Created {$this->option('role')} {$email}.");
        $this->line("Password: {$password}");

        return self::SUCCESS;
    }
}
