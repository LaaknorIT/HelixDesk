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
                Section::make('Asset details')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('assetType.name')
                            ->label('Type'),
                        TextEntry::make('asset_identifier')
                            ->label('Asset tag')
                            ->placeholder('—'),
                        TextEntry::make('organization.name')
                            ->label('Organization')
                            ->placeholder('—'),
                        TextEntry::make('lifecycle_status')
                            ->label('Lifecycle')
                            ->badge(),
                        TextEntry::make('owner.name')
                            ->label('Owner')
                            ->placeholder('—'),
                        TextEntry::make('serial_number')
                            ->placeholder('—'),
                        TextEntry::make('purchase_date')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('warranty_date')
                            ->date()
                            ->placeholder('—'),
                    ]),
                Section::make('Integration inventory')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('primary_hostname')
                            ->placeholder('—'),
                        TextEntry::make('operating_system')
                            ->placeholder('—'),
                        TextEntry::make('identity_resolution_status')
                            ->label('Identity status')
                            ->badge(),
                        TextEntry::make('last_inventory_sync_at')
                            ->label('Last inventory sync')
                            ->dateTime()
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
