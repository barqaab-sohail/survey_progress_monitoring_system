<?php

namespace App\Providers;

use App\Models\Mdb\SurveyBatch;
use App\Policies\SurveyBatchPolicy;
use App\Services\Mdb\Exporter;
use App\Services\Mdb\ExporterFactory;
use App\Services\Mdb\WorkflowAccess;
use App\Support\ProjectBranding;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProjectBranding::class);
        $this->app->singleton(Exporter::class, fn () => ExporterFactory::make());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(ProjectBranding $branding): void
    {
        Gate::policy(SurveyBatch::class, SurveyBatchPolicy::class);
        foreach (WorkflowAccess::ABILITIES as $ability) {
            Gate::define('mdb-workflow.'.$ability, fn ($user) => app(WorkflowAccess::class)->allows($user, $ability));
        }
        config(['app.name' => $branding->projectName()]);

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
