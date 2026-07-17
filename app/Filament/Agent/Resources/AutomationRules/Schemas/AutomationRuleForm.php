<?php

namespace App\Filament\Agent\Resources\AutomationRules\Schemas;

use App\Enums\AutomationActionType;
use App\Enums\AutomationTrigger;
use App\Models\WorkItemStatus;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AutomationRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Toggle::make('is_enabled')
                    ->default(true)
                    ->required(),
                Select::make('trigger')
                    ->options(AutomationTrigger::class)
                    ->enum(AutomationTrigger::class)
                    ->required()
                    ->native(false),
                Section::make('Conditions')
                    ->schema([
                        CheckboxList::make('conditions_json.statuses')
                            ->label('When status is')
                            ->options(fn (): array => static::statusOptions())
                            ->columns(2)
                            ->columnSpanFull(),
                    ]),
                Section::make('Actions')
                    ->schema([
                        Repeater::make('actions')
                            ->relationship()
                            ->orderColumn('sort_order')
                            ->schema([
                                Select::make('action_type')
                                    ->label('Action')
                                    ->options(AutomationActionType::class)
                                    ->enum(AutomationActionType::class)
                                    ->required()
                                    ->native(false)
                                    ->live(),
                                Select::make('action_config_json.status')
                                    ->label('Status')
                                    ->options(fn (): array => static::statusOptions())
                                    ->searchable()
                                    ->required(fn (Get $get): bool => $get('action_type') === AutomationActionType::SetStatus->value)
                                    ->visible(fn (Get $get): bool => $get('action_type') === AutomationActionType::SetStatus->value),
                                TextInput::make('action_config_json.subject')
                                    ->label('Email subject')
                                    ->maxLength(255)
                                    ->visible(fn (Get $get): bool => $get('action_type') === AutomationActionType::SendCustomerEmail->value),
                                Textarea::make('action_config_json.body')
                                    ->label('Email body')
                                    ->rows(4)
                                    ->required(fn (Get $get): bool => $get('action_type') === AutomationActionType::SendCustomerEmail->value)
                                    ->visible(fn (Get $get): bool => $get('action_type') === AutomationActionType::SendCustomerEmail->value)
                                    ->columnSpanFull(),
                                Toggle::make('action_config_json.move_reply_message')
                                    ->label('Move triggering reply to new ticket')
                                    ->default(true)
                                    ->visible(fn (Get $get): bool => $get('action_type') === AutomationActionType::CreateWorkItem->value),
                            ])
                            ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                                $data['tenant_id'] = Filament::getTenant()?->getKey();

                                return $data;
                            })
                            ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                                $data['tenant_id'] = Filament::getTenant()?->getKey();

                                return $data;
                            })
                            ->defaultItems(0)
                            ->addActionLabel('Add action')
                            ->columnSpanFull(),
                    ]),
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
