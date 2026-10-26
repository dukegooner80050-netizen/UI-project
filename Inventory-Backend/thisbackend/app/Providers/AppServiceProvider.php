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
        // Login: 5 attempts per minute for each username + IP address. Keyed
        // on both so one person guessing at an account is slowed down without
        // locking out everyone else on the same network.
        RateLimiter::for('login', function (Request $request) {
            $username = strtolower((string) $request->input('username'));

            return Limit::perMinute(5)->by($username . '|' . $request->ip());
        });
    }
}
