<?php

namespace App\Filament\Agent\Resources\Organizations\Schemas;

use App\Enums\OrganizationType;
use App\Models\Organization;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class OrganizationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label('Type')
                    ->options(fn (Get $get): array => static::typeOptions($get('parent_id')))
                    ->enum(OrganizationType::class)
                    ->required()
                    ->native(false)
                    ->live(),
                Select::make('parent_id')
                    ->label('Parent')
                    ->options(fn (): array => Organization::selectArray())
                    ->searchable()
                    ->default(Organization::defaultParentKey())
                    ->live(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('code')
                    ->maxLength(64)
                    ->default(null),
                Toggle::make('is_billable')
                    ->label('Billable customer')
                    ->visible(fn (Get $get): bool => ($get('type') ?? OrganizationType::Customer->value) === OrganizationType::Customer->value)
                    ->default(true),
                TextInput::make('external_reference')
                    ->label('External reference')
                    ->maxLength(255)
                    ->default(null),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function typeOptions(mixed $parentId): array
    {
        $parentType = null;

        if ($parentId !== null && $parentId !== '' && (int) $parentId !== Organization::defaultParentKey()) {
            $parent = Organization::query()->find((int) $parentId);
            $parentType = $parent?->type;
        }

        return collect(OrganizationType::allowedForParent($parentType))
            ->mapWithKeys(fn (OrganizationType $type): array => [$type->value => $type->label()])
            ->all();
    }
}
