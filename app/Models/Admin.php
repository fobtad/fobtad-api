<?php
// app/Models/Admin.php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * FOBTAD staff. Roles: super_admin (everything incl. settings and admins),
 * admin (operations, approvals, payouts), support (read-only + booking help).
 */
class Admin extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = ['first_name', 'last_name', 'email', 'password', 'role', 'is_active', 'last_login_at'];
    protected $hidden   = ['password', 'remember_token'];
    protected $casts    = ['is_active' => 'boolean', 'last_login_at' => 'datetime'];
    protected $appends  = ['full_name'];

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function allows(string $ability): bool
    {
        return match ($ability) {
            'settings'   => $this->role === 'super_admin',
            'operations' => in_array($this->role, ['super_admin', 'admin']),
            default      => true,
        };
    }
}
