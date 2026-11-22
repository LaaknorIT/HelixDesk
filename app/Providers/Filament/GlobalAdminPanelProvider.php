<?php

namespace App\Providers\Filament;

use App\Providers\Filament\Concerns\ConfiguresHelixdeskPanel;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;

class GlobalAdminPanelProvider extends PanelProvider
{
    use ConfiguresHelixdeskPanel;

    public function panel(Panel $panel): Panel
    {
        return $this->configureHelixdeskPanel($panel)
            ->default()
            ->id('global-admin')
            ->path('global-admin')
            // Same colours as the other panels; the name in the top bar says where you are.
            ->brandName(fn (): string => config('app.name', 'HelixDesk').' · '.__('Platform admin'))
            ->discoverResources(in: app_path('Filament/GlobalAdmin/Resources'), for: 'App\\Filament\\GlobalAdmin\\Resources')
            ->discoverPages(in: app_path('Filament/GlobalAdmin/Pages'), for: 'App\\Filament\\GlobalAdmin\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/GlobalAdmin/Widgets'), for: 'App\\Filament\\GlobalAdmin\\Widgets');
    }
}
