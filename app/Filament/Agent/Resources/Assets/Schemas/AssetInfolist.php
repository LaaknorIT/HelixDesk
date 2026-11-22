<?php

namespace App\Filament\Agent\Resources\Assets\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AssetInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Asset details'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('assetType.name')
                            ->label(__('Type')),
                        TextEntry::make('asset_identifier')
                            ->label(__('Asset tag'))
                            ->placeholder(__('—')),
                        TextEntry::make('organization.name')
                            ->label(__('Organization'))
                            ->placeholder(__('—')),
                        TextEntry::make('lifecycle_status')
                            ->label(__('Lifecycle'))
                            ->badge(),
                        TextEntry::make('owner.name')
                            ->label(__('Owner'))
                            ->placeholder(__('—')),
                        TextEntry::make('serial_number')
                            ->placeholder(__('—')),
                        TextEntry::make('purchase_date')
                            ->date()
                            ->placeholder(__('—')),
                        TextEntry::make('warranty_date')
                            ->date()
                            ->placeholder(__('—')),
                    ]),
                Section::make(__('Integration inventory'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('primary_hostname')
                            ->placeholder(__('—')),
                        TextEntry::make('operating_system')
                            ->placeholder(__('—')),
                        TextEntry::make('identity_resolution_status')
                            ->label(__('Identity status'))
                            ->badge(),
                        TextEntry::make('last_inventory_sync_at')
                            ->label(__('Last inventory sync'))
                            ->dateTime()
                            ->placeholder(__('—')),
                    ]),
            ]);
    }
}
