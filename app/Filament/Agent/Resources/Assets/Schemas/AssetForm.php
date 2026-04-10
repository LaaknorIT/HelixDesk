<?php

namespace App\Filament\Agent\Resources\Assets\Schemas;

use App\Models\Department;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class AssetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('organization_id')
                    ->label('Organization')
                    ->relationship('organization', 'name')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->default(null),
                Select::make('department_id')
                    ->label('Department')
                    ->options(function (Get $get): array {
                        $orgId = $get('organization_id');
                        if ($orgId === null || $orgId === '') {
                            return [];
                        }

                        return Department::query()
                            ->where('organization_id', $orgId)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->searchable()
                    ->preload()
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
