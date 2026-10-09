<?php

namespace App\Providers\Filament;

use App\Support\ProjectBranding;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => view('auth.password-reset-link'))
            ->brandName(fn (): string => app(ProjectBranding::class)->projectName())
            ->brandLogo(fn (): string => app(ProjectBranding::class)->logoUrl())
            ->brandLogoHeight('4rem')
            ->favicon(fn (): string => app(ProjectBranding::class)->faviconUrl())
            ->navigationItems([
                NavigationItem::make('Android Survey Web Test')
                    ->group('Administration')
                    ->icon('heroicon-o-device-phone-mobile')
                    ->url(fn (): string => route('field-survey-test.index'))
                    ->visible(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false)
                    ->sort(0),
                NavigationItem::make('Master Data & Import')
                    ->group('Administration')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->url(fn (): string => route('admin.master.index'))
                    ->visible(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false)
                    ->sort(1),
                NavigationItem::make('Teams & Assignments')
                    ->group('Administration')
                    ->icon('heroicon-o-user-group')
                    ->url(fn (): string => route('admin.teams.index'))
                    ->visible(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false)
                    ->sort(2),
                NavigationItem::make('Users')
                    ->group('Administration')
                    ->icon('heroicon-o-users')
                    ->url(fn (): string => route('admin.users.index'))
                    ->visible(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false)
                    ->sort(3),
                NavigationItem::make('Organizations')
                    ->group('Administration')
                    ->icon('heroicon-o-building-office')
                    ->url(fn (): string => route('admin.organizations.index'))
                    ->visible(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false)
                    ->sort(4),
                NavigationItem::make('Audit Log')
                    ->group('Administration')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->url(fn (): string => route('admin.audit.index'))
                    ->visible(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false)
                    ->sort(5),
            ])
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->plugin(FilamentShieldPlugin::make())
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
