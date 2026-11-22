<?php

namespace App\Filament\EndUser\Widgets;

use App\Filament\EndUser\Resources\WorkItems\EndUserWorkItemResource;
use App\Models\WorkItem;
use App\Support\WorkItemStatusDisplay;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class MyOpenTicketsWidget extends TableWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('My open tickets'))
            ->query(fn (): Builder => EndUserWorkItemResource::getEloquentQuery()->open())
            ->columns([
                TextColumn::make('id')
                    ->label(__('#'))
                    ->formatStateUsing(fn (int|string $state): string => '#'.$state),
                TextColumn::make('title')
                    ->label(__('Summary'))
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => WorkItemStatusDisplay::label($state))
                    ->color(fn (?string $state): string|array => WorkItemStatusDisplay::color($state)),
                TextColumn::make('updated_at')
                    ->label(__('Last updated'))
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->paginated([5])
            ->recordUrl(fn (WorkItem $record): string => EndUserWorkItemResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('No open tickets'))
            ->emptyStateDescription(__('Everything you have reported is solved. Use Report an issue if you need help.'))
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
