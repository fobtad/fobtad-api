<?php
// app/Models/VirtualAccount.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VirtualAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'wallet_id', 'account_number', 'account_name',
        'bank_name', 'bank_code', 'paystack_reference',
        'customer_code', 'is_primary', 'status',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}