<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderWallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id', 'balance', 'currency', 'status',
    ];

    protected $casts = [
        'balance' => 'float',
    ];

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function virtualAccounts()
    {
        return $this->hasMany(ProviderVirtualAccount::class);
    }

    public function primaryVirtualAccount()
    {
        return $this->hasOne(ProviderVirtualAccount::class)
            ->where('is_primary', true)
            ->where('status', 'active');
    }
}