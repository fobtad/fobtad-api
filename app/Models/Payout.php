<?php
// app/Models/Payout.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A weekly payout request from a hospital or provider, settled by FOBTAD admin. */
class Payout extends Model
{
    protected $fillable = [
        'reference', 'payee_type', 'payee_id', 'amount', 'status',
        'bank_name', 'account_number', 'account_name',
        'requested_by', 'admin_note', 'processed_at',
    ];

    protected $casts = [
        'amount'       => 'float',
        'processed_at' => 'datetime',
    ];

    public function earnings() { return $this->hasMany(Earning::class); }

    public function payee()
    {
        return $this->payee_type === 'hospital'
            ? Hospital::find($this->payee_id)
            : Provider::find($this->payee_id);
    }
}
