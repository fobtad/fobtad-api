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
        'company_name', 'email', 'phone', 'address', 'lga', 'state',
        'cac_number', 'coverage_areas', 'fleet_size', 'status', 'logo',
    ];

    protected $casts = [
        'fleet_size' => 'integer',
    ];

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