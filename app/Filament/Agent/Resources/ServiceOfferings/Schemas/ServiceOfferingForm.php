<?php

namespace App\Filament\Agent\Resources\ServiceOfferings\Schemas;

use App\Enums\OrganizationType;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ServiceOfferingForm
{
    public static function configure(Schema $schema): Schema
    {
        $kindLabels = collect(config('helixdesk_service_offering.kinds', []))
            ->mapWithKeys(fn (array $def, string $key): array => [$key => $def['label'] ?? Str::headline($key)])
            ->all();

        return $schema
            ->components([
                Select::make('organizations')
                    ->label('Organizations')
                    ->relationship(
                        name: 'organizations',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query
                            ->whereIn('type', [
                                OrganizationType::Customer->value,
                                OrganizationType::Group->value,
                            ])
                            ->orderBy('name'),
                    )
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->required()
                    ->helperText('Explicit assignment only — groups do not inherit to child customers.')
                    ->default(function (): ?array {
                        $organizationId = request()->query('organization_id');

                        return filled($organizationId) ? [(int) $organizationId] : null;
                    }),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('code')
                    ->label('Code')
                    ->maxLength(255)
                    ->default(null),
                Select::make('kind')
                    ->options($kindLabels)
                    ->required()
                    ->native(false),
                KeyValue::make('attributes_json')
                    ->label('Service attributes')
                    ->keyLabel('Key')
                    ->valueLabel('Value')
                    ->addActionLabel('Add attribute')
                    ->default([])
                    ->columnSpanFull(),
            ]);
    }
}
