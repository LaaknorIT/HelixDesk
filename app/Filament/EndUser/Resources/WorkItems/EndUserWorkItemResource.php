<?php

namespace App\Filament\EndUser\Resources\WorkItems;

use App\Filament\EndUser\Resources\WorkItems\Pages\ListEndUserWorkItems;
use App\Filament\EndUser\Resources\WorkItems\Pages\ViewEndUserWorkItem;
use App\Filament\EndUser\Resources\WorkItems\Tables\EndUserWorkItemsTable;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class EndUserWorkItemResource extends Resource
{
    protected static ?string $model = WorkItem::class;

    protected static ?string $navigationLabel = 'My tickets';

    protected static ?string $modelLabel = 'Ticket';

    protected static ?string $pluralModelLabel = 'Tickets';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $slug = 'tickets';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->type === 'end_user';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = Auth::user();

        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('reported_by_user_id', $user->id);
    }

    public static function table(Table $table): Table
    {
        return EndUserWorkItemsTable::configure($table);
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
            Section::make('Conversation')
                ->schema([
                    RepeatableEntry::make('threaded_messages')
                        ->label('')
                        ->state(function (WorkItem $record): array {
                            $messages = $record->messages()
                                ->with('author')
                                ->where('is_internal', false)
                                ->orderBy('created_at')
                                ->get();

                            return static::flattenThreadedMessages($messages);
                        })
                        ->schema([
                            TextEntry::make('author_name')->label('Author'),
                            TextEntry::make('created_at')->label('At'),
                            TextEntry::make('body')
                                ->label('Message')
                                ->columnSpanFull()
                                ->prose(),
                        ])
                        ->columns(2)
                        ->contained(false),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEndUserWorkItems::route('/'),
            'view' => ViewEndUserWorkItem::route('/{record}'),
        ];
    }

    /**
     * @param  Collection<int, WorkItemMessage>  $messages
     * @return array<int, array<string, string>>
     */
    public static function flattenThreadedMessages(Collection $messages): array
    {
        $byParent = $messages->groupBy('parent_message_id');
        $rows = [];

        $walk = function (?int $parentId, int $depth) use (&$walk, &$rows, $byParent): void {
            /** @var WorkItemMessage $message */
            foreach ($byParent->get($parentId, collect()) as $message) {
                $rows[] = [
                    'author_name' => $message->author?->name ?? 'Unknown user',
                    'created_at' => $message->created_at?->toDateTimeString() ?? '-',
                    'body' => str_repeat('  ', $depth).$message->body,
                ];

                $walk((int) $message->getKey(), $depth + 1);
            }
        };

        $walk(null, 0);

        return $rows;
    }
}
