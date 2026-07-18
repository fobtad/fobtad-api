<?php
// app/Models/Payment.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id', 'patient_id', 'reference', 'paystack_reference',
        'amount', 'currency', 'status', 'channel', 'paystack_response', 'paid_at',
    ];

    protected $casts = [
        'amount'            => 'float',
        'paystack_response' => 'array',
        'paid_at'           => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }
}