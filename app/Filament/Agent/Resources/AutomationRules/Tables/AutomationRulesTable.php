<?php

namespace App\Filament\Agent\Resources\AutomationRules\Tables;

use App\Enums\AutomationTrigger;
use App\Filament\Agent\Resources\AutomationRules\AutomationRuleResource;
use App\Models\AutomationRule;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AutomationRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_enabled')
                    ->boolean(),
                TextColumn::make('trigger')
                    ->badge()
                    ->formatStateUsing(fn (AutomationTrigger|string $state): string => $state instanceof AutomationTrigger
                        ? str($state->name)->headline()->toString()
                        : str((string) $state)->headline()->toString())
                    ->sortable(),
                IconColumn::make('is_system')
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (AutomationRule $record): bool => AutomationRuleResource::canDelete($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => AutomationRuleResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('sort_order');
    }
}
