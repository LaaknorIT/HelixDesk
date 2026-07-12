<?php

namespace App\Filament\Agent\Resources\Organizations\RelationManagers;

use App\Enums\OrganizationType;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChildOrganizationsRelationManager extends RelationManager
{
    protected static string $relationship = 'children';

    protected static ?string $title = 'Child organizations';

    protected static bool $shouldSkipAuthorization = true;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('tenant_id')
                ->default(fn (): int => $this->ownerRecord->tenant_id),
            Hidden::make('parent_id')
                ->default(fn (): int => $this->ownerRecord->getKey()),
            Select::make('type')
                ->label('Type')
                ->options(fn (): array => collect(OrganizationType::allowedForParent($this->ownerRecord->type))
                    ->mapWithKeys(fn (OrganizationType $type): array => [$type->value => $type->label()])
                    ->all())
                ->enum(OrganizationType::class)
                ->required()
                ->native(false)
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

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (OrganizationType $state): string => $state->label()),
                TextColumn::make('external_reference')
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('tenant.admin') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('tenant.admin') ?? false),
                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('tenant.admin') ?? false),
            ]);
    }
}
