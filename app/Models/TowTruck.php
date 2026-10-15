<?php
// app/Models/TowTruck.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TowTruck extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'provider_id', 'plate_number', 'make', 'model', 'year',
        'type', 'capacity_tonnes', 'status', 'fuel_level', 'last_service_at', 'mileage_km',
        'flagged_for_maintenance',
    ];

    protected $casts = [
        'flagged_for_maintenance' => 'boolean',
        'year'                    => 'integer',
        'capacity_tonnes'         => 'float',
        'fuel_level'              => 'integer',
        'mileage_km'              => 'integer',
        'last_service_at'         => 'date:Y-m-d',
    ];

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    /** The job this vehicle is currently out on, if any. */
    public function activeBooking()
    {
        return $this->hasOne(Booking::class)->ofMany(
            ['id' => 'max'],
            fn($q) => $q->whereIn('status', Booking::ACTIVE_STATUSES),
        );
    }
}