<?php

namespace App\Filament\GlobalAdmin\Widgets;

use App\Models\Tenant;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TenantsOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected function getHeading(): ?string
    {
        return __('Overview');
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $inactive = Tenant::query()->whereIn('status', ['readonly', 'disabled'])->count();

        return [
            Stat::make(__('Active tenants'), Tenant::query()->where('status', 'active')->count())
                ->icon('heroicon-o-building-office-2'),
            Stat::make(__('Read-only or disabled'), $inactive)
                ->icon('heroicon-o-no-symbol')
                ->color($inactive > 0 ? 'warning' : 'gray'),
            Stat::make(__('Users'), User::query()->count())
                ->icon('heroicon-o-users'),
        ];
    }
}
