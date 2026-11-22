<?php

namespace App\Filament\Agent\Resources\WorkItems\Tables;

use App\Enums\WorkItemPriority;
use App\Enums\WorkItemType;
use App\Filament\Agent\Resources\WorkItems\WorkItemResource;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\WorkItemWorkflowService;
use App\Support\DisplayFormats;
use App\Support\HelixdeskWorkItemAuthorization;
use App\Support\WorkItemStatusDisplay;
use Carbon\CarbonInterface;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The agents' ticket queue: who it is for, who has it, how urgent it is and when the SLA runs out.
 */
class WorkItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['organization', 'reportedBy', 'assignedAgent', 'queue']))
            ->columns([
                TextColumn::make('id')
                    ->label(__('#'))
                    ->formatStateUsing(fn (int|string $state): string => '#'.$state)
                    ->sortable()
                    ->searchable(),
                TextColumn::make('title')
                    ->label(__('Title'))
                    ->description(fn (WorkItem $record): ?string => static::requesterSummary($record))
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('organization.name')
                    ->label(__('Customer'))
                    ->placeholder(__('—'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => WorkItemStatusDisplay::label($state))
                    ->color(fn (?string $state): string|array => WorkItemStatusDisplay::color($state))
                    ->sortable(),
                TextColumn::make('priority')
                    ->label(__('Priority'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => WorkItemPriority::labelFor($state))
                    ->color(fn (?string $state): string => WorkItemPriority::colorFor($state))
                    ->sortable(),
                TextColumn::make('assignedAgent.name')
                    ->label(__('Assigned to'))
                    ->placeholder(__('Unassigned'))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('sla_due_at')
                    ->label(__('SLA due'))
                    ->state(fn (WorkItem $record): ?string => static::nextSlaDeadline($record)?->toIso8601String())
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : DisplayFormats::dateTime(now()->parse($state)))
                    ->description(fn (WorkItem $record): ?string => static::nextSlaDeadline($record)?->diffForHumans())
                    ->color(fn (WorkItem $record): string => static::slaColor($record))
                    ->placeholder(__('—'))
                    ->toggleable(),
                TextColumn::make('queue.name')
                    ->label(__('Queue'))
                    ->placeholder(__('—'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('opened_at')
                    ->label(__('Opened'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('Last updated'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->multiple()
                    ->options(fn (): array => WorkItemStatusDisplay::options()),
                SelectFilter::make('priority')
                    ->label(__('Priority'))
                    ->multiple()
                    ->options(WorkItemPriority::options()),
                SelectFilter::make('organization_id')
                    ->label(__('Customer'))
                    ->relationship('organization', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('queue_id')
                    ->label(__('Queue'))
                    ->relationship('queue', 'name')
                    ->preload(),
                SelectFilter::make('assigned_agent_id')
                    ->label(__('Assigned to'))
                    ->options(fn (): array => static::agentOptions())
                    ->searchable(),
                TernaryFilter::make('unassigned')
                    ->label(__('Assignment'))
                    ->placeholder(__('All tickets'))
                    ->trueLabel(__('Unassigned only'))
                    ->falseLabel(__('Assigned only'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('assigned_agent_id'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('assigned_agent_id'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                SelectFilter::make('type')
                    ->label(__('Type'))
                    ->options(WorkItemType::options()),
            ])
            ->recordUrl(fn (WorkItem $record): string => WorkItemResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('assign')
                        ->label(__('Assign to'))
                        ->icon(Heroicon::OutlinedUserPlus)
                        ->schema([
                            Select::make('assigned_agent_id')
                                ->label(__('Agent'))
                                ->placeholder(__('Unassigned'))
                                ->options(fn (): array => static::agentOptions())
                                ->searchable(),
                        ])
                        ->action(fn (Collection $records, array $data) => static::bulkUpdate(
                            $records,
                            fn (WorkItem $record) => $record->forceFill(['assigned_agent_id' => $data['assigned_agent_id'] ?? null])->save(),
                        ))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('setPriority')
                        ->label(__('Change priority'))
                        ->icon(Heroicon::OutlinedFlag)
                        ->schema([
                            Select::make('priority')
                                ->label(__('Priority'))
                                ->options(WorkItemPriority::options())
                                ->required(),
                        ])
                        ->action(fn (Collection $records, array $data) => static::bulkUpdate(
                            $records,
                            fn (WorkItem $record) => $record->forceFill(['priority' => $data['priority']])->save(),
                        ))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('setStatus')
                        ->label(__('Change status'))
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->schema([
                            Select::make('status')
                                ->label(__('Status'))
                                ->options(fn (): array => WorkItemStatusDisplay::options())
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            /** @var User $user */
                            $user = Auth::user();

                            static::bulkUpdate(
                                $records,
                                fn (WorkItem $record) => $record->status === $data['status']
                                    ? null
                                    : app(WorkItemWorkflowService::class)->transition($record, $data['status'], $user),
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * Requester and customer under the title, so agents see who a ticket is for at a glance.
     */
    protected static function requesterSummary(WorkItem $record): ?string
    {
        $parts = array_filter([
            $record->reportedBy?->name,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * The SLA deadline that matters now: first response until someone has answered, then resolution.
     */
    public static function nextSlaDeadline(WorkItem $record): ?CarbonInterface
    {
        if ($record->resolved_at !== null || $record->closed_at !== null) {
            return null;
        }

        if ($record->first_responded_at === null && $record->first_response_due_at !== null) {
            return $record->first_response_due_at;
        }

        return $record->resolution_due_at;
    }

    protected static function slaColor(WorkItem $record): string
    {
        $deadline = static::nextSlaDeadline($record);

        if ($deadline === null) {
            return 'gray';
        }

        if ($deadline->isPast()) {
            return 'danger';
        }

        $warningMinutes = (int) config('helixdesk.notifications.sla_warning_minutes', 30);

        return $deadline->lte(now()->addMinutes($warningMinutes)) ? 'warning' : 'gray';
    }

    /**
     * @return array<int, string>
     */
    public static function agentOptions(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        return User::query()
            ->where('tenant_id', $user->operatingTenantId())
            ->where('type', 'agent')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Apply a change to every selected ticket the agent may update, and say how many were skipped.
     *
     * @param  Collection<int, WorkItem>  $records
     * @param  callable(WorkItem): mixed  $change
     */
    protected static function bulkUpdate(Collection $records, callable $change): void
    {
        $user = Auth::user();
        $updated = 0;
        $skipped = 0;

        foreach ($records as $record) {
            if (! $user instanceof User || $record->queue_id === null
                || ! HelixdeskWorkItemAuthorization::hasWorkItemPermission($user, 'update', (int) $record->queue_id)) {
                $skipped++;

                continue;
            }

            try {
                $change($record);
                $updated++;
            } catch (Throwable) {
                $skipped++;
            }
        }

        $notification = Notification::make()
            ->title($updated === 0
                ? __('No tickets were updated')
                : trans_choice('{1} :count ticket updated|[2,*] :count tickets updated', $updated, ['count' => $updated]));

        if ($updated === 0) {
            $notification->danger();
        }

        if ($skipped > 0) {
            $notification
                ->body(trans_choice('{1} :count ticket was skipped: you may not change it, or the status change is not allowed.|[2,*] :count tickets were skipped: you may not change them, or the status change is not allowed.', $skipped, ['count' => $skipped]))
                ->warning();
        } elseif ($updated > 0) {
            $notification->success();
        }

        $notification->send();
    }
}
