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
        'name', 'email', 'phone', 'address', 'lga', 'state',
        'latitude', 'longitude', 'licence_number', 'fleet_size',
        'status', 'logo', 'description',
    ];

    protected $casts = [
        'latitude'   => 'float',
        'longitude'  => 'float',
        'fleet_size' => 'integer',
    ];

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