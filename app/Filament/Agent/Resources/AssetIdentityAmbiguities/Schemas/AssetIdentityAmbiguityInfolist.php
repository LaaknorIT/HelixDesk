<?php

namespace App\Filament\Agent\Resources\AssetIdentityAmbiguities\Schemas;

use App\Filament\Agent\Resources\Assets\AssetResource;
use App\Models\Asset;
use App\Models\AssetIdentityAmbiguity;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AssetIdentityAmbiguityInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Ambiguity details'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('organization.name')
                            ->label(__('Organization')),
                        TextEntry::make('provider')
                            ->badge(),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('organizationIntegration.type')
                            ->label(__('Integration type')),
                        TextEntry::make('created_at')
                            ->dateTime(),
                        TextEntry::make('resolvedAsset.name')
                            ->label(__('Resolved asset'))
                            ->placeholder(__('—'))
                            ->url(fn (AssetIdentityAmbiguity $record): ?string => $record->resolved_asset_id !== null
                                ? AssetResource::getUrl('view', ['record' => $record->resolved_asset_id])
                                : null),
                    ]),
                Section::make(__('Provider snapshot'))
                    ->schema([
                        RepeatableEntry::make('snapshot_json.identifiers')
                            ->label(__('Identifiers'))
                            ->schema([
                                TextEntry::make('type'),
                                TextEntry::make('value'),
                            ])
                            ->columns(2)
                            ->placeholder(__('No identifiers')),
                        RepeatableEntry::make('snapshot_json.attributes')
                            ->label(__('Attributes'))
                            ->schema([
                                TextEntry::make('key')
                                    ->label(__('Field')),
                                TextEntry::make('value')
                                    ->label(__('Value')),
                            ])
                            ->state(fn (AssetIdentityAmbiguity $record): array => collect(is_array($record->snapshot_json['attributes'] ?? null) ? $record->snapshot_json['attributes'] : [])
                                ->map(fn (mixed $value, string|int $key): array => [
                                    'key' => (string) $key,
                                    'value' => is_scalar($value) ? (string) $value : json_encode($value),
                                ])
                                ->values()
                                ->all())
                            ->columns(2)
                            ->placeholder(__('No attributes')),
                    ]),
                Section::make(__('Candidate assets'))
                    ->schema([
                        RepeatableEntry::make('candidate_assets')
                            ->label(__('Candidates'))
                            ->state(fn (AssetIdentityAmbiguity $record): array => Asset::query()
                                ->whereIn('id', $record->candidate_asset_ids ?? [])
                                ->get()
                                ->map(fn (Asset $asset): array => [
                                    'id' => $asset->getKey(),
                                    'name' => $asset->name,
                                    'serial_number' => $asset->serial_number,
                                    'primary_hostname' => $asset->primary_hostname,
                                ])
                                ->all())
                            ->schema([
                                TextEntry::make('name')
                                    ->url(fn (array $state): string => AssetResource::getUrl('view', ['record' => $state['id']])),
                                TextEntry::make('serial_number')
                                    ->placeholder(__('—')),
                                TextEntry::make('primary_hostname')
                                    ->placeholder(__('—')),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }
}
