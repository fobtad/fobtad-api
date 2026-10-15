<?php
// app/Models/Hospital.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Hospital extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'facility_type', 'email', 'phone', 'website', 'address', 'lga', 'state',
        'latitude', 'longitude', 'licence_number', 'cac_number', 'fleet_size',
        'status', 'logo', 'description', 'bank_name', 'account_number', 'account_name',
    ];

    protected $casts = [
        'latitude'   => 'float',
        'longitude'  => 'float',
        'fleet_size' => 'integer',
    ];

    /**
     * The active hospital closest to the pickup point. Hospitals without
     * coordinates are only used when none have them (or no pickup point given).
     */
    public static function nearestActive(?float $lat, ?float $lng): ?self
    {
        $active = static::where('status', 'active');

        if ($lat !== null && $lng !== null) {
            $nearest = (clone $active)
                ->whereNotNull('latitude')->whereNotNull('longitude')
                ->get()
                ->sortBy(fn($h) => static::distanceKm($lat, $lng, $h->latitude, $h->longitude))
                ->first();

            if ($nearest) {
                return $nearest;
            }
        }

        return $active->oldest('id')->first();
    }

    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function users()
    {
        return $this->hasMany(HospitalUser::class);
    }

    public function paramedics()
    {
        return $this->hasMany(Paramedic::class);
    }

    public function ambulances()
    {
        return $this->hasMany(Ambulance::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function availableParamedics()
    {
        return $this->hasMany(Paramedic::class)
            ->where('status', 'available');
    }

    public function availableAmbulances()
    {
        return $this->hasMany(Ambulance::class)
            ->where('status', 'available')
            ->where('flagged_for_maintenance', false);
    }
}