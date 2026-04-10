<?php

namespace App\Filament\Agent\Resources\Organizations\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DepartmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'departments';

    protected static bool $shouldSkipAuthorization = true;

    public function form(Schema $schema): Schema
    {
        $organizationId = $this->ownerRecord->getKey();

        return $schema->components([
            Hidden::make('tenant_id')
                ->default(fn (): int => $this->ownerRecord->tenant_id),
            Hidden::make('organization_id')
                ->default($organizationId),
            Select::make('parent_department_id')
                ->label('Parent department')
                ->relationship(
                    name: 'parent',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn (Builder $query): Builder => $query->where('organization_id', $organizationId),
                )
                ->searchable()
                ->preload()
                ->default(null),
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('code')
                ->maxLength(64)
                ->default(null),
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
                TextColumn::make('parent.name')
                    ->label('Parent dept.')
                    ->placeholder('—'),
                TextColumn::make('code')
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
