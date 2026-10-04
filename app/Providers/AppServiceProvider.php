<?php

namespace App\Providers;

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Auth\Notifications\ResetPassword;
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
        FilamentShield::enforcePolicies();

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(10)->by('reset-ip:'.$request->ip()),
            Limit::perMinute(3)->by('reset-email:'.strtolower(trim(is_string($request->input('email')) ? $request->input('email') : ''))),
        ]);
        RateLimiter::for('password-reset-submit', fn (Request $request) => Limit::perMinute(10)
            ->by('reset-submit:'.$request->ip()));

        // Use the configured application address rather than an untrusted request host.
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('app.url'), '/')
            .route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], false));
    }
}
