<?php

namespace App\Filament\EndUser\Actions;

use App\Enums\WorkItemPriority;
use App\Enums\WorkItemType;
use App\Filament\EndUser\Resources\WorkItems\EndUserWorkItemResource;
use App\Models\User;
use App\Models\WorkItemCategory;
use App\Services\WorkItemWorkflowService;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * "Report an issue" for end users, in plain words: what kind of help, what is wrong, and who is affected.
 * The answer to "who is affected" sets the priority, so users do not have to judge priority themselves.
 */
class CreateTicketAction
{
    public const IMPACT_ME = 'me';

    public const IMPACT_SEVERAL = 'several';

    public const IMPACT_EVERYONE = 'everyone';

    public static function make(string $name = 'createWorkItem'): Action
    {
        return Action::make($name)
            ->label(__('Report an issue'))
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading(__('Report an issue'))
            ->modalDescription(__('Tell us what you need. You can follow the ticket and reply to us under My tickets.'))
            ->modalSubmitActionLabel(__('Submit ticket'))
            ->modalWidth('2xl')
            ->schema([
                Radio::make('type')
                    ->label(__('What do you need help with?'))
                    ->options([
                        WorkItemType::Incident->value => __('Something is not working'),
                        WorkItemType::ServiceRequest->value => __('I need something (access, equipment, software)'),
                    ])
                    ->default(WorkItemType::Incident->value)
                    ->required(),
                TextInput::make('title')
                    ->label(__('Short summary'))
                    ->placeholder(__('For example: Cannot print from my laptop'))
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->label(__('Description'))
                    ->helperText(__('What happened, what did you expect, and any error message you saw.'))
                    ->rows(5)
                    ->required(),
                Radio::make('impact')
                    ->label(__('Who is affected?'))
                    ->options([
                        self::IMPACT_ME => __('Only me'),
                        self::IMPACT_SEVERAL => __('Several people'),
                        self::IMPACT_EVERYONE => __('The whole organization'),
                    ])
                    ->default(self::IMPACT_ME)
                    ->inline()
                    ->required(),
                Select::make('category_id')
                    ->label(__('Category'))
                    ->placeholder(__('Not sure'))
                    ->options(fn (): array => WorkItemCategory::query()
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->map(fn (string $name): string => __($name))
                        ->all())
                    ->visible(fn (): bool => WorkItemCategory::query()->where('is_active', true)->exists()),
            ])
            ->action(function (array $data, Action $action): void {
                $user = Auth::user();

                if (! $user instanceof User) {
                    return;
                }

                $workItem = app(WorkItemWorkflowService::class)->createWorkItem([
                    'type' => $data['type'] ?? WorkItemType::Incident->value,
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'priority' => static::priorityForImpact($data['impact'] ?? self::IMPACT_ME)->value,
                    'category_id' => $data['category_id'] ?? null,
                ], $user);

                Notification::make()
                    ->title(__('Ticket #:id submitted', ['id' => $workItem->getKey()]))
                    ->body(__('Use this number if you contact us about it. We will reply here and by email.'))
                    ->success()
                    ->send();

                $action->redirect(EndUserWorkItemResource::getUrl('view', ['record' => $workItem]));
            });
    }

    public static function priorityForImpact(string $impact): WorkItemPriority
    {
        return match ($impact) {
            self::IMPACT_EVERYONE => WorkItemPriority::Urgent,
            self::IMPACT_SEVERAL => WorkItemPriority::High,
            default => WorkItemPriority::Normal,
        };
    }
}
