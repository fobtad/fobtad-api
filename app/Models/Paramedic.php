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
        'role', 'shift', 'licence_number', 'status', 'total_trips', 'rating', 'photo',
    ];

    protected $appends = ['full_name'];

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

    /** The booking this paramedic is currently out on, if any. */
    public function activeBooking()
    {
        // The status filter must go inside ofMany, otherwise the newest booking
        // of any status is picked first and then filtered out.
        return $this->hasOne(Booking::class)->ofMany(
            ['id' => 'max'],
            fn($q) => $q->whereIn('status', Booking::ACTIVE_STATUSES),
        );
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}