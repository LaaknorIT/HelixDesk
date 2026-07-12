<?php

namespace App\Filament\Agent\Resources\Assets\Schemas;

use App\Models\Organization;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class AssetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('organization_id')
                    ->label('Organization scope')
                    ->options(fn (): array => Organization::selectArray())
                    ->searchable()
                    ->default(null),
                Select::make('asset_type_id')
                    ->label('Type')
                    ->relationship('assetType', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('asset_identifier')
                    ->label('Asset tag / ID')
                    ->maxLength(255)
                    ->default(null),
                TextInput::make('serial_number')
                    ->maxLength(255)
                    ->default(null),
                DatePicker::make('purchase_date'),
                DatePicker::make('warranty_date'),
                Select::make('lifecycle_status')
                    ->required()
                    ->options([
                        'in_use' => 'In use',
                        'in_stock' => 'In stock',
                        'retired' => 'Retired',
                    ])
                    ->default('in_use'),
                Select::make('owner_user_id')
                    ->label('Owner (user)')
                    ->relationship(
                        name: 'owner',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->where(
                            'tenant_id',
                            Filament::getTenant()->getKey()
                        ),
                    )
                    ->searchable()
                    ->preload()
                    ->default(null),
            ]);
    }
}
