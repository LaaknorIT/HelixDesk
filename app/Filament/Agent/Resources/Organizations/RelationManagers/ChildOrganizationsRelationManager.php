<?php

namespace App\Filament\Agent\Resources\Organizations\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChildOrganizationsRelationManager extends RelationManager
{
    protected static string $relationship = 'children';

    protected static ?string $title = 'Sub-organizations';

    protected static bool $shouldSkipAuthorization = true;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('tenant_id')
                ->default(fn (): int => $this->ownerRecord->tenant_id),
            Hidden::make('parent_organization_id')
                ->default(fn (): int => $this->ownerRecord->getKey()),
            TextInput::make('name')
                ->required()
                ->maxLength(255),
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
