<?php
// app/Models/Driver.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Driver extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'provider_id', 'first_name', 'last_name', 'email', 'phone',
        'licence_number', 'shift', 'status', 'total_jobs', 'rating', 'photo',
    ];

    protected $appends = ['full_name'];

    protected $casts = [
        'rating'     => 'float',
        'total_jobs' => 'integer',
    ];

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    /** The job this driver is currently out on, if any. */
    public function activeBooking()
    {
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