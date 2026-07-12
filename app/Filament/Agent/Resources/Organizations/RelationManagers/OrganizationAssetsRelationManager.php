<?php

namespace App\Filament\Agent\Resources\Organizations\RelationManagers;

use App\Filament\Agent\Resources\Assets\AssetResource;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrganizationAssetsRelationManager extends RelationManager
{
    protected static string $relationship = 'assets';

    protected static ?string $title = 'Assets';

    protected static bool $shouldSkipAuthorization = true;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('assetType.name')
                    ->label('Type'),
                TextColumn::make('lifecycle_status')
                    ->label('Status'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->url(fn (): string => AssetResource::getUrl('create', [
                        'organization_id' => $this->ownerRecord->getKey(),
                    ]))
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('asset.create') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->url(fn ($record): string => AssetResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('asset.update') ?? false),
            ]);
    }
}
