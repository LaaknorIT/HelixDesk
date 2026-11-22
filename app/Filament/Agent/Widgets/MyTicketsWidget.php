<?php

namespace App\Filament\Agent\Widgets;

use App\Enums\WorkItemPriority;
use App\Filament\Agent\Resources\WorkItems\WorkItemResource;
use App\Models\WorkItem;
use App\Support\WorkItemStatusDisplay;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class MyTicketsWidget extends TableWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return WorkItemResource::canAccess();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('My open tickets'))
            ->query(fn (): Builder => WorkItemResource::getEloquentQuery()
                ->open()
                ->where('assigned_agent_id', Auth::id())
                ->with(['organization', 'reportedBy']))
            ->columns([
                TextColumn::make('id')
                    ->label(__('#'))
                    ->formatStateUsing(fn (int|string $state): string => '#'.$state),
                TextColumn::make('title')
                    ->label(__('Title'))
                    ->description(fn (WorkItem $record): ?string => $record->reportedBy?->name)
                    ->wrap(),
                TextColumn::make('organization.name')
                    ->label(__('Customer'))
                    ->placeholder(__('—')),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => WorkItemStatusDisplay::label($state))
                    ->color(fn (?string $state): string|array => WorkItemStatusDisplay::color($state)),
                TextColumn::make('priority')
                    ->label(__('Priority'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => WorkItemPriority::labelFor($state))
                    ->color(fn (?string $state): string => WorkItemPriority::colorFor($state)),
                TextColumn::make('updated_at')
                    ->label(__('Last updated'))
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->paginated([5, 10])
            ->recordUrl(fn (WorkItem $record): string => WorkItemResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('No open tickets assigned to you'))
            ->emptyStateDescription(__('Take a ticket from the Unassigned list to get started.'))
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
