<?php

namespace App\Filament\Agent\Resources\Queues;

use App\Filament\Agent\Resources\Queues\Pages\CreateQueue;
use App\Filament\Agent\Resources\Queues\Pages\EditQueue;
use App\Filament\Agent\Resources\Queues\Pages\ListQueues;
use App\Filament\Agent\Resources\Queues\Schemas\QueueForm;
use App\Filament\Agent\Resources\Queues\Tables\QueuesTable;
use App\Models\Queue;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class QueueResource extends Resource
{
    protected static ?string $model = Queue::class;

    protected static ?string $navigationLabel = 'Queues';

    protected static ?int $navigationSort = 25;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null || ! $user->isTenantStaff()) {
            return false;
        }

        return $user->hasPermissionTo('tenant.admin');
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermissionTo('tenant.admin') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->hasPermissionTo('tenant.admin') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return Auth::user()?->hasPermissionTo('tenant.admin') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->hasPermissionTo('tenant.admin') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return QueueForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QueuesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQueues::route('/'),
            'create' => CreateQueue::route('/create'),
            'edit' => EditQueue::route('/{record}/edit'),
        ];
    }
}
