<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');

            return [
                Limit::perMinute(10)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('email:'.hash('sha256', is_string($email) ? strtolower($email) : 'invalid')),
            ];
        });
        RateLimiter::for('frontend', fn (Request $request) => Limit::perMinute(120)->by((string) $request->user()->id));
        RateLimiter::for('tracker', function (Request $request) {
            $imei = $request->input('i');

            return Limit::perMinute(120)->by(is_string($imei) ? $imei : 'invalid');
        });
    }
}
