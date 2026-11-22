<?php

namespace App\Filament\Agent\Resources\WorkItems\Pages;

use App\Enums\WorkItemPriority;
use App\Filament\Agent\Resources\WorkItems\Schemas\WorkItemInfolist;
use App\Filament\Agent\Resources\WorkItems\WorkItemResource;
use App\Models\CannedResponse;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Services\WorkItems\CannedResponseRenderer;
use App\Services\WorkItems\WorkItemCollaborationService;
use App\Services\WorkItemWorkflowService;
use App\Support\HelixdeskWorkItemAuthorization;
use App\Support\WorkItemQueueScope;
use App\Support\WorkItemStatusDisplay;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @property-read Schema $workZoneForm
 */
class ViewWorkItem extends ViewRecord
{
    protected static string $resource = WorkItemResource::class;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $workZoneData = [];

    #[Url(as: 'tab')]
    public ?string $activeMetaTab = 'details';

    /**
     * Newest message the agent has seen, for the stale reply guard.
     */
    #[Locked]
    public int $lastSeenMessageId = 0;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->workZoneForm->fill($this->defaultWorkZoneState());
        $this->lastSeenMessageId = $this->latestMessageId();
    }

    public function hasInlineLabels(): bool
    {
        return true;
    }

    public function getTitle(): string|Htmlable
    {
        return '#'.$this->getRecord()->getKey().' · '.$this->getRecord()->title;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getInfolistContentComponent(),
        ]);
    }

    public function getRelationManagers(): array
    {
        return [];
    }

    public function infolist(Schema $schema): Schema
    {
        return WorkItemInfolist::configure($schema, $this);
    }

    public function defaultWorkZoneForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('workZoneData')
            ->columns(1);
    }

    public function workZoneForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Quick actions'))
                    ->schema([
                        // Each change is saved right away, so there is no separate Apply button.
                        Select::make('to_status')
                            ->label(__('Change status'))
                            ->placeholder(fn (): string => __('Now: :status', ['status' => WorkItemStatusDisplay::label($this->getRecord()->status)]))
                            ->options(fn (): array => $this->availableStatusOptions())
                            ->live()
                            ->afterStateUpdated(fn () => $this->applyQuickActions()),
                        Select::make('priority')
                            ->label(__('Priority'))
                            ->options(fn (): array => $this->priorityOptions())
                            ->selectablePlaceholder(false)
                            ->live()
                            ->afterStateUpdated(fn () => $this->applyQuickActions()),
                        Select::make('assigned_agent_id')
                            ->label(__('Assigned to'))
                            ->placeholder(__('Unassigned'))
                            ->options(fn (): array => $this->agentOptions())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn () => $this->applyQuickActions()),
                    ])
                    ->columns(['default' => 1, 'sm' => 3]),
                Section::make(__('Compose response'))
                    ->schema([
                        Select::make('canned_response_id')
                            ->label(__('Canned response'))
                            ->placeholder(__('Insert a canned response…'))
                            ->options(fn (): array => $this->cannedResponseOptions())
                            ->searchable()
                            ->live()
                            ->dehydrated(false)
                            ->visible(fn (): bool => $this->cannedResponseOptions() !== [])
                            ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                if (blank($state)) {
                                    return;
                                }

                                $text = $this->renderCannedResponse((int) $state);
                                $set('canned_response_id', null);

                                if ($text === null) {
                                    return;
                                }

                                $current = rtrim((string) $get('body'));
                                $set('body', $current === '' ? $text : $current."\n\n".$text);
                            }),
                        Textarea::make('body')
                            ->label(__('Message'))
                            ->placeholder(__('Type your reply to the requester…'))
                            ->required()
                            ->rows(5),
                        Grid::make(['default' => 1, 'sm' => 2])
                            ->schema([
                                Toggle::make('is_internal')
                                    ->label(__('Internal note (not visible to requester)'))
                                    ->live(),
                                Select::make('status_after_reply')
                                    ->label(__('Then set status to'))
                                    ->placeholder(__('Keep current status'))
                                    ->options(fn (): array => $this->availableStatusOptions()),
                            ]),
                        Actions::make([
                            Action::make('sendReply')
                                ->label(fn (Get $get): string => $get('is_internal') ? __('Add internal note') : __('Send reply'))
                                ->icon(Heroicon::OutlinedPaperAirplane)
                                ->action('sendReply'),
                        ]),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('takeTicket')
                ->label(__('Take ticket'))
                ->icon(Heroicon::OutlinedHandRaised)
                ->visible(fn (): bool => $this->getRecord()->assigned_agent_id === null && $this->canUpdateWorkItem())
                ->action(function (): void {
                    $this->getRecord()->forceFill(['assigned_agent_id' => auth()->id()])->save();
                    $this->getRecord()->refresh();
                    $this->workZoneForm->fill($this->defaultWorkZoneState());

                    Notification::make()
                        ->title(__('The ticket is assigned to you'))
                        ->success()
                        ->send();
                }),
            Action::make('watch')
                ->label(__('Watch'))
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->visible(fn (): bool => ! $this->isWatching())
                ->action(fn () => $this->toggleWatch(true)),
            Action::make('unwatch')
                ->label(__('Stop watching'))
                ->icon(Heroicon::OutlinedEyeSlash)
                ->color('gray')
                ->visible(fn (): bool => $this->isWatching())
                ->action(fn () => $this->toggleWatch(false)),
            ActionGroup::make([
                Action::make('manageWatchers')
                    ->label(__('Watchers'))
                    ->icon(Heroicon::OutlinedUserGroup)
                    ->modalDescription(__('Watchers get customer replies and SLA warnings for this ticket.'))
                    ->fillForm(fn (): array => [
                        'watcher_ids' => $this->getRecord()->watchers()->pluck('users.id')->all(),
                    ])
                    ->schema([
                        Select::make('watcher_ids')
                            ->label(__('Watchers'))
                            ->multiple()
                            ->searchable()
                            ->options(fn (): array => $this->agentOptions()),
                    ])
                    ->visible(fn (): bool => $this->canUpdateWorkItem())
                    ->action(fn (array $data) => $this->syncWatchers(array_map('intval', $data['watcher_ids'] ?? []))),
                Action::make('editCc')
                    ->label(__('CC recipients'))
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->modalDescription(__('Public replies to the requester are also sent to these addresses.'))
                    ->fillForm(fn (): array => ['cc_addresses' => $this->getRecord()->ccAddressList()])
                    ->schema([
                        TagsInput::make('cc_addresses')
                            ->label(__('CC addresses'))
                            ->placeholder(__('name@example.com'))
                            ->nestedRecursiveRules(['email:rfc', 'max:255']),
                    ])
                    ->visible(fn (): bool => $this->canUpdateWorkItem())
                    ->action(fn (array $data) => $this->saveCcAddresses($data['cc_addresses'] ?? [])),
                Action::make('linkTicket')
                    ->label(__('Link ticket'))
                    ->icon(Heroicon::OutlinedLink)
                    ->schema([
                        $this->ticketSelect('linked_work_item_id', __('Ticket')),
                    ])
                    ->visible(fn (): bool => $this->canUpdateWorkItem())
                    ->action(fn (array $data) => $this->linkTicket((int) $data['linked_work_item_id'])),
                Action::make('unlinkTicket')
                    ->label(__('Remove link'))
                    ->icon(Heroicon::OutlinedLinkSlash)
                    ->schema([
                        Select::make('linked_work_item_id')
                            ->label(__('Ticket'))
                            ->required()
                            ->options(fn (): array => $this->getRecord()->linkedWorkItems()
                                ->mapWithKeys(fn (WorkItem $workItem): array => [$workItem->getKey() => static::ticketLabel($workItem)])
                                ->all()),
                    ])
                    ->visible(fn (): bool => $this->canUpdateWorkItem() && $this->getRecord()->linkedWorkItems()->isNotEmpty())
                    ->action(fn (array $data) => $this->unlinkTicket((int) $data['linked_work_item_id'])),
                Action::make('mergeTicket')
                    ->label(__('Merge into another ticket'))
                    ->icon(Heroicon::OutlinedArrowsPointingIn)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Merge this ticket'))
                    ->modalDescription(__('All messages move to the ticket you pick, and this ticket is closed. This cannot be undone.'))
                    ->modalSubmitActionLabel(__('Merge'))
                    ->schema([
                        $this->ticketSelect('target_work_item_id', __('Merge into')),
                    ])
                    ->visible(fn (): bool => $this->canUpdateWorkItem() && $this->getRecord()->merged_into_work_item_id === null)
                    ->action(fn (array $data) => $this->mergeInto((int) $data['target_work_item_id'])),
            ])
                ->label(__('More'))
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->button()
                ->color('gray'),
        ];
    }

    public static function ticketLabel(WorkItem $workItem): string
    {
        return '#'.$workItem->getKey().' '.mb_substr((string) $workItem->title, 0, 80);
    }

    public function applyQuickActions(): void
    {
        $data = $this->workZoneForm->getState();
        $user = auth()->user();
        $record = $this->getRecord();

        if ($user === null) {
            return;
        }

        $workflow = app(WorkItemWorkflowService::class);
        $updated = false;

        if (filled($data['to_status'] ?? null) && $data['to_status'] !== $record->status) {
            $workflow->transition($record, $data['to_status'], $user);
            $record->refresh();
            $updated = true;
        }

        if (array_key_exists('priority', $data) && $data['priority'] !== null && $data['priority'] !== $record->priority) {
            $record->priority = $data['priority'];
            $record->save();
            $updated = true;
        }

        if (array_key_exists('assigned_agent_id', $data)) {
            $newAssignee = $data['assigned_agent_id'] !== '' ? $data['assigned_agent_id'] : null;

            if ((int) ($record->assigned_agent_id ?? 0) !== (int) ($newAssignee ?? 0)) {
                if (! $this->canUpdateWorkItem()) {
                    Notification::make()
                        ->title(__('Not allowed to update this ticket'))
                        ->danger()
                        ->send();

                    return;
                }

                $record->assigned_agent_id = $newAssignee;
                $record->save();
                $updated = true;
            }
        }

        if ($updated) {
            Notification::make()
                ->title(__('Ticket updated'))
                ->success()
                ->send();
        }

        $this->getRecord()->refresh();
        $this->workZoneForm->fill($this->defaultWorkZoneState());
    }

    public function sendReply(): void
    {
        $data = $this->workZoneForm->getState();
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $body = trim((string) ($data['body'] ?? ''));

        if ($body === '') {
            Notification::make()
                ->title(__('Message is required'))
                ->danger()
                ->send();

            return;
        }

        $workflow = app(WorkItemWorkflowService::class);
        $record = $this->getRecord();
        $isInternal = (bool) ($data['is_internal'] ?? false);
        $parentMessageId = null;

        if (! $isInternal && $this->warnAboutNewMessages($user)) {
            return;
        }

        try {
            if ($isInternal) {
                $workflow->addInternalNote($record, $body, $user);
            } else {
                $workflow->addMessage($record, $body, $user, $parentMessageId);
            }
        } catch (AccessDeniedHttpException) {
            $queueLabel = $record->queue?->name ?? ('#'.$record->queue_id);
            $canOperate = $record->tenant_id !== null && $user->canOperateInTenant((int) $record->tenant_id);
            $body = $canOperate
                ? __('Your role needs work item Update (or Admin) on queue ":queue" under Administration → Roles → Work item permissions by queue.', ['queue' => $queueLabel])
                : __('You do not have access to operate in this tenant. Global admins need an explicit Tenant Admin assignment for this tenant.');

            Notification::make()
                ->title($isInternal ? __('Not allowed to add an internal note') : __('Not allowed to reply on this ticket'))
                ->body($body)
                ->danger()
                ->persistent()
                ->send();

            return;
        } catch (UnprocessableEntityHttpException|NotFoundHttpException $e) {
            Notification::make()
                ->title(__('Could not save message'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title(__('Could not save message'))
                ->body(__('An unexpected error occurred. Please try again or check the application log.'))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $statusAfterReply = $data['status_after_reply'] ?? null;
        $statusChanged = false;

        if (filled($statusAfterReply) && $statusAfterReply !== $record->status) {
            try {
                $workflow->transition($record, (string) $statusAfterReply, $user);
                $statusChanged = true;
            } catch (\Throwable $e) {
                Notification::make()
                    ->title(__('The message was saved, but the status could not be changed'))
                    ->body($e->getMessage())
                    ->warning()
                    ->send();
            }
        }

        Notification::make()
            ->title($isInternal ? __('Internal note added') : __('Reply saved'))
            ->body(match (true) {
                $statusChanged => __('Status changed to :status.', ['status' => WorkItemStatusDisplay::label((string) $statusAfterReply)]),
                $isInternal => null,
                default => __('The requester gets it by email when email is set up for this queue.'),
            })
            ->success()
            ->send();

        $this->getRecord()->refresh();
        $this->lastSeenMessageId = $this->latestMessageId();
        $this->workZoneForm->fill([
            ...$this->defaultWorkZoneState(),
            'body' => null,
            'is_internal' => false,
            'status_after_reply' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultWorkZoneState(): array
    {
        $record = $this->getRecord();

        return [
            'to_status' => null,
            'canned_response_id' => null,
            'priority' => $record->priority,
            'assigned_agent_id' => $record->assigned_agent_id,
            'body' => null,
            'is_internal' => false,
            'status_after_reply' => null,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function availableStatusOptions(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        return app(WorkItemWorkflowService::class)->availableStatusOptions($this->getRecord(), $user);
    }

    /**
     * @return array<int, string>
     */
    protected function agentOptions(): array
    {
        /** @var WorkItem $record */
        $record = $this->getRecord();

        return User::query()
            ->where('tenant_id', $record->tenant_id)
            ->where('type', 'agent')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Priorities are normalized on save, but a value from before that migration or from an external system
     * stays selectable so the form never refuses to submit.
     *
     * @return array<string, string>
     */
    protected function priorityOptions(): array
    {
        $options = WorkItemPriority::options();
        $current = (string) $this->getRecord()->priority;

        if ($current !== '' && ! array_key_exists($current, $options)) {
            $options = [$current => WorkItemPriority::labelFor($current), ...$options];
        }

        return $options;
    }

    /**
     * Stop a public reply once when someone else has replied since the agent last looked.
     */
    protected function warnAboutNewMessages(User $user): bool
    {
        $newMessages = $this->getRecord()->messages()
            ->with('author')
            ->where('id', '>', $this->lastSeenMessageId)
            ->where('is_internal', false)
            ->where(fn ($query) => $query->whereNull('author_user_id')->orWhere('author_user_id', '!=', $user->getKey()))
            ->orderBy('id')
            ->get();

        $this->lastSeenMessageId = $this->latestMessageId();

        if ($newMessages->isEmpty()) {
            return false;
        }

        $authors = $newMessages
            ->map(fn (WorkItemMessage $message): string => $message->author?->name ?? __('Someone'))
            ->unique()
            ->implode(', ');

        Notification::make()
            ->title(__('New reply since you opened this ticket'))
            ->body(__(':authors added a message. Your reply was not sent; read the conversation, then send it again if it still fits.', ['authors' => $authors]))
            ->warning()
            ->persistent()
            ->send();

        return true;
    }

    protected function latestMessageId(): int
    {
        return (int) WorkItemMessage::query()
            ->where('work_item_id', $this->getRecord()->getKey())
            ->max('id');
    }

    /**
     * @return array<int, string>
     */
    protected function cannedResponseOptions(): array
    {
        return once(fn (): array => CannedResponse::query()
            ->availableFor($this->getRecord())
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all());
    }

    protected function renderCannedResponse(int $cannedResponseId): ?string
    {
        if (! array_key_exists($cannedResponseId, $this->cannedResponseOptions())) {
            return null;
        }

        $response = CannedResponse::query()->find($cannedResponseId);

        if ($response === null) {
            return null;
        }

        return app(CannedResponseRenderer::class)->render($response->body, $this->getRecord(), auth()->user());
    }

    protected function ticketSelect(string $name, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            ->required()
            ->searchable()
            ->placeholder(__('Search by number or title'))
            ->getSearchResultsUsing(fn (string $search): array => $this->searchTickets($search))
            ->getOptionLabelUsing(fn ($value): ?string => ($workItem = WorkItem::query()->find($value)) !== null
                ? static::ticketLabel($workItem)
                : null);
    }

    /**
     * Tickets in the same tenant the agent can read, other than this one and merged ones.
     *
     * @return array<int, string>
     */
    protected function searchTickets(string $search): array
    {
        $user = auth()->user();
        $record = $this->getRecord();

        if ($user === null) {
            return [];
        }

        $search = trim(ltrim(trim($search), '#'));
        $query = WorkItem::query()
            ->where('tenant_id', $record->tenant_id)
            ->whereKeyNot($record->getKey())
            ->whereNull('merged_into_work_item_id');

        WorkItemQueueScope::applyToWorkItemQuery($query, $user);

        if ($search !== '') {
            $pattern = '%'.mb_strtolower($search).'%';

            $query->where(function ($query) use ($search, $pattern): void {
                if (ctype_digit($search)) {
                    $query->whereKey((int) $search);
                }

                $query->orWhereRaw('LOWER(title) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(public_id) LIKE ?', [$pattern]);
            });
        }

        return $query->orderByDesc('id')
            ->limit(25)
            ->get()
            ->mapWithKeys(fn (WorkItem $workItem): array => [$workItem->getKey() => static::ticketLabel($workItem)])
            ->all();
    }

    protected function isWatching(): bool
    {
        $user = auth()->user();

        return $user !== null && app(WorkItemCollaborationService::class)->isWatching($this->getRecord(), $user);
    }

    public function toggleWatch(bool $watch): void
    {
        $this->runCollaboration(function (WorkItemCollaborationService $service, User $user) use ($watch): string {
            $watch ? $service->watch($this->getRecord(), $user) : $service->unwatch($this->getRecord(), $user);

            return $watch ? __('You are watching this ticket') : __('You stopped watching this ticket');
        });
    }

    /**
     * @param  list<int>  $userIds
     */
    protected function syncWatchers(array $userIds): void
    {
        $this->runCollaboration(function (WorkItemCollaborationService $service, User $actor) use ($userIds): string {
            $record = $this->getRecord();
            $current = $record->watchers()->get()->keyBy('id');
            $wanted = User::query()
                ->where('tenant_id', $record->tenant_id)
                ->whereKey($userIds)
                ->get()
                ->keyBy('id');

            foreach ($current->diffKeys($wanted) as $user) {
                $service->unwatch($record, $user, $actor);
            }

            foreach ($wanted->diffKeys($current) as $user) {
                $service->watch($record, $user, $actor);
            }

            return __('Watchers updated');
        });
    }

    /**
     * @param  array<int, mixed>  $addresses
     */
    protected function saveCcAddresses(array $addresses): void
    {
        $this->runCollaboration(function (WorkItemCollaborationService $service, User $user) use ($addresses): string {
            $service->setCcAddresses($this->getRecord(), array_map('strval', $addresses), $user);

            return __('CC recipients updated');
        });
    }

    protected function linkTicket(int $workItemId): void
    {
        $this->runCollaboration(function (WorkItemCollaborationService $service, User $user) use ($workItemId): string {
            $other = $this->findTicket($workItemId);
            $service->link($this->getRecord(), $other, $user);

            return __('Linked to :ticket', ['ticket' => static::ticketLabel($other)]);
        });
    }

    protected function unlinkTicket(int $workItemId): void
    {
        $this->runCollaboration(function (WorkItemCollaborationService $service, User $user) use ($workItemId): string {
            $other = $this->findTicket($workItemId);
            $service->unlink($this->getRecord(), $other, $user);

            return __('Link removed');
        });
    }

    protected function mergeInto(int $workItemId): void
    {
        $target = null;

        $merged = $this->runCollaboration(function (WorkItemCollaborationService $service, User $user) use ($workItemId, &$target): string {
            $target = $service->merge($this->getRecord(), $this->findTicket($workItemId), $user);

            return __('Merged into :ticket', ['ticket' => static::ticketLabel($target)]);
        });

        if ($merged && $target !== null) {
            $this->redirect(WorkItemResource::getUrl('view', ['record' => $target->getKey()]));
        }
    }

    protected function findTicket(int $workItemId): WorkItem
    {
        $workItem = WorkItem::query()
            ->where('tenant_id', $this->getRecord()->tenant_id)
            ->find($workItemId);

        if ($workItem === null) {
            throw new NotFoundHttpException(__('Ticket not found.'));
        }

        return $workItem;
    }

    /**
     * @param  callable(WorkItemCollaborationService, User): string  $callback
     */
    protected function runCollaboration(callable $callback): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        try {
            $message = $callback(app(WorkItemCollaborationService::class), $user);
        } catch (AccessDeniedHttpException) {
            Notification::make()
                ->title(__('Not allowed to update this ticket'))
                ->body(__('This needs work-item Update on the queues of the tickets involved.'))
                ->danger()
                ->send();

            return false;
        } catch (UnprocessableEntityHttpException|NotFoundHttpException $e) {
            Notification::make()
                ->title(__('Could not update the ticket'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return false;
        }

        Notification::make()
            ->title($message)
            ->success()
            ->send();

        $this->getRecord()->refresh();

        return true;
    }

    protected function canUpdateWorkItem(): bool
    {
        $user = auth()->user();
        $record = $this->getRecord();

        if ($user === null || $record->queue_id === null) {
            return false;
        }

        return HelixdeskWorkItemAuthorization::hasWorkItemPermission($user, 'update', (int) $record->queue_id);
    }
}
