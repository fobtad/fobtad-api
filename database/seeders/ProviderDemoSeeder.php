<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Driver;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\ProviderUser;
use App\Models\TowTruck;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local/demo data for the provider portal. Do not run in production.
 *   php artisan db:seed --class=ProviderDemoSeeder
 * Login: admin@demo-provider.test / DemoProvider2026
 */
class ProviderDemoSeeder extends Seeder
{
    public function run(): void
    {
        $provider = Provider::updateOrCreate(['email' => 'ops@demo-provider.test'], [
            'company_name'   => 'Demo Recovery Services',
            'business_type'  => 'towing_company',
            'services'       => ['towing'],
            'phone'          => '+234 802 000 0000',
            'address'        => '14 Apapa Road, Apapa',
            'lga'            => 'Apapa',
            'state'          => 'Lagos',
            'latitude'       => 6.4474,
            'longitude'      => 3.3903,
            'coverage_areas' => 'Apapa, Lagos Island, Victoria Island, Lekki, Surulere',
            'status'         => 'active',
        ]);

        ProviderUser::updateOrCreate(['email' => 'admin@demo-provider.test'], [
            'provider_id' => $provider->id,
            'first_name'  => 'Emeka',
            'last_name'   => 'Okafor',
            'password'    => Hash::make('DemoProvider2026'),
            'role'        => 'admin',
            'is_active'   => true,
        ]);

        $drivers = collect([
            ['Femi', 'Adeyemi', 'morning', 'available'],
            ['Tayo', 'Ogun', 'morning', 'available'],
            ['Chukwudi', 'Nwosu', 'afternoon', 'standby'],
            ['Lanre', 'Bello', 'night', 'off_duty'],
        ])->map(fn($d, $i) => Driver::updateOrCreate(['email' => strtolower($d[0]) . '@demo-provider.test'], [
            'provider_id'    => $provider->id,
            'first_name'     => $d[0],
            'last_name'      => $d[1],
            'phone'          => '+234 803 000 00' . str_pad($i, 2, '0', STR_PAD_LEFT),
            'licence_number' => 'LIC-00' . (1200 + $i),
            'shift'          => $d[2],
            'status'         => $d[3],
        ]));

        $trucks = collect([
            ['TOW-001', 'DAF', 'LF 45', 2021, 'flatbed', 5, 'available'],
            ['TOW-002', 'Mercedes', 'Actros', 2020, 'heavy_recovery', 18, 'available'],
            ['TOW-003', 'Iveco', 'Daily', 2022, 'wheel_lift', 3.5, 'available'],
            ['TOW-004', 'Ford', 'Transit', 2018, 'flatbed', 3, 'maintenance'],
        ])->map(fn($t) => TowTruck::updateOrCreate(['plate_number' => $t[0]], [
            'provider_id'             => $provider->id,
            'make'                    => $t[1],
            'model'                   => $t[2],
            'year'                    => $t[3],
            'type'                    => $t[4],
            'capacity_tonnes'         => $t[5],
            'status'                  => $t[6],
            'flagged_for_maintenance' => $t[6] === 'maintenance',
            'fuel_level'              => rand(20, 100),
            'last_service_at'         => now()->subDays(rand(10, 120)),
            'mileage_km'              => rand(20000, 120000),
        ]));

        $customer = Patient::updateOrCreate(['email' => 'motorist@demo-provider.test'], [
            'first_name'  => 'Kunle',
            'last_name'   => 'Afolabi',
            'phone'       => '+234 804 000 0001',
            'password'    => Hash::make('DemoPatient2026'),
            'is_verified' => true,
        ]);

        if (Booking::where('provider_id', $provider->id)->exists()) {
            return;
        }

        foreach ([['Accident recovery', 'Third Mainland Bridge'], ['Breakdown', 'Ozumba Mbadiwe Ave, VI']] as [$issue, $pickup]) {
            Booking::create([
                'patient_id'          => $customer->id,
                'provider_id'         => $provider->id,
                'type'                => 'towing',
                'status'              => 'pending',
                'pickup_address'      => $pickup,
                'destination_address' => 'Marina Mechanic Village',
                'incident_type'       => $issue,
                'ride_type'           => 'flatbed',
                'notes'               => 'Vehicle: Toyota Camry LAG-123-AB',
            ]);
        }

        foreach (range(1, 18) as $i) {
            $created  = now()->subDays(rand(1, 60))->subMinutes(rand(0, 600));
            $accepted = $created->copy()->addMinutes(rand(2, 8));
            $driver   = $drivers->random();
            $job = Booking::create([
                'patient_id'     => $customer->id,
                'provider_id'    => $provider->id,
                'driver_id'      => $driver->id,
                'tow_truck_id'   => $trucks->random()->id,
                'type'           => 'towing',
                'status'         => $i % 7 === 0 ? 'cancelled' : 'completed',
                'pickup_address' => "Demo pickup {$i}, Lagos",
                'incident_type'  => collect(['Accident recovery', 'Breakdown', 'Flat tyre'])->random(),
                'accepted_at'    => $accepted,
                'completed_at'   => $i % 7 === 0 ? null : $accepted->copy()->addMinutes(rand(30, 90)),
                'cancelled_at'   => $i % 7 === 0 ? $accepted : null,
                'rating'         => $i % 7 === 0 ? null : rand(4, 5),
            ]);
            $job->forceFill(['created_at' => $created])->save();

            if ($job->status === 'completed') {
                $job->trip()->create([
                    'status'           => 'completed',
                    'dispatched_at'    => $accepted,
                    'arrived_at'       => $accepted->copy()->addMinutes(rand(10, 25)),
                    'completed_at'     => $job->completed_at,
                    'duration_minutes' => $accepted->diffInMinutes($job->completed_at),
                ]);
                $driver->increment('total_jobs');
            }
        }
    }
}
