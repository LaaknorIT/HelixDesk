<?php

namespace App\Filament\Agent\Resources\WorkItems\Pages;

use App\Filament\Agent\Resources\WorkItems\Schemas\WorkItemInfolist;
use App\Filament\Agent\Resources\WorkItems\WorkItemResource;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\WorkItemWorkflowService;
use App\Support\HelixdeskWorkItemAuthorization;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
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

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->workZoneForm->fill($this->defaultWorkZoneState());
    }

    public function hasInlineLabels(): bool
    {
        return true;
    }

    public function getTitle(): string|Htmlable
    {
        return '#'.$this->getRecord()->getKey();
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
                Section::make('Quick actions')
                    ->schema([
                        Select::make('to_status')
                            ->label('Status')
                            ->placeholder('Select status')
                            ->options(fn (): array => $this->allowedStatusTransitions()),
                        Select::make('priority')
                            ->label('Priority')
                            ->options([
                                'normal' => 'Normal',
                                'high' => 'High',
                                'urgent' => 'Urgent',
                            ])
                            ->default(fn (): string => (string) $this->getRecord()->priority),
                        Select::make('assigned_agent_id')
                            ->label('Assign to')
                            ->placeholder('Unassigned')
                            ->options(fn (): array => $this->agentOptions())
                            ->default(fn (): ?int => $this->getRecord()->assigned_agent_id),
                        Actions::make([
                            Action::make('applyQuickActions')
                                ->label('Apply')
                                ->action('applyQuickActions'),
                        ]),
                    ])
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4]),
                Section::make('Compose response')
                    ->schema([
                        Select::make('parent_message_id')
                            ->label('Reply to')
                            ->placeholder('Top-level reply')
                            ->options(fn (): array => $this->replyTargetOptions()),
                        Textarea::make('body')
                            ->label('Message')
                            ->placeholder('Type your reply to the requester…')
                            ->rows(4),
                        Checkbox::make('is_internal')
                            ->label('Internal note (not visible to requester)'),
                        Actions::make([
                            Action::make('sendReply')
                                ->label('Send reply')
                                ->action('sendReply'),
                        ]),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
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
                        ->title('Not allowed to update this ticket')
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
                ->title('Ticket updated')
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
                ->title('Message is required')
                ->danger()
                ->send();

            return;
        }

        $workflow = app(WorkItemWorkflowService::class);
        $record = $this->getRecord();
        $isInternal = (bool) ($data['is_internal'] ?? false);
        $parentMessageId = filled($data['parent_message_id'] ?? null) ? (int) $data['parent_message_id'] : null;

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
                ? "Your role needs work-item Update (or Admin) on queue \"{$queueLabel}\" under Administration → Roles → Work item permissions by queue."
                : 'You do not have access to operate in this tenant. Global admins need an explicit Tenant Admin assignment for this tenant.';

            Notification::make()
                ->title($isInternal ? 'Not allowed to add an internal note' : 'Not allowed to reply on this ticket')
                ->body($body)
                ->danger()
                ->persistent()
                ->send();

            return;
        } catch (UnprocessableEntityHttpException|NotFoundHttpException $e) {
            Notification::make()
                ->title('Could not save message')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title('Could not save message')
                ->body('An unexpected error occurred. Please try again or check the application log.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title($isInternal ? 'Internal note added' : 'Reply saved')
            ->body($isInternal ? null : 'Outbound email will be sent in the background when configured.')
            ->success()
            ->send();

        $this->getRecord()->refresh();
        $this->workZoneForm->fill([
            ...$this->defaultWorkZoneState(),
            'body' => null,
            'parent_message_id' => null,
            'is_internal' => false,
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
            'priority' => $record->priority,
            'assigned_agent_id' => $record->assigned_agent_id,
            'parent_message_id' => null,
            'body' => null,
            'is_internal' => false,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function allowedStatusTransitions(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        return app(WorkItemWorkflowService::class)->allowedTransitions($this->getRecord(), $user);
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
     * @return array<int, string>
     */
    protected function replyTargetOptions(): array
    {
        /** @var WorkItem $record */
        $record = $this->getRecord();

        return $record->messages()
            ->where('is_internal', false)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn ($message): array => [
                $message->getKey() => '#'.$message->getKey().' '.mb_substr($message->body, 0, 60),
            ])
            ->all();
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
