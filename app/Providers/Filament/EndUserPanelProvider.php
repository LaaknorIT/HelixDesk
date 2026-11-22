<?php

namespace App\Providers\Filament;

use App\Filament\EndUser\Pages\Dashboard;
use App\Http\Middleware\SyncFilamentTenantContext;
use App\Models\Tenant;
use App\Providers\Filament\Concerns\ConfiguresHelixdeskPanel;
use Filament\Panel;
use Filament\PanelProvider;

class EndUserPanelProvider extends PanelProvider
{
    use ConfiguresHelixdeskPanel;

    public function panel(Panel $panel): Panel
    {
        return $this->configureHelixdeskPanel($panel)
            ->default()
            ->id('end-user')
            ->path('end-user')
            ->tenant(Tenant::class, 'slug', 'tenant')
            ->tenantMiddleware([
                SyncFilamentTenantContext::class,
            ], isPersistent: true)
            ->tenantMenu(false)
            ->tenantSwitcher(false)
            ->discoverResources(in: app_path('Filament/EndUser/Resources'), for: 'App\\Filament\\EndUser\\Resources')
            ->discoverPages(in: app_path('Filament/EndUser/Pages'), for: 'App\\Filament\\EndUser\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/EndUser/Widgets'), for: 'App\\Filament\\EndUser\\Widgets');
    }
}
