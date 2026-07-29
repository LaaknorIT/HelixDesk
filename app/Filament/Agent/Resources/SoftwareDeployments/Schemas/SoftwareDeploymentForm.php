<?php

namespace App\Filament\Agent\Resources\SoftwareDeployments\Schemas;

use App\Enums\OrganizationType;
use App\Enums\SoftwareDeploymentEnvironment;
use App\Enums\SoftwareDeploymentStatus;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class SoftwareDeploymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('software_product_id')
                    ->label('Software product')
                    ->relationship(
                        name: 'softwareProduct',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true)->orderBy('name'),
                    )
                    ->searchable()
                    ->preload()
                    ->required()
                    ->default(fn (): mixed => request()->query('software_product_id')),
                Select::make('organization_id')
                    ->label('Organization')
                    ->relationship(
                        name: 'organization',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query
                            ->whereIn('type', [
                                OrganizationType::Customer->value,
                                OrganizationType::Group->value,
                            ])
                            ->orderBy('name'),
                    )
                    ->searchable()
                    ->preload()
                    ->required()
                    ->default(fn (): mixed => request()->query('organization_id')),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('environment')
                    ->options(collect(SoftwareDeploymentEnvironment::cases())
                        ->mapWithKeys(fn (SoftwareDeploymentEnvironment $case): array => [
                            $case->value => $case->label(),
                        ])
                        ->all())
                    ->native(false)
                    ->nullable(),
                Select::make('status')
                    ->options(collect(SoftwareDeploymentStatus::cases())
                        ->mapWithKeys(fn (SoftwareDeploymentStatus $case): array => [
                            $case->value => $case->label(),
                        ])
                        ->all())
                    ->required()
                    ->native(false)
                    ->default(SoftwareDeploymentStatus::Active->value),
                TextInput::make('external_tenant_id')
                    ->label('External tenant ID')
                    ->maxLength(255),
                TextInput::make('admin_url')
                    ->label('Admin URL')
                    ->url()
                    ->maxLength(255),
                Textarea::make('notes')
                    ->columnSpanFull(),
                KeyValue::make('attributes_json')
                    ->label('Attributes')
                    ->keyLabel('Key')
                    ->valueLabel('Value')
                    ->addActionLabel('Add attribute')
                    ->default([])
                    ->columnSpanFull(),
            ]);
    }
}
