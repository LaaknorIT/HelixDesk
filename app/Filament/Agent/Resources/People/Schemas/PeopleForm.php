<?php

namespace App\Filament\Agent\Resources\People\Schemas;

use App\Models\Department;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class PeopleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('organization_id')
                    ->label('Organization')
                    ->relationship(
                        name: 'organization',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->where(
                            'tenant_id',
                            Filament::getTenant()->getKey(),
                        ),
                    )
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
                    ->maxLength(255)
                    ->dehydrated(fn (?string $state): bool => filled($state)),
                Select::make('type')
                    ->options([
                        'agent' => 'Agent',
                        'end_user' => 'End user',
                    ])
                    ->required()
                    ->native(false),
            ]);
    }
}
