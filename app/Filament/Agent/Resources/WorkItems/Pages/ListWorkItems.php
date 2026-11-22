<?php

namespace App\Filament\Agent\Resources\WorkItems\Pages;

use App\Enums\WorkItemPriority;
use App\Enums\WorkItemType;
use App\Filament\Agent\Resources\WorkItems\Tables\WorkItemsTable;
use App\Filament\Agent\Resources\WorkItems\WorkItemResource;
use App\Models\Organization;
use App\Models\Queue;
use App\Models\User;
use App\Models\WorkItemCategory;
use App\Services\WorkItemWorkflowService;
use App\Support\HelixdeskWorkItemAuthorization;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ListWorkItems extends ListRecords
{
    protected static string $resource = WorkItemResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $userId = Auth::id();

        return [
            'mine' => Tab::make(__('Mine'))
                ->icon(Heroicon::OutlinedUser)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->open()->where('assigned_agent_id', $userId))
                ->badge(fn (): int => $this->countFor(fn (Builder $query): Builder => $query->open()->where('assigned_agent_id', $userId))),
            'unassigned' => Tab::make(__('Unassigned'))
                ->icon(Heroicon::OutlinedInbox)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->open()->whereNull('assigned_agent_id'))
                ->badge(fn (): int => $this->countFor(fn (Builder $query): Builder => $query->open()->whereNull('assigned_agent_id')))
                ->badgeColor('warning'),
            'open' => Tab::make(__('Open'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->open()),
            'all' => Tab::make(__('All')),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'open';
    }

    /**
     * @param  callable(Builder): Builder  $constraint
     */
    protected function countFor(callable $constraint): int
    {
        return $constraint(WorkItemResource::getEloquentQuery())->count();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createWorkItem')
                ->label(__('New ticket'))
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading(__('New ticket'))
                ->modalSubmitActionLabel(__('Create ticket'))
                ->modalWidth('3xl')
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2])
                        ->schema([
                            Select::make('type')
                                ->label(__('Type'))
                                ->options(WorkItemType::options())
                                ->default(WorkItemType::Incident->value)
                                ->required(),
                            Select::make('priority')
                                ->label(__('Priority'))
                                ->options(WorkItemPriority::options())
                                ->default(WorkItemPriority::Normal->value)
                                ->required(),
                            Select::make('organization_id')
                                ->label(__('Customer'))
                                ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(fn (Set $set) => $set('reported_by_user_id', null)),
                            Select::make('reported_by_user_id')
                                ->label(__('Requester'))
                                ->helperText(__('Who the ticket is for. Leave empty to register it in your own name.'))
                                ->options(fn (Get $get): array => static::requesterOptions($get('organization_id')))
                                ->searchable(),
                        ]),
                    TextInput::make('title')
                        ->label(__('Title'))
                        ->required()
                        ->maxLength(255),
                    Textarea::make('description')
                        ->label(__('Description'))
                        ->rows(5)
                        ->required(),
                    Grid::make(['default' => 1, 'sm' => 3])
                        ->schema([
                            Select::make('category_id')
                                ->label(__('Category'))
                                ->options(fn (): array => WorkItemCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all())
                                ->placeholder(__('No category')),
                            Select::make('queue_id')
                                ->label(__('Queue'))
                                ->options(fn (): array => static::queueOptions())
                                ->placeholder(__('General queue')),
                            Select::make('assigned_agent_id')
                                ->label(__('Assign to'))
                                ->options(fn (): array => WorkItemsTable::agentOptions())
                                ->placeholder(__('Unassigned'))
                                ->default(fn (): ?int => Auth::id())
                                ->searchable(),
                        ]),
                ])
                ->action(function (array $data): void {
                    $user = Auth::user();

                    if (! $user instanceof User) {
                        return;
                    }

                    try {
                        $workItem = app(WorkItemWorkflowService::class)->createWorkItem($data, $user);
                    } catch (AccessDeniedHttpException) {
                        Notification::make()
                            ->title(__('You may not create tickets in this queue'))
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('Ticket #:id created', ['id' => $workItem->getKey()]))
                        ->success()
                        ->send();

                    $this->redirect(WorkItemResource::getUrl('view', ['record' => $workItem]));
                }),
        ];
    }

    /**
     * End users of the chosen customer, or every end user when no customer is chosen.
     *
     * @return array<int, string>
     */
    protected static function requesterOptions(mixed $organizationId): array
    {
        return User::query()
            ->where('tenant_id', Auth::user()?->operatingTenantId())
            ->where('type', 'end_user')
            ->when(filled($organizationId), fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(fn (User $user): array => [$user->getKey() => $user->name.' ('.$user->email.')'])
            ->all();
    }

    /**
     * Queues the agent may create tickets in.
     *
     * @return array<int, string>
     */
    protected static function queueOptions(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        return Queue::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->filter(fn (Queue $queue): bool => HelixdeskWorkItemAuthorization::hasWorkItemPermission($user, 'create', (int) $queue->getKey()))
            ->pluck('name', 'id')
            ->all();
    }
}
