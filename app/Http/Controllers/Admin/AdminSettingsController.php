<?php
// app/Http/Controllers/Admin/AdminSettingsController.php
namespace App\Http\Controllers\Admin;

use App\Models\PlatformSetting;
use Illuminate\Http\Request;

/** Commission, fares, cancellation fee and payout interval. Super admins only for changes. */
class AdminSettingsController extends AdminController
{
    public function show()
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'settings' => PlatformSetting::snapshot(),
                'defaults' => PlatformSetting::DEFAULTS,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $this->authorizeAbility($request, 'settings');

        $rateKeys = array_keys(PlatformSetting::DEFAULTS['rates']);

        $data = $request->validate([
            'commission_percent'   => 'sometimes|numeric|min:0|max:50',
            'cancellation_fee'     => 'sometimes|numeric|min:0|max:100000',
            'payout_interval_days' => 'sometimes|integer|min:1|max:31',
            'rates'                => 'sometimes|array',
            'rates.*'              => 'array',
            'rates.*.base'         => 'required_with:rates|numeric|min:0',
            'rates.*.per_km'       => 'required_with:rates|numeric|min:0',
            'rates.*.floor'        => 'required_with:rates|numeric|min:0',
        ]);

        if (isset($data['rates'])) {
            $unknown = array_diff(array_keys($data['rates']), $rateKeys);
            if ($unknown) {
                return response()->json(['success' => false, 'message' => 'Unknown rate: ' . implode(', ', $unknown)], 422);
            }
            // Merge so a partial update doesn't wipe other rates.
            $data['rates'] = array_map(
                fn($r) => ['base' => (float) $r['base'], 'per_km' => (float) $r['per_km'], 'floor' => (float) $r['floor']],
                [...PlatformSetting::get('rates'), ...$data['rates']],
            );
        }

        foreach ($data as $key => $value) {
            PlatformSetting::put($key, is_numeric($value) ? (float) $value : $value);
        }

        return response()->json(['success' => true, 'message' => 'Settings saved. New bookings use them immediately.', 'data' => PlatformSetting::snapshot()]);
    }
}
