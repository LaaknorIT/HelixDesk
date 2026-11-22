<?php

namespace App\Filament\Agent\Resources\Assets\RelationManagers;

use App\Models\AssetSourceRecord;
use App\Support\DeviceSourceCatalog;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Which device sources (Intune, Action1, …) report this asset, and when each last saw it active.
 */
class SourceRecordsRelationManager extends RelationManager
{
    protected static string $relationship = 'sourceRecords';

    protected static ?string $title = 'Sources';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('source')
            ->columns([
                TextColumn::make('source')
                    ->label(__('Source'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => DeviceSourceCatalog::label($state)),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (AssetSourceRecord $record): string => $record->status())
                    ->formatStateUsing(fn (string $state): string => AssetSourceRecord::statusLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        AssetSourceRecord::STATUS_ACTIVE => 'success',
                        AssetSourceRecord::STATUS_STALE => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('display_name')
                    ->label(__('Name'))
                    ->placeholder(__('—')),
                TextColumn::make('device_class')
                    ->label(__('Class'))
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? '—'),
                TextColumn::make('last_activity_at')
                    ->label(__('Last active'))
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder(__('Unknown')),
                TextColumn::make('last_reported_at')
                    ->label(__('Last reported'))
                    ->dateTime()
                    ->placeholder(__('—')),
                TextColumn::make('provider_record_id')
                    ->label(__('Source ID'))
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->paginated([10, 25, 50]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
