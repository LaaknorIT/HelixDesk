<?php

namespace App\Filament\GlobalAdmin\Widgets;

use App\Models\Tenant;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TenantsOverviewWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Overview';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        return [
            Stat::make('Tenants', Tenant::query()->count())
                ->icon('heroicon-o-building-office-2'),
            Stat::make('Users', User::query()->count())
                ->icon('heroicon-o-users'),
        ];
    }
}
