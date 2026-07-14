<?php

namespace App\Filament\Agent\Resources\WorkItems\Schemas;

use App\Enums\OrganizationType;
use App\Filament\Agent\Resources\WorkItems\Pages\ViewWorkItem;
use App\Filament\Agent\Resources\WorkItems\RelationManagers\AssetsRelationManager;
use App\Filament\Agent\Resources\WorkItems\RelationManagers\DocumentsRelationManager;
use App\Filament\Agent\Resources\WorkItems\WorkItemResource;
use App\Models\WorkItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
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
                        Group::make(static::workZoneComponents())
                            ->columnSpan(['xl' => 7]),
                        Group::make(static::metadataSidebarComponents($page))
                            ->columnSpan(['xl' => 3]),
                    ]),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    private static function workZoneComponents(): array
    {
        return [
            Section::make()
                ->schema([
                    TextEntry::make('id')
                        ->label('#')
                        ->formatStateUsing(fn (int|string $state): string => '#'.$state)
                        ->weight(FontWeight::SemiBold),
                    TextEntry::make('title')
                        ->hiddenLabel()
                        ->weight(FontWeight::SemiBold)
                        ->columnSpanFull(),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('priority')->badge(),
                    TextEntry::make('type')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? $state->name : (string) $state),
                    TextEntry::make('queue.name')
                        ->label('Queue')
                        ->badge()
                        ->placeholder('—'),
                    TextEntry::make('assignedAgent.name')
                        ->label('Assigned')
                        ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Assigned: '.$state : 'Unassigned')
                        ->color('gray'),
                ])
                ->columns(['default' => 2, 'sm' => 3])
                ->columnSpanFull(),
            EmbeddedSchema::make('workZoneForm'),
            Section::make('Conversation')
                ->schema([
                    RepeatableEntry::make('threaded_messages')
                        ->label('')
                        ->state(function (WorkItem $record): array {
                            $messages = $record->messages()
                                ->with('author')
                                ->orderBy('created_at')
                                ->get();

                            return WorkItemResource::flattenThreadedMessages(
                                $messages,
                                true,
                                $record->reported_by_user_id,
                            );
                        })
                        ->schema([
                            TextEntry::make('author_name')
                                ->hiddenLabel()
                                ->weight(FontWeight::SemiBold),
                            TextEntry::make('author_role')
                                ->hiddenLabel()
                                ->color('gray'),
                            TextEntry::make('message_type')
                                ->hiddenLabel()
                                ->badge()
                                ->color(fn (?string $state): string => $state === 'Internal note' ? 'warning' : 'gray'),
                            TextEntry::make('created_at')
                                ->hiddenLabel()
                                ->dateTime()
                                ->color('gray'),
                            TextEntry::make('body')
                                ->hiddenLabel()
                                ->columnSpanFull()
                                ->prose(),
                        ])
                        ->columns(4)
                        ->contained(true),
                ])
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function metadataSidebarComponents(ViewWorkItem $page): array
    {
        return [
            Section::make('Metadata')
                ->schema([
                    Tabs::make()
                        ->livewireProperty('activeMetaTab')
                        ->contained(false)
                        ->tabs([
                            Tab::make('details')
                                ->label('Details')
                                ->schema(static::detailsTabSchema()),
                            Tab::make('requester')
                                ->label('Requester')
                                ->schema(static::requesterTabSchema()),
                            Tab::make('routing')
                                ->label('Routing')
                                ->schema(static::routingTabSchema()),
                            Tab::make('relations')
                                ->label('Relations')
                                ->schema(static::relationsTabSchema($page)),
                            Tab::make('email')
                                ->label('Email')
                                ->schema(static::emailTabSchema()),
                            Tab::make('activity')
                                ->label('Activity')
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
            TextEntry::make('type')
                ->label('Type')
                ->formatStateUsing(fn ($state): string => is_object($state) ? $state->name : (string) $state),
            TextEntry::make('priority')->label('Priority'),
            TextEntry::make('status')->label('Status'),
            TextEntry::make('category_id')->label('Category')->placeholder('—'),
            TextEntry::make('requester_department')
                ->label('Department')
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
            TextEntry::make('opened_at')->label('Opened')->dateTime()->placeholder('—'),
            TextEntry::make('resolved_at')->label('Resolved')->dateTime()->placeholder('—'),
            TextEntry::make('closed_at')->label('Closed')->dateTime()->placeholder('—'),
            TextEntry::make('assignedAgent.name')->label('Assigned to')->placeholder('—'),
            TextEntry::make('description')->label('Description')->placeholder('—')->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, TextEntry>
     */
    private static function requesterTabSchema(): array
    {
        return [
            TextEntry::make('reportedBy.name')->label('Name')->placeholder('Unknown'),
            TextEntry::make('reportedBy.email')->label('Email')->placeholder('Unknown'),
            TextEntry::make('organization.name')->label('Organization')->placeholder('—'),
            TextEntry::make('previous_ticket_count')
                ->label('Previous tickets')
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
            TextEntry::make('queue.name')->label('Queue')->placeholder('—'),
            TextEntry::make('source')
                ->label('Source')
                ->formatStateUsing(fn ($state): string => is_object($state) ? $state->name : (string) $state),
            TextEntry::make('organization.name')->label('Organization')->placeholder('—'),
            TextEntry::make('tenant.name')->label('Tenant')->placeholder('—'),
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
            TextEntry::make('linked_problems')
                ->label('Linked problems')
                ->state(function (WorkItem $record): string {
                    $titles = $record->problems()->pluck('title');

                    return $titles->isEmpty() ? '—' : $titles->implode(', ');
                })
                ->columnSpanFull(),
            TextEntry::make('linked_monitoring_events')
                ->label('Monitoring events')
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
            TextEntry::make('mailAccount.email_address')->label('Mail account')->placeholder('—'),
            TextEntry::make('inbound_message_id')->label('Inbound message ID')->placeholder('—'),
            TextEntry::make('title')->label('Thread subject'),
            TextEntry::make('last_inbound_at')
                ->label('Last inbound')
                ->state(function (WorkItem $record): ?string {
                    $message = $record->messages()
                        ->where('is_internal', false)
                        ->orderByDesc('created_at')
                        ->first();

                    return $message?->created_at?->toDateTimeString();
                })
                ->dateTime()
                ->placeholder('—'),
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
                    TextEntry::make('when')->label('When')->dateTime(),
                    TextEntry::make('who')->label('Who'),
                    TextEntry::make('event')->label('Event')->columnSpanFull(),
                ])
                ->columns(2)
                ->contained(false),
        ];
    }
}
