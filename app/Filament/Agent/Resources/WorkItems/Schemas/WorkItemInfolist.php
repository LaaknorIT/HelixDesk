<?php

namespace App\Filament\Agent\Resources\WorkItems\Schemas;

use App\Enums\OrganizationType;
use App\Enums\WorkItemPriority;
use App\Filament\Agent\Resources\WorkItems\Pages\ViewWorkItem;
use App\Filament\Agent\Resources\WorkItems\RelationManagers\AssetsRelationManager;
use App\Filament\Agent\Resources\WorkItems\RelationManagers\DocumentsRelationManager;
use App\Filament\Agent\Resources\WorkItems\Tables\WorkItemsTable;
use App\Filament\Agent\Resources\WorkItems\WorkItemResource;
use App\Livewire\WorkItemViewers;
use App\Models\WorkItem;
use App\Support\WorkItemStatusDisplay;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

class WorkItemInfolist
{
    public static function configure(Schema $schema, ViewWorkItem $page): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'xl' => 10])
                    ->schema([
                        Group::make(static::workZoneComponents($page))
                            ->columnSpan(['xl' => 7]),
                        Group::make(static::metadataSidebarComponents($page))
                            ->columnSpan(['xl' => 3]),
                    ]),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    private static function workZoneComponents(ViewWorkItem $page): array
    {
        return [
            Livewire::make(WorkItemViewers::class, ['workItemId' => (int) $page->getRecord()->getKey()])
                ->key('work-item-viewers')
                ->columnSpanFull(),
            Callout::make(fn (WorkItem $record): string => __('Merged into #:id', ['id' => $record->merged_into_work_item_id]))
                ->description(__('This ticket was merged. New messages and email replies go to the other ticket.'))
                ->info()
                ->icon('heroicon-o-arrows-pointing-in')
                ->visible(fn (WorkItem $record): bool => $record->merged_into_work_item_id !== null)
                ->actions([
                    Action::make('openMergedInto')
                        ->label(__('Open ticket'))
                        ->link()
                        ->url(fn (WorkItem $record): string => WorkItemResource::getUrl('view', ['record' => $record->merged_into_work_item_id])),
                ])
                ->columnSpanFull(),
            Section::make()
                ->schema([
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => WorkItemStatusDisplay::label($state))
                        ->color(fn (?string $state): string|array => WorkItemStatusDisplay::color($state)),
                    TextEntry::make('priority')
                        ->label(__('Priority'))
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => WorkItemPriority::labelFor($state))
                        ->color(fn (?string $state): string => WorkItemPriority::colorFor($state)),
                    TextEntry::make('type')
                        ->label(__('Type'))
                        ->badge(),
                    TextEntry::make('assignedAgent.name')
                        ->label(__('Assigned to'))
                        ->placeholder(__('Unassigned')),
                    TextEntry::make('reportedBy.name')
                        ->label(__('Requester'))
                        ->placeholder(__('Unknown')),
                    TextEntry::make('organization.name')
                        ->label(__('Customer'))
                        ->placeholder(__('—')),
                    TextEntry::make('queue.name')
                        ->label(__('Queue'))
                        ->placeholder(__('—')),
                    TextEntry::make('sla_due')
                        ->label(__('SLA due'))
                        ->state(fn (WorkItem $record): ?string => WorkItemsTable::nextSlaDeadline($record)?->toIso8601String())
                        ->dateTime()
                        ->since()
                        ->dateTimeTooltip()
                        ->placeholder(__('—')),
                ])
                ->inlineLabel(false)
                ->columns(['default' => 2, 'sm' => 4])
                ->columnSpanFull(),
            Section::make(__('Conversation'))
                ->schema([
                    RepeatableEntry::make('conversation')
                        ->hiddenLabel()
                        ->state(function (WorkItem $record): array {
                            $messages = $record->messages()
                                ->with('author')
                                ->orderBy('created_at')
                                ->get();

                            return WorkItemResource::conversationEntries($record, $messages, true);
                        })
                        ->schema([
                            TextEntry::make('author_name')
                                ->hiddenLabel()
                                ->weight(FontWeight::SemiBold)
                                ->inlineLabel(false),
                            TextEntry::make('author_role')
                                ->hiddenLabel()
                                ->color('gray')
                                ->inlineLabel(false),
                            TextEntry::make('message_type')
                                ->hiddenLabel()
                                ->badge()
                                ->color(fn (?string $state): string => match ($state) {
                                    __('Internal note') => 'warning',
                                    __('Request') => 'info',
                                    default => 'gray',
                                })
                                ->inlineLabel(false),
                            TextEntry::make('created_at')
                                ->hiddenLabel()
                                ->dateTime()
                                ->color('gray')
                                ->inlineLabel(false),
                            TextEntry::make('body')
                                ->hiddenLabel()
                                ->columnSpanFull()
                                ->inlineLabel(false)
                                ->prose(),
                        ])
                        ->columns(['default' => 2, 'md' => 4])
                        ->contained(true),
                ])
                ->columnSpanFull(),
            EmbeddedSchema::make('workZoneForm'),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function metadataSidebarComponents(ViewWorkItem $page): array
    {
        return [
            Section::make(__('Metadata'))
                ->schema([
                    Tabs::make()
                        ->livewireProperty('activeMetaTab')
                        ->contained(false)
                        ->tabs([
                            Tab::make('details')
                                ->label(__('Details'))
                                ->schema(static::detailsTabSchema()),
                            Tab::make('requester')
                                ->label(__('Requester'))
                                ->schema(static::requesterTabSchema()),
                            Tab::make('routing')
                                ->label(__('Routing'))
                                ->schema(static::routingTabSchema()),
                            Tab::make('relations')
                                ->label(__('Relations'))
                                ->schema(static::relationsTabSchema($page)),
                            Tab::make('email')
                                ->label(__('Email'))
                                ->schema(static::emailTabSchema()),
                            Tab::make('activity')
                                ->label(__('Activity'))
                                ->schema(static::activityTabSchema()),
                        ]),
                ])
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, TextEntry>
     */
    private static function detailsTabSchema(): array
    {
        return [
            TextEntry::make('category.name')->label(__('Category'))->placeholder(__('No category')),
            TextEntry::make('requester_department')
                ->label(__('Department'))
                ->state(function (WorkItem $record): string {
                    $organization = $record->reportedBy?->organization;

                    if ($organization === null) {
                        return '—';
                    }

                    if ($organization->type === OrganizationType::Department) {
                        return $organization->name;
                    }

                    return '—';
                }),
            TextEntry::make('opened_at')->label(__('Opened'))->dateTime()->placeholder(__('—')),
            TextEntry::make('resolved_at')->label(__('Resolved'))->dateTime()->placeholder(__('—')),
            TextEntry::make('closed_at')->label(__('Closed'))->dateTime()->placeholder(__('—')),
            TextEntry::make('watcher_names')
                ->label(__('Watchers'))
                ->state(fn (WorkItem $record): string => $record->watchers()->orderBy('name')->pluck('name')->implode(', ') ?: '—'),
            TextEntry::make('cc_list')
                ->label(__('CC'))
                ->state(fn (WorkItem $record): string => implode(', ', $record->ccAddressList()) ?: '—'),
        ];
    }

    /**
     * @return array<int, TextEntry>
     */
    private static function requesterTabSchema(): array
    {
        return [
            TextEntry::make('reportedBy.name')->label(__('Name'))->placeholder(__('Unknown')),
            TextEntry::make('reportedBy.email')->label(__('Email'))->placeholder(__('Unknown')),
            TextEntry::make('organization.name')->label(__('Organization'))->placeholder(__('—')),
            TextEntry::make('previous_ticket_count')
                ->label(__('Previous tickets'))
                ->state(function (WorkItem $record): int {
                    return WorkItem::query()
                        ->where('reported_by_user_id', $record->reported_by_user_id)
                        ->whereKeyNot($record->getKey())
                        ->count();
                }),
        ];
    }

    /**
     * @return array<int, TextEntry>
     */
    private static function routingTabSchema(): array
    {
        return [
            TextEntry::make('queue.name')->label(__('Queue'))->placeholder(__('—')),
            TextEntry::make('source')
                ->label(__('Source'))
                ->placeholder(__('—')),
            TextEntry::make('organization.name')->label(__('Organization'))->placeholder(__('—')),
            TextEntry::make('tenant.name')->label(__('Tenant'))->placeholder(__('—')),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function relationsTabSchema(ViewWorkItem $page): array
    {
        $ownerRecord = $page->getRecord();
        $managerData = [
            'ownerRecord' => $ownerRecord,
            'pageClass' => ViewWorkItem::class,
        ];

        return [
            Livewire::make(AssetsRelationManager::class, $managerData)
                ->key(AssetsRelationManager::class),
            Livewire::make(DocumentsRelationManager::class, $managerData)
                ->key(DocumentsRelationManager::class),
            TextEntry::make('linked_tickets')
                ->label(__('Linked tickets'))
                ->state(fn (WorkItem $record): array => $record->linkedWorkItems()
                    ->map(fn (WorkItem $linked): string => '<a class="fi-link" href="'.e(WorkItemResource::getUrl('view', ['record' => $linked->getKey()])).'">'
                        .e(ViewWorkItem::ticketLabel($linked)).'</a> <span class="fi-color-gray">('.e(WorkItemStatusDisplay::label($linked->status)).')</span>')
                    ->all())
                ->html()
                ->listWithLineBreaks()
                ->placeholder(__('—'))
                ->columnSpanFull(),
            TextEntry::make('linked_problems')
                ->label(__('Linked problems'))
                ->state(function (WorkItem $record): string {
                    $titles = $record->problems()->pluck('title');

                    return $titles->isEmpty() ? '—' : $titles->implode(', ');
                })
                ->columnSpanFull(),
            TextEntry::make('linked_monitoring_events')
                ->label(__('Monitoring events'))
                ->state(function (WorkItem $record): string {
                    $summaries = $record->monitoringEvents()
                        ->get()
                        ->map(fn ($event) => '#'.$event->getKey().' '.$event->severity.': '.mb_substr((string) $event->message, 0, 40));

                    return $summaries->isEmpty() ? '—' : $summaries->implode(', ');
                })
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, TextEntry>
     */
    private static function emailTabSchema(): array
    {
        return [
            TextEntry::make('mailAccount.email_address')->label(__('Mail account'))->placeholder(__('—')),
            TextEntry::make('public_id')->label(__('Mail reference'))->placeholder(__('—')),
            TextEntry::make('inbound_message_id')->label(__('Inbound message ID'))->placeholder(__('—')),
            TextEntry::make('title')->label(__('Thread subject')),
            TextEntry::make('last_inbound_at')
                ->label(__('Last inbound'))
                ->state(function (WorkItem $record): ?string {
                    $message = $record->messages()
                        ->where('is_internal', false)
                        ->orderByDesc('created_at')
                        ->first();

                    return $message?->created_at?->toIso8601String();
                })
                ->dateTime()
                ->placeholder(__('—')),
        ];
    }

    /**
     * @return array<int, RepeatableEntry>
     */
    private static function activityTabSchema(): array
    {
        return [
            RepeatableEntry::make('activity_timeline')
                ->label('')
                ->state(fn (WorkItem $record): array => WorkItemResource::buildActivityTimeline($record))
                ->schema([
                    TextEntry::make('when')->label(__('When'))->dateTime(),
                    TextEntry::make('who')->label(__('Who')),
                    TextEntry::make('event')->label(__('Event'))->columnSpanFull(),
                ])
                ->columns(2)
                ->contained(false),
        ];
    }
}
