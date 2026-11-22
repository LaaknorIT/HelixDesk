<?php

namespace App\Filament\EndUser\Resources\WorkItems\Tables;

use App\Filament\EndUser\Actions\CreateTicketAction;
use App\Filament\EndUser\Resources\WorkItems\EndUserWorkItemResource;
use App\Models\WorkItem;
use App\Support\WorkItemStatusDisplay;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EndUserWorkItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(__('#'))
                    ->formatStateUsing(fn (int|string $state): string => '#'.$state)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label(__('Summary'))
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => WorkItemStatusDisplay::label($state))
                    ->color(fn (?string $state): string|array => WorkItemStatusDisplay::color($state))
                    ->sortable(),
                TextColumn::make('opened_at')
                    ->label(__('Submitted'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('Last updated'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(fn (WorkItem $record): string => EndUserWorkItemResource::getUrl('view', ['record' => $record]))
            ->emptyStateIcon('heroicon-o-chat-bubble-left-right')
            ->emptyStateHeading(__('No tickets yet'))
            ->emptyStateDescription(__('When something is not working or you need something, report it here and follow it until it is solved.'))
            ->emptyStateActions([
                CreateTicketAction::make('createFirstWorkItem'),
            ]);
    }
}
