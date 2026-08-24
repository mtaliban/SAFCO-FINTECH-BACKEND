<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            \App\Services\Payment\PaymentProviderRegistry::class,
            fn () => new \App\Services\Payment\PaymentProviderRegistry()
        );
    }

    public function boot(): void
    {
        // Default API rate limit (60 req/min per user or IP)
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute((int) env('RATE_LIMIT_API', 60))
            ->by($r->user()?->id ?: $r->ip()));

        // Stricter limit for login / register / password endpoints (5 req/min per IP)
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute((int) env('RATE_LIMIT_AUTH', 5))
            ->by($r->ip()));

        // Tightest limit for OTP requests (3 req/min per IP)
        RateLimiter::for('otp', fn (Request $r) => Limit::perMinute((int) env('RATE_LIMIT_OTP', 3))
            ->by($r->ip()));

        // Live-play poll endpoint: frontend polls every ~1.2s (≈50/min).
        // Give players plenty of headroom (240/min = 4/sec) per participant/IP.
        RateLimiter::for('play', fn (Request $r) => Limit::perMinute((int) env('RATE_LIMIT_PLAY', 240))
            ->by($r->ip()));
    }
}
