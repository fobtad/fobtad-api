<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id', 'balance', 'currency', 'status',
    ];

    protected $casts = [
        'balance' => 'float',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function virtualAccounts()
    {
        return $this->hasMany(VirtualAccount::class);
    }

    public function primaryVirtualAccount()
    {
        return $this->hasOne(VirtualAccount::class)
            ->where('is_primary', true)
            ->where('status', 'active');
    }

    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class)->latest();
    }

    public function credit(float $amount, string $reference, string $description, string $source = 'bank_transfer'): WalletTransaction
    {
        $balanceBefore = $this->balance;
        $balanceAfter  = $balanceBefore + $amount;

        $this->increment('balance', $amount);

        return $this->transactions()->create([
            'type'           => 'credit',
            'amount'         => $amount,
            'balance_before' => $balanceBefore,
            'balance_after'  => $balanceAfter,
            'reference'      => $reference,
            'description'    => $description,
            'source'         => $source,
            'status'         => 'success',
        ]);
    }

    public function debit(float $amount, string $reference, string $description, string $source = 'booking_payment'): WalletTransaction
    {
        if ($this->balance < $amount) {
            throw new \Exception('Insufficient wallet balance.');
        }

        $balanceBefore = $this->balance;
        $balanceAfter  = $balanceBefore - $amount;

        $this->decrement('balance', $amount);

        return $this->transactions()->create([
            'type'           => 'debit',
            'amount'         => $amount,
            'balance_before' => $balanceBefore,
            'balance_after'  => $balanceAfter,
            'reference'      => $reference,
            'description'    => $description,
            'source'         => $source,
            'status'         => 'success',
        ]);
    }
}