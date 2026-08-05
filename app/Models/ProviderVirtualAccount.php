<?php
// app/Models/ProviderVirtualAccount.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderVirtualAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_wallet_id', 'account_number', 'account_name',
        'bank_name', 'bank_code', 'paystack_reference',
        'customer_code', 'is_primary', 'status',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function providerWallet()
    {
        return $this->belongsTo(ProviderWallet::class);
    }
}