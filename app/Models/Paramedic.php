<?php
// app/Models/Paramedic.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Paramedic extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'hospital_id', 'first_name', 'last_name', 'email', 'phone',
        'licence_number', 'status', 'total_trips', 'rating', 'photo',
    ];

    protected $casts = [
        'rating'      => 'float',
        'total_trips' => 'integer',
    ];

    public function hospital()
    {
        return $this->belongsTo(Hospital::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}