<?php

namespace App\Filament\EndUser\Resources\WorkItems;

use App\Filament\Agent\Resources\WorkItems\WorkItemResource;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\EndUser\Resources\WorkItems\Pages\ListEndUserWorkItems;
use App\Filament\EndUser\Resources\WorkItems\Pages\ViewEndUserWorkItem;
use App\Filament\EndUser\Resources\WorkItems\Tables\EndUserWorkItemsTable;
use App\Models\WorkItem;
use App\Support\WorkItemStatusDisplay;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class EndUserWorkItemResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = WorkItem::class;

    protected static ?string $navigationLabel = 'My tickets';

    protected static ?string $modelLabel = 'ticket';

    protected static ?string $pluralModelLabel = 'tickets';

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
            Section::make()
                ->schema([
                    TextEntry::make('id')
                        ->label(__('Ticket number'))
                        ->formatStateUsing(fn (int|string $state): string => '#'.$state)
                        ->weight(FontWeight::SemiBold)
                        ->copyable(),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => WorkItemStatusDisplay::label($state))
                        ->color(fn (?string $state): string|array => WorkItemStatusDisplay::color($state)),
                    TextEntry::make('type')
                        ->label(__('Type'))
                        ->badge(),
                    TextEntry::make('opened_at')
                        ->label(__('Submitted'))
                        ->dateTime(),
                ])
                ->columns(['default' => 2, 'md' => 4])
                ->columnSpanFull(),
            Section::make(__('Conversation'))
                ->schema([
                    RepeatableEntry::make('conversation')
                        ->hiddenLabel()
                        ->state(function (WorkItem $record): array {
                            $messages = $record->messages()
                                ->with('author')
                                ->where('is_internal', false)
                                ->orderBy('created_at')
                                ->get();

                            return WorkItemResource::conversationEntries($record, $messages, false);
                        })
                        ->schema([
                            TextEntry::make('author_name')
                                ->hiddenLabel()
                                ->weight(FontWeight::SemiBold),
                            TextEntry::make('created_at')
                                ->hiddenLabel()
                                ->dateTime()
                                ->color('gray'),
                            TextEntry::make('body')
                                ->hiddenLabel()
                                ->columnSpanFull()
                                ->prose(),
                        ])
                        ->columns(2)
                        ->contained(true),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEndUserWorkItems::route('/'),
            'view' => ViewEndUserWorkItem::route('/{record}'),
        ];
    }
}
