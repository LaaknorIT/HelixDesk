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
use Filament\Resources\Resource;
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

                $messageType = match (true) {
                    $message->is_internal => 'Internal note',
                    $isRequester && $depth === 0 => 'Inbound',
                    default => 'Reply',
                };

                $rows[] = [
                    'author_name' => $message->author?->name ?? 'Unknown user',
                    'author_role' => $authorType === 'end_user' ? 'Requester' : 'Agent',
                    'created_at' => $message->created_at?->toDateTimeString() ?? '-',
                    'message_type' => $messageType,
                    'body' => str_repeat('  ', $depth).$message->body,
                    'depth' => $depth,
                    'is_internal' => $message->is_internal,
                ];

                $walk((int) $message->getKey(), $depth + 1);
            }
        };

        $walk(null, 0);

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
                'who' => 'System',
                'event' => 'Ticket created',
            ];
        }

        foreach ($record->messages()->with('author')->orderBy('created_at')->get() as $message) {
            $authorName = $message->author?->name ?? 'Unknown user';

            if ($message->is_internal) {
                $event = 'Added internal note';
            } elseif ((int) $message->author_user_id === (int) $record->reported_by_user_id) {
                $event = 'Requester replied';
            } else {
                $event = 'Replied to requester';
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
                'who' => 'System',
                'event' => 'Ticket resolved',
            ];
        }

        if ($record->closed_at !== null) {
            $rows[] = [
                'when' => $record->closed_at->toDateTimeString(),
                'who' => 'System',
                'event' => 'Ticket closed',
            ];
        }

        return collect($rows)
            ->sortByDesc('when')
            ->values()
            ->all();
    }
}
