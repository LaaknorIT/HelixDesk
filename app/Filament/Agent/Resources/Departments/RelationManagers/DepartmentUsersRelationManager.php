<?php

namespace App\Filament\Agent\Resources\Departments\RelationManagers;

use App\Filament\Agent\Resources\People\PeopleResource;
use App\Models\User;
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

class DepartmentUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'People in this department';

    protected static bool $shouldSkipAuthorization = true;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('tenant_id')
                ->default(fn (): int => $this->ownerRecord->tenant_id),
            Hidden::make('organization_id')
                ->default(fn (): int => $this->ownerRecord->organization_id),
            Hidden::make('department_id')
                ->default(fn (): int => $this->ownerRecord->getKey()),
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->email()
                ->required()
                ->maxLength(255),
            TextInput::make('password')
                ->password()
                ->revealable()
                ->required()
                ->maxLength(255),
            Select::make('type')
                ->options([
                    'agent' => 'Agent',
                    'end_user' => 'End user',
                ])
                ->required()
                ->native(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('type')
                    ->badge(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->after(function (User $record): void {
                        if ($record->type !== 'agent') {
                            return;
                        }

                        setPermissionsTeamId((int) $record->tenant_id);

                        try {
                            $record->syncRoles(['Agent']);
                        } finally {
                            setPermissionsTeamId(null);
                        }
                    })
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('tenant.admin') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->url(fn (User $record): string => PeopleResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('tenant.admin') ?? false),
                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('tenant.admin') ?? false),
            ]);
    }
}
