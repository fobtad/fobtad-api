<?php
// app/Http/Middleware/EnsureUserType.php
namespace App\Http\Middleware;

use App\Models\HospitalUser;
use App\Models\Patient;
use App\Models\ProviderUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Patients, hospital staff and provider staff all authenticate with Sanctum
 * tokens. This makes sure a token can only be used on its own route group.
 * Usage: ->middleware('user.type:hospital')
 */
class EnsureUserType
{
    private const TYPES = [
        'patient'  => Patient::class,
        'hospital' => HospitalUser::class,
        'provider' => ProviderUser::class,
    ];

    public function handle(Request $request, Closure $next, string $type): Response
    {
        $class = self::TYPES[$type] ?? null;

        if (!$class || !($request->user() instanceof $class)) {
            return response()->json([
                'success' => false,
                'message' => 'This account cannot access this resource.',
            ], 403);
        }

        $org = match ($type) {
            'hospital' => $request->user()->hospital,
            'provider' => $request->user()->provider,
            default    => null,
        };

        if ($org?->status === 'suspended') {
            return response()->json([
                'success' => false,
                'message' => "This {$type} account has been suspended. Please contact FOBTAD.",
            ], 403);
        }

        return $next($request);
    }
}
