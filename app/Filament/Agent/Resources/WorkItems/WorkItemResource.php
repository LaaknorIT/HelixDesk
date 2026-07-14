<?php

namespace App\Filament\Agent\Resources\WorkItems;

use App\Filament\Agent\Resources\WorkItems\Pages\ListWorkItems;
use App\Filament\Agent\Resources\WorkItems\Pages\ViewWorkItem;
use App\Filament\Agent\Resources\WorkItems\RelationManagers\AssetsRelationManager;
use App\Filament\Agent\Resources\WorkItems\RelationManagers\DocumentsRelationManager;
use App\Filament\Agent\Resources\WorkItems\Schemas\WorkItemForm;
use App\Filament\Agent\Resources\WorkItems\Tables\WorkItemsTable;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Support\HelixdeskWorkItemAuthorization;
use App\Support\WorkItemQueueScope;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class WorkItemResource extends Resource
{
    protected static ?string $model = WorkItem::class;

    protected static ?string $navigationLabel = 'Tickets';

    protected static ?string $modelLabel = 'Ticket';

    protected static ?string $pluralModelLabel = 'Tickets';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        if (! $user->isTenantStaff()) {
            return false;
        }

        return HelixdeskWorkItemAuthorization::canAccessWorkItemWorkspace($user);
    }

    public static function form(Schema $schema): Schema
    {
        return WorkItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkItemsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        return WorkItemQueueScope::applyToWorkItemQuery($query, $user);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ticket')
                ->schema([
                    TextEntry::make('type')->label('Type')->badge(),
                    TextEntry::make('title')->label('Title'),
                    TextEntry::make('status')->label('Status')->badge(),
                    TextEntry::make('priority')->label('Priority')->badge(),
                    TextEntry::make('opened_at')->label('Opened')->dateTime(),
                ])->columns(2),
            Section::make('Requester')
                ->schema([
                    TextEntry::make('reportedBy.name')
                        ->label('Name')
                        ->placeholder('Unknown'),
                    TextEntry::make('reportedBy.email')
                        ->label('Email')
                        ->placeholder('Unknown'),
                    TextEntry::make('previous_ticket_count')
                        ->label('Previous tickets')
                        ->state(function (WorkItem $record): int {
                            return WorkItem::query()
                                ->where('reported_by_user_id', $record->reported_by_user_id)
                                ->whereKeyNot($record->getKey())
                                ->count();
                        }),
                ])->columns(3),
            Section::make('Routing')
                ->schema([
                    TextEntry::make('organization.name')
                        ->label('Organization')
                        ->placeholder('—'),
                    TextEntry::make('queue.name')
                        ->label('Queue')
                        ->placeholder('—'),
                    TextEntry::make('linked_problems')
                        ->label('Linked problems')
                        ->state(function (WorkItem $record): string {
                            $titles = $record->problems()->pluck('title');

                            return $titles->isEmpty() ? '—' : $titles->implode(', ');
                        }),
                    TextEntry::make('linked_monitoring_events')
                        ->label('Monitoring events')
                        ->state(function (WorkItem $record): string {
                            $summaries = $record->monitoringEvents()
                                ->get()
                                ->map(fn ($e) => '#'.$e->getKey().' '.$e->severity.': '.mb_substr((string) $e->message, 0, 40));

                            return $summaries->isEmpty() ? '—' : $summaries->implode(', ');
                        }),
                    TextEntry::make('linked_documents')
                        ->label('Linked documents')
                        ->state(function (WorkItem $record): string {
                            $titles = $record->documents()->pluck('title');

                            return $titles->isEmpty() ? '—' : $titles->implode(', ');
                        }),
                ])->columns(3),
            Section::make('Assets')
                ->schema([
                    TextEntry::make('linked_assets')
                        ->label('Linked assets')
                        ->state(function (WorkItem $record): string {
                            $labels = $record->assets()
                                ->with('assetType')
                                ->get()
                                ->map(fn ($a) => $a->name.($a->assetType ? ' ('.$a->assetType->name.')' : ''))
                                ->implode(', ');

                            return $labels === '' ? '—' : $labels;
                        }),
                ]),
            Section::make('Conversation')
                ->schema([
                    RepeatableEntry::make('threaded_messages')
                        ->label('')
                        ->state(function (WorkItem $record): array {
                            $messages = $record->messages()
                                ->with('author')
                                ->orderBy('created_at')
                                ->get();

                            return static::flattenThreadedMessages($messages, true);
                        })
                        ->schema([
                            TextEntry::make('author_name')->label('Author'),
                            TextEntry::make('created_at')->label('At'),
                            TextEntry::make('message_type')->label('Type')->badge(),
                            TextEntry::make('body')
                                ->label('Message')
                                ->columnSpanFull()
                                ->prose(),
                        ])
                        ->columns(3)
                        ->contained(false),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            AssetsRelationManager::class,
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkItems::route('/'),
            'view' => ViewWorkItem::route('/{record}'),
        ];
    }

    /**
     * @param  Collection<int, WorkItemMessage>  $messages
     * @return array<int, array<string, string>>
     */
    public static function flattenThreadedMessages(Collection $messages, bool $includeInternal): array
    {
        $byParent = $messages->groupBy('parent_message_id');
        $rows = [];

        $walk = function (?int $parentId, int $depth) use (&$walk, &$rows, $byParent, $includeInternal): void {
            /** @var WorkItemMessage $message */
            foreach ($byParent->get($parentId, collect()) as $message) {
                if ($message->is_internal && ! $includeInternal) {
                    continue;
                }

                $rows[] = [
                    'author_name' => $message->author?->name ?? 'Unknown user',
                    'created_at' => $message->created_at?->toDateTimeString() ?? '-',
                    'message_type' => $message->is_internal ? 'Internal note' : 'Reply',
                    'body' => str_repeat('  ', $depth).$message->body,
                ];

                $walk((int) $message->getKey(), $depth + 1);
            }
        };

        $walk(null, 0);

        return $rows;
    }
}
