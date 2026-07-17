<?php

namespace App\Filament\Agent\Resources\WorkflowTransitions\Schemas;

use App\Models\WorkItemStatus;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class WorkflowTransitionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('from_status')
                    ->label('From')
                    ->options(fn (): array => static::statusOptions())
                    ->required()
                    ->searchable(),
                Select::make('to_status')
                    ->label('To')
                    ->options(fn (): array => static::statusOptions())
                    ->required()
                    ->searchable(),
                TextInput::make('required_permission')
                    ->label('Required permission')
                    ->helperText('Leave empty to allow end users (e.g. close). Agents typically need work_item.update.')
                    ->placeholder('work_item.update')
                    ->maxLength(255)
                    ->nullable(),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $tenant = Filament::getTenant();
        if ($tenant === null) {
            return [];
        }

        return WorkItemStatus::query()
            ->where('tenant_id', $tenant->getKey())
            ->orderBy('sort_order')
            ->pluck('name', 'name')
            ->all();
    }
}
