<?php

namespace App\Filament\EndUser\Resources\WorkItems\Tables;

use App\Filament\EndUser\Resources\WorkItems\EndUserWorkItemResource;
use App\Models\WorkItem;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EndUserWorkItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->sortable(),
                TextColumn::make('priority')
                    ->label('Priority')
                    ->sortable(),
                TextColumn::make('opened_at')
                    ->label('Opened')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-eye')
                    ->url(fn (WorkItem $record): string => EndUserWorkItemResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
