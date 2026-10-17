<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\Auth\RequestPasswordReset;
use App\Http\Middleware\SyncFilamentTenantContext;
use App\Models\Tenant;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AgentPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('agent')
            ->path('agent')
            ->tenant(Tenant::class, 'slug', 'tenant')
            ->tenantMiddleware([
                SyncFilamentTenantContext::class,
            ], isPersistent: true)
            ->tenantMenu(false)
            ->tenantSwitcher(false)
            ->login(Login::class)
            ->passwordReset(RequestPasswordReset::class)
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->navigationGroups([
                'Customers' => NavigationGroup::make('Customers')
                    ->icon(Heroicon::OutlinedBuildingOffice)
                    ->collapsed(false),
                'Service management' => NavigationGroup::make('Service management')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->collapsed(),
                'Infrastructure' => NavigationGroup::make('Infrastructure')
                    ->icon(Heroicon::OutlinedServer)
                    ->collapsed(),
                'Knowledge' => NavigationGroup::make('Knowledge')
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->collapsed(),
                'Administration' => NavigationGroup::make('Administration')
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->collapsed(),
            ])
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Agent/Resources'), for: 'App\\Filament\\Agent\\Resources')
            ->discoverPages(in: app_path('Filament/Agent/Pages'), for: 'App\\Filament\\Agent\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Agent/Widgets'), for: 'App\\Filament\\Agent\\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
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
