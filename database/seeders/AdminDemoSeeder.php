<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local/demo admin. Do not run in production.
 *   php artisan db:seed --class=AdminDemoSeeder
 * Login: admin@demo-fobtad.test / DemoAdmin2026
 */
class AdminDemoSeeder extends Seeder
{
    public function run(): void
    {
        Admin::updateOrCreate(['email' => 'admin@demo-fobtad.test'], [
            'first_name' => 'Demo',
            'last_name'  => 'Admin',
            'password'   => Hash::make('DemoAdmin2026'),
            'role'       => 'super_admin',
            'is_active'  => true,
        ]);
    }
}
