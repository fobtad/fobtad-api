<?php
namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    
    public function boot(): void
    {
        Builder::defaultStringLength(191);

        // Website emergency form: limit per phone number. Many Nigerian mobile users share one
        // carrier IP, so the per-IP cap is only there to stop a script flooding the form.
        RateLimiter::for('web-emergency', fn(Request $request) => [
            Limit::perMinutes(10, 5)->by('phone:' . substr(preg_replace('/\D/', '', (string) $request->input('phone')), -10)),
            Limit::perMinute(30)->by('ip:' . $request->ip()),
        ]);
    }
}