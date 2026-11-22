<?php

namespace App\Filament\Agent\Widgets;

use App\Filament\Agent\Resources\WorkItems\WorkItemResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The numbers an agent starts the day with, limited to the queues they may see.
 */
class TicketOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    public static function canView(): bool
    {
        return WorkItemResource::canAccess();
    }

    protected function getStats(): array
    {
        $open = fn (): Builder => WorkItemResource::getEloquentQuery()->open();
        $ticketsUrl = WorkItemResource::getUrl('index');

        $mine = $open()->where('assigned_agent_id', Auth::id())->count();
        $unassigned = $open()->whereNull('assigned_agent_id')->count();
        $breached = $open()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->whereNull('first_responded_at')->where('first_response_due_at', '<', now()))
                ->orWhere('resolution_due_at', '<', now()))
            ->count();
        $newToday = WorkItemResource::getEloquentQuery()->where('opened_at', '>=', now()->startOfDay())->count();

        return [
            Stat::make(__('My open tickets'), $mine)
                ->icon('heroicon-o-user')
                ->url($ticketsUrl.'?activeTab=mine'),
            Stat::make(__('Unassigned'), $unassigned)
                ->icon('heroicon-o-inbox')
                ->color($unassigned > 0 ? 'warning' : 'gray')
                ->description($unassigned > 0 ? __('Waiting for someone to take them') : __('Nothing waiting'))
                ->url($ticketsUrl.'?activeTab=unassigned'),
            Stat::make(__('SLA breached'), $breached)
                ->icon('heroicon-o-clock')
                ->color($breached > 0 ? 'danger' : 'success')
                ->description($breached > 0 ? __('Past the response or resolution deadline') : __('All open tickets are within SLA')),
            Stat::make(__('New today'), $newToday)
                ->icon('heroicon-o-sparkles'),
        ];
    }
}
