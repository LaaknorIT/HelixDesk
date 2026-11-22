<?php

namespace App\Filament\Agent\Resources\SlaPolicies\Schemas;

use App\Enums\SlaCalendarType;
use App\Enums\SlaTargetType;
use App\Enums\WorkItemPriority;
use App\Enums\WorkItemType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SlaPolicyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Toggle::make('is_active')
                    ->default(true)
                    ->required(),
                Select::make('target_type')
                    ->options(SlaTargetType::class)
                    ->enum(SlaTargetType::class)
                    ->required()
                    ->native(false),
                TextInput::make('first_response_minutes')
                    ->numeric()
                    ->minValue(1)
                    ->nullable(),
                TextInput::make('resolution_minutes')
                    ->numeric()
                    ->minValue(1)
                    ->nullable(),
                Select::make('calendar_type')
                    ->options(SlaCalendarType::class)
                    ->enum(SlaCalendarType::class)
                    ->default(SlaCalendarType::WorkingHours->value)
                    ->required()
                    ->native(false),
                Section::make(__('Matching criteria'))
                    ->schema([
                        Select::make('applies_to_priority')
                            ->label(__('Priority'))
                            ->options(WorkItemPriority::options())
                            ->placeholder(__('Any priority'))
                            ->nullable(),
                        Select::make('applies_to_organization_id')
                            ->label(__('Organization'))
                            ->relationship('organization', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Select::make('applies_to_category_id')
                            ->label(__('Category'))
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Select::make('applies_to_work_item_type')
                            ->label(__('Work item type'))
                            ->options(WorkItemType::class)
                            ->enum(WorkItemType::class)
                            ->nullable()
                            ->native(false),
                        TextInput::make('match_priority')
                            ->numeric()
                            ->default(0)
                            ->required()
                            ->helperText(__('Higher values win when multiple policies match.')),
                    ])
                    ->columns(2),
            ]);
    }
}
