<?php

namespace App\Filament\Agent\Resources\MonitoringEvents;

use App\Filament\Agent\Resources\MonitoringEvents\Pages\ListMonitoringEvents;
use App\Filament\Agent\Resources\MonitoringEvents\Tables\MonitoringEventsTable;
use App\Models\MonitoringEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class MonitoringEventResource extends Resource
{
    protected static ?string $model = MonitoringEvent::class;

    protected static ?string $navigationLabel = 'Monitoring events';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        if (! $user->isTenantStaff()) {
            return false;
        }

        return $user->hasPermissionTo('monitoring_event.read');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return MonitoringEventsTable::configure($table);
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
            'index' => ListMonitoringEvents::route('/'),
        ];
    }
}
