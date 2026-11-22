<?php

namespace App\Filament\Agent\Resources\AssetIdentityAmbiguities\Tables;

use App\Enums\AssetIdentityAmbiguityStatus;
use App\Filament\Agent\Resources\AssetIdentityAmbiguities\AssetIdentityAmbiguityResource;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AssetIdentityAmbiguitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('organization.name')
                    ->label(__('Organization'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('provider')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('candidate_asset_ids')
                    ->label(__('Candidates'))
                    ->formatStateUsing(fn (array $state): string => (string) count($state)),
                TextColumn::make('resolvedAsset.name')
                    ->label(__('Resolved asset'))
                    ->placeholder(__('—')),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        AssetIdentityAmbiguityStatus::Open->value => __('Open'),
                        AssetIdentityAmbiguityStatus::Resolved->value => __('Resolved'),
                        AssetIdentityAmbiguityStatus::Dismissed->value => __('Dismissed'),
                    ])
                    ->default(AssetIdentityAmbiguityStatus::Open->value),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record): string => AssetIdentityAmbiguityResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
