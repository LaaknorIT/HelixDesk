<?php

namespace App\Filament\Agent\Resources\WorkItems;

use App\Filament\Agent\Resources\WorkItems\Pages\ListWorkItems;
use App\Filament\Agent\Resources\WorkItems\Pages\ViewWorkItem;
use App\Filament\Agent\Resources\WorkItems\RelationManagers\AssetsRelationManager;
use App\Filament\Agent\Resources\WorkItems\RelationManagers\DocumentsRelationManager;
use App\Filament\Agent\Resources\WorkItems\Schemas\WorkItemForm;
use App\Filament\Agent\Resources\WorkItems\Tables\WorkItemsTable;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Support\HelixdeskWorkItemAuthorization;
use App\Support\WorkItemQueueScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class WorkItemResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = WorkItem::class;

    protected static ?string $navigationLabel = 'Tickets';

    protected static ?string $modelLabel = 'ticket';

    protected static ?string $pluralModelLabel = 'tickets';

    protected static ?int $navigationSort = 1;

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
        return $schema;
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
     * The conversation in the order it happened, starting with the request itself (the ticket description).
     *
     * @param  Collection<int, WorkItemMessage>  $messages
     * @return array<int, array<string, mixed>>
     */
    public static function conversationEntries(
        WorkItem $record,
        Collection $messages,
        bool $includeInternal,
    ): array {
        $rows = [];

        if (filled($record->description)) {
            $rows[] = [
                'author_name' => $record->reportedBy?->name ?? __('Unknown user'),
                'author_role' => __('Requester'),
                'created_at' => $record->opened_at ?? $record->created_at,
                'message_type' => __('Request'),
                'message_kind' => 'request',
                'body' => (string) $record->description,
                'is_internal' => false,
            ];
        }

        foreach (static::flattenThreadedMessages($messages, $includeInternal, $record->reported_by_user_id) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  Collection<int, WorkItemMessage>  $messages
     * @return array<int, array<string, mixed>>
     */
    public static function flattenThreadedMessages(
        Collection $messages,
        bool $includeInternal,
        ?int $reportedByUserId = null,
    ): array {
        $byParent = $messages->groupBy('parent_message_id');
        $rows = [];

        $walk = function (?int $parentId, int $depth) use (&$walk, &$rows, $byParent, $includeInternal, $reportedByUserId): void {
            /** @var WorkItemMessage $message */
            foreach ($byParent->get($parentId, collect()) as $message) {
                if ($message->is_internal && ! $includeInternal) {
                    continue;
                }

                $isRequester = $reportedByUserId !== null
                    && (int) $message->author_user_id === $reportedByUserId;
                $authorType = $message->author?->type;

                $messageKind = match (true) {
                    $message->is_internal => 'internal',
                    $isRequester && $depth === 0 => 'inbound',
                    default => 'reply',
                };

                $rows[] = [
                    'author_name' => $message->author?->name ?? __('Unknown user'),
                    'author_role' => $authorType === 'end_user' ? __('Requester') : __('Agent'),
                    'created_at' => $message->created_at,
                    'message_type' => match ($messageKind) {
                        'internal' => __('Internal note'),
                        'inbound' => __('Inbound'),
                        default => __('Reply'),
                    },
                    'message_kind' => $messageKind,
                    'body' => $message->body,
                    'depth' => $depth,
                    'is_internal' => $message->is_internal,
                ];

                $walk((int) $message->getKey(), $depth + 1);
            }
        };

        $walk(null, 0);

        usort($rows, fn (array $a, array $b): int => ($a['created_at']?->getTimestamp() ?? 0) <=> ($b['created_at']?->getTimestamp() ?? 0));

        return $rows;
    }

    /**
     * @return array<int, array{when: string, who: string, event: string}>
     */
    public static function buildActivityTimeline(WorkItem $record): array
    {
        $rows = [];

        if ($record->opened_at !== null) {
            $rows[] = [
                'when' => $record->opened_at->toDateTimeString(),
                'who' => __('System'),
                'event' => __('Ticket created'),
            ];
        }

        foreach ($record->messages()->with('author')->orderBy('created_at')->get() as $message) {
            $authorName = $message->author?->name ?? __('Unknown user');

            if ($message->is_internal) {
                $event = __('Added internal note');
            } elseif ((int) $message->author_user_id === (int) $record->reported_by_user_id) {
                $event = __('Requester replied');
            } else {
                $event = __('Replied to requester');
            }

            $rows[] = [
                'when' => $message->created_at?->toDateTimeString() ?? '-',
                'who' => $authorName,
                'event' => $event,
            ];
        }

        if ($record->resolved_at !== null) {
            $rows[] = [
                'when' => $record->resolved_at->toDateTimeString(),
                'who' => __('System'),
                'event' => __('Ticket resolved'),
            ];
        }

        if ($record->closed_at !== null) {
            $rows[] = [
                'when' => $record->closed_at->toDateTimeString(),
                'who' => __('System'),
                'event' => __('Ticket closed'),
            ];
        }

        return collect($rows)
            ->sortByDesc('when')
            ->values()
            ->all();
    }
}
