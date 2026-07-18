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
        'type', 'status', 'flagged_for_maintenance',
    ];

    protected $casts = [
        'flagged_for_maintenance' => 'boolean',
        'year'                    => 'integer',
    ];

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}