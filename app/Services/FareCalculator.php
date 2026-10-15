<?php
// app/Services/FareCalculator.php
namespace App\Services;

use App\Models\Hospital;
use App\Models\PlatformSetting;

/**
 * Fixed fares from the admin-editable rate card: base + per_km × distance,
 * never below the floor, rounded up to the nearest ₦100.
 */
class FareCalculator
{
    /** Which rate applies: the ride/truck type if it has a rate, else the booking type. */
    public static function rateKey(string $bookingType, ?string $rideType): string
    {
        $rates = PlatformSetting::get('rates');

        if ($rideType && isset($rates[$rideType])) {
            return $rideType;
        }

        return match ($bookingType) {
            'emergency' => 'emergency',
            'maternal'  => 'maternal',
            'mortuary'  => 'mortuary',
            'towing'    => 'flatbed',
            default     => 'standard',
        };
    }

    public static function fare(string $rateKey, float $distanceKm): float
    {
        $rate = PlatformSetting::get('rates')[$rateKey] ?? PlatformSetting::DEFAULTS['rates']['standard'];
        $raw  = $rate['base'] + max(0, $distanceKm) * $rate['per_km'];

        return (float) (ceil(max($rate['floor'], $raw) / 100) * 100);
    }

    /**
     * Distance used for pricing: pickup → destination when both are known; for
     * emergencies (no destination) the hospital → pickup leg; otherwise 0 (floor).
     */
    public static function distanceKm(?float $pLat, ?float $pLng, ?float $dLat, ?float $dLng, ?Hospital $hospital = null): float
    {
        if ($pLat !== null && $pLng !== null && $dLat !== null && $dLng !== null) {
            return Hospital::distanceKm($pLat, $pLng, $dLat, $dLng);
        }

        if ($pLat !== null && $pLng !== null && $hospital?->latitude !== null) {
            return Hospital::distanceKm($pLat, $pLng, $hospital->latitude, $hospital->longitude);
        }

        return 0;
    }

    public static function forBooking(string $type, ?string $rideType, ?float $pLat, ?float $pLng, ?float $dLat, ?float $dLng, ?Hospital $hospital = null): float
    {
        return static::fare(static::rateKey($type, $rideType), static::distanceKm($pLat, $pLng, $dLat, $dLng, $hospital));
    }
}
