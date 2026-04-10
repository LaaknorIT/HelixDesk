<?php

namespace App\Filament\Agent\Resources\Organizations\RelationManagers;

use App\Filament\Agent\Resources\People\PeopleResource;
use App\Models\Department;
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

class OrganizationUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'People in this organization';

    protected static bool $shouldSkipAuthorization = true;

    public function form(Schema $schema): Schema
    {
        $organizationId = $this->ownerRecord->getKey();

        return $schema->components([
            Hidden::make('tenant_id')
                ->default(fn (): int => $this->ownerRecord->tenant_id),
            Hidden::make('organization_id')
                ->default($organizationId),
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
            Select::make('department_id')
                ->label('Department')
                ->options(fn (): array => Department::query()
                    ->where('organization_id', $organizationId)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
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
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('department.name')
                    ->label('Department')
                    ->placeholder('—'),
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
