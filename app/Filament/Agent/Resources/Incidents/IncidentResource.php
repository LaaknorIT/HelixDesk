<?php

namespace App\Filament\Agent\Resources\Incidents;

use App\Filament\Agent\Resources\Incidents\Pages\ListIncidents;
use App\Filament\Agent\Resources\Incidents\Pages\ViewIncident;
use App\Filament\Agent\Resources\Incidents\Schemas\IncidentForm;
use App\Filament\Agent\Resources\Incidents\Tables\IncidentsTable;
use App\Models\Incident;
use App\Models\IncidentMessage;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class IncidentResource extends Resource
{
    protected static ?string $model = Incident::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        if ($user->type !== 'agent' && $user->type !== 'tenant_admin') {
            return false;
        }

        return $user->hasPermissionTo('view_incidents');
    }

    public static function form(Schema $schema): Schema
    {
        return IncidentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IncidentsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Incident')
                ->schema([
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
                        ->state(function (Incident $record): int {
                            return Incident::query()
                                ->where('reported_by_user_id', $record->reported_by_user_id)
                                ->whereKeyNot($record->getKey())
                                ->count();
                        }),
                ])->columns(3),
            Section::make('Assets')
                ->schema([
                    TextEntry::make('asset_linking')
                        ->label('Asset linking')
                        ->state('Asset linking is not enabled yet in this environment.'),
                ]),
            Section::make('Conversation')
                ->schema([
                    RepeatableEntry::make('threaded_messages')
                        ->label('')
                        ->state(function (Incident $record): array {
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIncidents::route('/'),
            'view' => ViewIncident::route('/{record}'),
        ];
    }

    /**
     * @param  Collection<int, IncidentMessage>  $messages
     * @return array<int, array<string, string>>
     */
    public static function flattenThreadedMessages(Collection $messages, bool $includeInternal): array
    {
        $byParent = $messages->groupBy('parent_message_id');
        $rows = [];

        $walk = function (?int $parentId, int $depth) use (&$walk, &$rows, $byParent, $includeInternal): void {
            /** @var IncidentMessage $message */
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
