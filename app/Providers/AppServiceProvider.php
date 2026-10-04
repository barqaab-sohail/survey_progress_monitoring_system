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

        // Keep the trusted origin while preserving a deployment subdirectory or index.php.
        ResetPassword::createUrlUsing(function ($user, string $token): string {
            $appUrl = rtrim((string) config('app.url'), '/');
            $configuredPath = trim((string) (parse_url($appUrl, PHP_URL_PATH) ?? ''), '/');
            $basePath = $configuredPath === '' ? request()->getBaseUrl() : '';

            return $appUrl.$basePath.route('password.reset', [
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ], false);
        });
    }
}
