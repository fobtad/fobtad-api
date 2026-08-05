<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'wallet_id', 'type', 'amount', 'balance_before',
        'balance_after', 'reference', 'description',
        'source', 'status', 'meta',
    ];

    protected $casts = [
        'amount'         => 'float',
        'balance_before' => 'float',
        'balance_after'  => 'float',
        'meta'           => 'array',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}