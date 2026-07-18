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
        'type', 'status', 'fuel_level', 'flagged_for_maintenance',
    ];

    protected $casts = [
        'flagged_for_maintenance' => 'boolean',
        'fuel_level'              => 'integer',
        'year'                    => 'integer',
    ];

    public function hospital()
    {
        return $this->belongsTo(Hospital::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}