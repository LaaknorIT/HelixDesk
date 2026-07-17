<?php

namespace App\Filament\Agent\Resources\WorkItemStatuses\Tables;

use App\Filament\Agent\Resources\WorkItemStatuses\WorkItemStatusResource;
use App\Models\WorkItemStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WorkItemStatusesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->sortable(),
                IconColumn::make('pauses_sla')
                    ->boolean(),
                IconColumn::make('is_system')
                    ->boolean(),
                IconColumn::make('is_default_open')
                    ->boolean(),
            ])
            ->filters([])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (WorkItemStatus $record): bool => WorkItemStatusResource::canDelete($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => WorkItemStatusResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('sort_order');
    }
}
