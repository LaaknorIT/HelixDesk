<?php

namespace App\Filament\Agent\Resources\AutomationRules\Schemas;

use App\Enums\AutomationActionType;
use App\Enums\AutomationTrigger;
use App\Models\WorkItemStatus;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
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
                        Group::make([
                            CheckboxList::make('statuses')
                                ->label('When status is')
                                ->options(fn (): array => static::statusOptions())
                                ->columns(2)
                                ->columnSpanFull(),
                        ])
                            ->statePath('conditions_json')
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
                                Group::make([
                                    Select::make('status')
                                        ->label('Set status to')
                                        ->options(fn (): array => static::statusOptions())
                                        ->searchable()
                                        ->required(fn (Get $get): bool => static::isActionType(
                                            $get('../action_type'),
                                            AutomationActionType::SetStatus
                                        ))
                                        ->visible(fn (Get $get): bool => static::isActionType(
                                            $get('../action_type'),
                                            AutomationActionType::SetStatus
                                        )),
                                    TextInput::make('subject')
                                        ->label('Email subject')
                                        ->maxLength(255)
                                        ->visible(fn (Get $get): bool => static::isActionType(
                                            $get('../action_type'),
                                            AutomationActionType::SendCustomerEmail
                                        )),
                                    Textarea::make('body')
                                        ->label('Email body')
                                        ->rows(4)
                                        ->required(fn (Get $get): bool => static::isActionType(
                                            $get('../action_type'),
                                            AutomationActionType::SendCustomerEmail
                                        ))
                                        ->visible(fn (Get $get): bool => static::isActionType(
                                            $get('../action_type'),
                                            AutomationActionType::SendCustomerEmail
                                        ))
                                        ->columnSpanFull(),
                                    Toggle::make('move_reply_message')
                                        ->label('Move triggering reply to new ticket')
                                        ->default(true)
                                        ->visible(fn (Get $get): bool => static::isActionType(
                                            $get('../action_type'),
                                            AutomationActionType::CreateWorkItem
                                        )),
                                    Toggle::make('link_to_original')
                                        ->label('Link new ticket to original')
                                        ->default(true)
                                        ->visible(fn (Get $get): bool => static::isActionType(
                                            $get('../action_type'),
                                            AutomationActionType::CreateWorkItem
                                        )),
                                ])
                                    ->statePath('action_config_json')
                                    ->columns(1)
                                    ->columnSpanFull(),
                            ])
                            ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                                $data['tenant_id'] = Filament::getTenant()?->getKey();
                                $data['action_config_json'] = static::normalizeActionConfig($data);

                                return $data;
                            })
                            ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                                $data['tenant_id'] = Filament::getTenant()?->getKey();
                                $data['action_config_json'] = static::normalizeActionConfig($data);

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

    private static function isActionType(mixed $value, AutomationActionType $expected): bool
    {
        if ($value instanceof BackedEnum) {
            return $value->value === $expected->value;
        }

        return (string) $value === $expected->value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalizeActionConfig(array $data): array
    {
        $type = $data['action_type'] ?? null;
        if ($type instanceof BackedEnum) {
            $type = $type->value;
        }

        $config = $data['action_config_json'] ?? [];
        if (! is_array($config)) {
            $config = [];
        }

        return match ((string) $type) {
            AutomationActionType::SetStatus->value => [
                'status' => $config['status'] ?? null,
            ],
            AutomationActionType::SendCustomerEmail->value => [
                'subject' => $config['subject'] ?? null,
                'body' => $config['body'] ?? null,
            ],
            AutomationActionType::CreateWorkItem->value => [
                'move_reply_message' => (bool) ($config['move_reply_message'] ?? true),
                'link_to_original' => (bool) ($config['link_to_original'] ?? true),
            ],
            default => [],
        };
    }
}
