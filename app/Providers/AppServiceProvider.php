<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keyed by the Sanctum token (the caller's API key), not the request
        // IP - a merchant's backend can legitimately call this from many
        // source IPs under one token, and IP-based limiting would either let
        // that traffic dodge the limit across IPs or wrongly throttle
        // unrelated tokens sharing an egress IP.
        RateLimiter::for('usage-events', function (Request $request) {
            $key = $request->user()?->currentAccessToken()?->id ?? $request->ip();

            return Limit::perMinute(
                config('billing.usage_events.rate_limit.max_attempts'),
                config('billing.usage_events.rate_limit.decay_minutes'),
            )->by($key);
        });
    }
}
