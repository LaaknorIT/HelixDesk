<?php

namespace App\Providers\Filament;

use App\Filament\Agent\Pages\NotificationSettings;
use App\Http\Middleware\SyncFilamentTenantContext;
use App\Models\Tenant;
use App\Providers\Filament\Concerns\ConfiguresHelixdeskPanel;
use Filament\Actions\Action;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;

class AgentPanelProvider extends PanelProvider
{
    use ConfiguresHelixdeskPanel;

    public function panel(Panel $panel): Panel
    {
        return $this->configureHelixdeskPanel($panel)
            ->default()
            ->id('agent')
            ->path('agent')
            ->tenant(Tenant::class, 'slug', 'tenant')
            ->tenantMiddleware([
                SyncFilamentTenantContext::class,
            ], isPersistent: true)
            ->tenantMenu(false)
            ->tenantSwitcher(false)
            // Group keys stay in English (resources refer to them); the labels follow the user's language.
            ->navigationGroups([
                'Service management' => NavigationGroup::make()
                    ->label(fn (): string => __('Service management'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->collapsed(false),
                'Customers' => NavigationGroup::make()
                    ->label(fn (): string => __('Customers and people'))
                    ->icon(Heroicon::OutlinedBuildingOffice)
                    ->collapsed(false),
                'Infrastructure' => NavigationGroup::make()
                    ->label(fn (): string => __('Infrastructure'))
                    ->icon(Heroicon::OutlinedServer)
                    ->collapsed(),
                'Knowledge' => NavigationGroup::make()
                    ->label(fn (): string => __('Knowledge'))
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->collapsed(),
                'Ticket setup' => NavigationGroup::make()
                    ->label(fn (): string => __('Ticket setup'))
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->collapsed(),
                'Administration' => NavigationGroup::make()
                    ->label(fn (): string => __('Administration'))
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->collapsed(),
            ])
            ->sidebarCollapsibleOnDesktop()
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->userMenuItems([
                Action::make('notificationSettings')
                    ->label(fn (): string => __('Notification settings'))
                    ->icon(Heroicon::OutlinedBell)
                    ->url(fn (): string => NotificationSettings::getUrl()),
            ])
            ->discoverResources(in: app_path('Filament/Agent/Resources'), for: 'App\\Filament\\Agent\\Resources')
            ->discoverPages(in: app_path('Filament/Agent/Pages'), for: 'App\\Filament\\Agent\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Agent/Widgets'), for: 'App\\Filament\\Agent\\Widgets');
    }
}
