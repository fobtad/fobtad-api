<?php
// app/Models/Provider.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Provider extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_name', 'business_type', 'services', 'email', 'phone', 'address', 'lga', 'state',
        'latitude', 'longitude', 'cac_number', 'coverage_areas', 'description', 'fleet_size', 'status', 'logo',
    ];

    protected $casts = [
        'fleet_size' => 'integer',
        'services'   => 'array',
        'latitude'   => 'float',
        'longitude'  => 'float',
    ];

    /** Services a provider can offer. Rides will be added when that launches. */
    public const SERVICES = ['towing'];

    /**
     * The active provider offering $service closest to the pickup point.
     * Providers without coordinates are only used when none have them.
     */
    public static function nearestActive(string $service, ?float $lat, ?float $lng): ?self
    {
        $candidates = static::where('status', 'active')->oldest('id')->get()
            ->filter(fn($p) => in_array($service, $p->services ?? ['towing'], true));

        if ($lat !== null && $lng !== null) {
            $nearest = $candidates
                ->filter(fn($p) => $p->latitude !== null && $p->longitude !== null)
                ->sortBy(fn($p) => Hospital::distanceKm($lat, $lng, $p->latitude, $p->longitude))
                ->first();

            if ($nearest) {
                return $nearest;
            }
        }

        return $candidates->first();
    }

    public function users()
    {
        return $this->hasMany(ProviderUser::class);
    }

    public function drivers()
    {
        return $this->hasMany(Driver::class);
    }

    public function towTrucks()
    {
        return $this->hasMany(TowTruck::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function availableDrivers()
    {
        return $this->hasMany(Driver::class)->where('status', 'available');
    }

    public function availableTrucks()
    {
        return $this->hasMany(TowTruck::class)
            ->where('status', 'available')
            ->where('flagged_for_maintenance', false);
    }
}