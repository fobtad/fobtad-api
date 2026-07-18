<?php
// app/Models/Booking.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Booking extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference', 'patient_id', 'hospital_id', 'provider_id',
        'paramedic_id', 'ambulance_id', 'driver_id', 'tow_truck_id',
        'type', 'status',
        'pickup_address', 'pickup_latitude', 'pickup_longitude',
        'destination_address', 'destination_latitude', 'destination_longitude',
        'incident_type', 'ride_type', 'is_high_risk', 'needs_wheelchair',
        'weeks_pregnant', 'notes', 'scheduled_at',
        'accepted_at', 'completed_at', 'cancelled_at', 'cancellation_reason',
        'fare', 'rating', 'rating_comment',
    ];

    protected $casts = [
        'pickup_latitude'       => 'float',
        'pickup_longitude'      => 'float',
        'destination_latitude'  => 'float',
        'destination_longitude' => 'float',
        'is_high_risk'          => 'boolean',
        'needs_wheelchair'      => 'boolean',
        'weeks_pregnant'        => 'integer',
        'fare'                  => 'float',
        'rating'                => 'integer',
        'scheduled_at'          => 'datetime',
        'accepted_at'           => 'datetime',
        'completed_at'          => 'datetime',
        'cancelled_at'          => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($booking) {
            $booking->reference = strtoupper(substr($booking->type, 0, 3))
                . '-' . date('Y') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
        });
    }

    public function patient()    { return $this->belongsTo(Patient::class); }
    public function hospital()   { return $this->belongsTo(Hospital::class); }
    public function provider()   { return $this->belongsTo(Provider::class); }
    public function paramedic()  { return $this->belongsTo(Paramedic::class); }
    public function ambulance()  { return $this->belongsTo(Ambulance::class); }
    public function driver()     { return $this->belongsTo(Driver::class); }
    public function towTruck()   { return $this->belongsTo(TowTruck::class); }
    public function trip()       { return $this->hasOne(Trip::class); }
    public function payment()    { return $this->hasOne(Payment::class); }
}