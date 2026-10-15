<?php
// app/Models/Earning.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A hospital's or provider's share of one paid booking, after commission. */
class Earning extends Model
{
    protected $fillable = [
        'booking_id', 'payee_type', 'payee_id', 'gross',
        'commission_percent', 'commission', 'net', 'payout_id',
    ];

    protected $casts = [
        'gross'              => 'float',
        'commission_percent' => 'float',
        'commission'         => 'float',
        'net'                => 'float',
    ];

    public function booking() { return $this->belongsTo(Booking::class); }
    public function payout()  { return $this->belongsTo(Payout::class); }
}
