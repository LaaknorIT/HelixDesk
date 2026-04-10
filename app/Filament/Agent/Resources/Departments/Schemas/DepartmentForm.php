<?php

namespace App\Filament\Agent\Resources\Departments\Schemas;

use App\Models\Department;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class DepartmentForm
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
                    ->required()
                    ->live(),
                Select::make('parent_department_id')
                    ->label('Parent department')
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
                TextInput::make('code')
                    ->maxLength(64)
                    ->default(null),
                TextInput::make('external_reference')
                    ->label('External reference')
                    ->maxLength(255)
                    ->default(null),
            ]);
    }
}
