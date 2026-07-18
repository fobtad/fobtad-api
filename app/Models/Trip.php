<?php
// app/Models/Trip.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Trip extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id', 'status',
        'current_latitude', 'current_longitude',
        'eta_minutes', 'dispatched_at', 'arrived_at',
        'completed_at', 'duration_minutes', 'distance_km',
    ];

    protected $casts = [
        'current_latitude'  => 'float',
        'current_longitude' => 'float',
        'eta_minutes'       => 'integer',
        'duration_minutes'  => 'integer',
        'distance_km'       => 'float',
        'dispatched_at'     => 'datetime',
        'arrived_at'        => 'datetime',
        'completed_at'      => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}