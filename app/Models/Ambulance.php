<?php
// app/Models/Ambulance.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ambulance extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'hospital_id', 'plate_number', 'make', 'model', 'year',
        'type', 'status', 'fuel_level', 'last_service_at', 'mileage_km',
        'flagged_for_maintenance',
    ];

    protected $casts = [
        'flagged_for_maintenance' => 'boolean',
        'fuel_level'              => 'integer',
        'year'                    => 'integer',
        'mileage_km'              => 'integer',
        'last_service_at'         => 'date:Y-m-d',
    ];

    public function hospital()
    {
        return $this->belongsTo(Hospital::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    /** The booking this ambulance is currently out on, if any. */
    public function activeBooking()
    {
        // The status filter must go inside ofMany, otherwise the newest booking
        // of any status is picked first and then filtered out.
        return $this->hasOne(Booking::class)->ofMany(
            ['id' => 'max'],
            fn($q) => $q->whereIn('status', Booking::ACTIVE_STATUSES),
        );
    }
}