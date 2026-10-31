<?php
// app/Http/Controllers/Admin/AdminController.php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

abstract class AdminController extends Controller
{
    /** Abort with 403 unless the signed-in admin's role allows $ability. */
    protected function authorizeAbility(Request $request, string $ability): void
    {
        if (!$request->user()->allows($ability)) {
            abort(response()->json([
                'success' => false,
                'message' => 'Your admin role does not allow this action.',
            ], 403));
        }
    }

    protected function perPage(Request $request, int $default = 20): int
    {
        return min((int) $request->input('per_page', $default), 100);
    }
}
