<?php

namespace App\Filament\EndUser\Resources\WorkItems\Pages;

use App\Filament\EndUser\Actions\CreateTicketAction;
use App\Filament\EndUser\Resources\WorkItems\EndUserWorkItemResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListEndUserWorkItems extends ListRecords
{
    protected static string $resource = EndUserWorkItemResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'open' => Tab::make(__('Open'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->open()),
            'closed' => Tab::make(__('Solved and closed'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where(fn (Builder $query) => $query->whereNotNull('resolved_at')->orWhereNotNull('closed_at'))),
            'all' => Tab::make(__('All')),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'open';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateTicketAction::make(),
        ];
    }
}
