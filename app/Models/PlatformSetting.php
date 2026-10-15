<?php
// app/Models/PlatformSetting.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-editable platform settings (commission, fares, cancellation fee).
 * Read with PlatformSetting::get('commission_percent').
 */
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing  = false;
    protected $keyType    = 'string';
    protected $fillable   = ['key', 'value'];
    protected $casts      = ['value' => 'array'];

    /** Defaults used until an admin changes them. Towing rates are placeholders. */
    public const DEFAULTS = [
        'commission_percent' => 10,
        'cancellation_fee'   => 0,
        'payout_interval_days' => 7,
        'rates' => [
            // Ambulance rides (ride_type)
            'standard'       => ['base' => 3000, 'per_km' => 250, 'floor' => 8000],
            'advanced'       => ['base' => 5000, 'per_km' => 450, 'floor' => 15000],
            'wheelchair'     => ['base' => 4000, 'per_km' => 320, 'floor' => 10000],
            // Booking types without a ride type
            'emergency'      => ['base' => 5000, 'per_km' => 450, 'floor' => 15000],
            'maternal'       => ['base' => 3000, 'per_km' => 250, 'floor' => 8000],
            'mortuary'       => ['base' => 5000, 'per_km' => 300, 'floor' => 15000],
            // Towing (truck type)
            'flatbed'        => ['base' => 10000, 'per_km' => 500, 'floor' => 15000],
            'wheel_lift'     => ['base' => 8000, 'per_km' => 400, 'floor' => 12000],
            'heavy_recovery' => ['base' => 25000, 'per_km' => 1000, 'floor' => 40000],
        ],
    ];

    public static function get(string $key): mixed
    {
        // Stored as {"v": ...} so scalars round-trip through the json column.
        $stored = static::find($key)?->value;

        return $stored['v'] ?? static::DEFAULTS[$key] ?? null;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
    }

    public static function snapshot(): array
    {
        return collect(array_keys(static::DEFAULTS))
            ->mapWithKeys(fn($k) => [$k => static::get($k)])
            ->all();
    }
}
