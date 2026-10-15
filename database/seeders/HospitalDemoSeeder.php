<?php

namespace Database\Seeders;

use App\Models\Ambulance;
use App\Models\Booking;
use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\Paramedic;
use App\Models\Patient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local/demo data for the hospital portal. Do not run in production.
 *   php artisan db:seed --class=HospitalDemoSeeder
 * Login: admin@demo-hospital.test / DemoHospital2026
 */
class HospitalDemoSeeder extends Seeder
{
    public function run(): void
    {
        $hospital = Hospital::updateOrCreate(['email' => 'info@demo-hospital.test'], [
            'name'           => 'Lagos Island Demo Hospital',
            'facility_type'  => 'government',
            'phone'          => '+234 801 000 0000',
            'address'        => '1 Broad Street, Lagos Island',
            'lga'            => 'Lagos Island',
            'state'          => 'Lagos',
            'latitude'       => 6.4541,
            'longitude'      => 3.3947,
            'licence_number' => 'MDCN/H/0001',
            'status'         => 'active',
        ]);

        HospitalUser::updateOrCreate(['email' => 'admin@demo-hospital.test'], [
            'hospital_id' => $hospital->id,
            'first_name'  => 'Amara',
            'last_name'   => 'Okafor',
            'phone'       => '+234 801 111 1111',
            'password'    => Hash::make('DemoHospital2026'),
            'role'        => 'admin',
            'is_active'   => true,
        ]);

        $paramedics = collect([
            ['Emeka', 'Okonkwo', 'senior_paramedic', 'morning', 'available'],
            ['Adeola', 'Bello', 'paramedic', 'morning', 'available'],
            ['Chukwudi', 'Eze', 'emergency_technician', 'afternoon', 'standby'],
            ['Fatima', 'Sule', 'paramedic', 'night', 'off_duty'],
            ['Biodun', 'Adeyemi', 'senior_paramedic', 'afternoon', 'available'],
        ])->map(fn($p, $i) => Paramedic::updateOrCreate(
            ['email' => strtolower($p[0]) . '@demo-hospital.test'],
            [
                'hospital_id' => $hospital->id,
                'first_name'  => $p[0],
                'last_name'   => $p[1],
                'phone'       => '+234 802 000 00' . str_pad($i, 2, '0', STR_PAD_LEFT),
                'role'        => $p[2],
                'shift'       => $p[3],
                'status'      => $p[4],
            ]
        ));

        $ambulances = collect([
            ['DEMO-001', 'Toyota', 'HiAce', 2022, 'advanced', 'available'],
            ['DEMO-002', 'Mercedes', 'Sprinter', 2021, 'advanced', 'available'],
            ['DEMO-003', 'Toyota', 'HiAce', 2020, 'standard', 'available'],
            ['DEMO-004', 'Ford', 'Transit', 2019, 'wheelchair', 'maintenance'],
        ])->map(fn($a) => Ambulance::updateOrCreate(['plate_number' => $a[0]], [
            'hospital_id'             => $hospital->id,
            'make'                    => $a[1],
            'model'                   => $a[2],
            'year'                    => $a[3],
            'type'                    => $a[4],
            'status'                  => $a[5],
            'flagged_for_maintenance' => $a[5] === 'maintenance',
            'fuel_level'              => rand(30, 100),
            'last_service_at'         => now()->subDays(rand(10, 120)),
            'mileage_km'              => rand(15000, 90000),
        ]));

        $patient = Patient::updateOrCreate(['email' => 'patient@demo-hospital.test'], [
            'first_name'  => 'Taiwo',
            'last_name'   => 'Adebayo',
            'phone'       => '+234 803 000 0001',
            'password'    => Hash::make('DemoPatient2026'),
            'is_verified' => true,
        ]);

        if (Booking::where('hospital_id', $hospital->id)->exists()) {
            return;
        }

        $pending = [
            ['emergency', '5 Ozumba Mbadiwe Ave, Victoria Island', null, 'Chest pain', 'advanced'],
            ['scheduled', '14 Bode Thomas St, Surulere', 'LUTH, Idi-Araba', null, 'standard'],
            ['maternal', '3 Admiralty Way, Lekki Phase 1', 'Lagos Island Demo Hospital', null, 'standard'],
        ];

        foreach ($pending as [$type, $pickup, $destination, $incident, $rideType]) {
            Booking::create([
                'patient_id'          => $patient->id,
                'hospital_id'         => $hospital->id,
                'type'                => $type,
                'status'              => 'pending',
                'pickup_address'      => $pickup,
                'pickup_latitude'     => 6.43 + rand(0, 100) / 1000,
                'pickup_longitude'    => 3.42 + rand(0, 100) / 1000,
                'destination_address' => $destination,
                'incident_type'       => $incident,
                'ride_type'           => $rideType,
                'weeks_pregnant'      => $type === 'maternal' ? 36 : null,
                'notes'               => $type === 'scheduled' ? 'Weekly dialysis appointment.' : null,
                'scheduled_at'        => $type === 'scheduled' ? now()->addHours(3) : null,
            ]);
        }

        // History over the last few weeks so reports have something to show.
        foreach (range(1, 25) as $i) {
            $created  = now()->subDays(rand(1, 60))->subMinutes(rand(0, 600));
            $medic    = $paramedics->random();
            $vehicle  = $ambulances->random();
            $type     = collect(['emergency', 'emergency', 'scheduled', 'maternal', 'mortuary', 'recurring'])->random();
            $accepted = $created->copy()->addMinutes(rand(1, 4));

            $booking = Booking::create([
                'patient_id'     => $patient->id,
                'hospital_id'    => $hospital->id,
                'paramedic_id'   => $medic->id,
                'ambulance_id'   => $vehicle->id,
                'type'           => $type,
                'status'         => $i % 8 === 0 ? 'cancelled' : 'completed',
                'pickup_address' => 'Demo pickup address ' . $i . ', Lagos',
                'ride_type'      => 'standard',
                'accepted_at'    => $accepted,
                'completed_at'   => $i % 8 === 0 ? null : $accepted->copy()->addMinutes(rand(25, 70)),
                'cancelled_at'   => $i % 8 === 0 ? $accepted : null,
                'rating'         => $i % 8 === 0 ? null : rand(4, 5),
            ]);
            $booking->forceFill(['created_at' => $created])->save();

            if ($booking->status === 'completed') {
                $booking->trip()->create([
                    'status'        => 'completed',
                    'dispatched_at' => $accepted,
                    'arrived_at'    => $accepted->copy()->addMinutes(rand(4, 12)),
                    'completed_at'  => $booking->completed_at,
                    'distance_km'   => rand(30, 250) / 10,
                ]);
                $medic->increment('total_trips');
            }
        }
    }
}
