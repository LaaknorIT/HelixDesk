<?php

namespace App\Filament\Agent\Resources\Organizations\Tables;

use App\Enums\OrganizationType;
use App\Filament\Agent\Resources\Organizations\OrganizationResource;
use App\Models\Organization;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrganizationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (OrganizationType $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('parent.name')
                    ->label('Parent')
                    ->placeholder('—')
                    ->sortable(),
                IconColumn::make('is_billable')
                    ->label('Billable')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('external_reference')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Users')
                    ->sortable(),
                TextColumn::make('children_count')
                    ->counts('children')
                    ->label('Children')
                    ->sortable(),
                TextColumn::make('assets_count')
                    ->counts('assets')
                    ->label('Assets')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Organization $record): bool => OrganizationResource::canEdit($record)),
                DeleteAction::make()
                    ->visible(fn (Organization $record): bool => OrganizationResource::canDelete($record)),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => OrganizationResource::canCreate()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => OrganizationResource::canDeleteAny()),
                ]),
            ]);
    }
}
