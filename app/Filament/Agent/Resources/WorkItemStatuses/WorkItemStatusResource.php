<?php

namespace App\Filament\Agent\Resources\WorkItemStatuses;

use App\Filament\Agent\Resources\WorkItemStatuses\Pages\CreateWorkItemStatus;
use App\Filament\Agent\Resources\WorkItemStatuses\Pages\EditWorkItemStatus;
use App\Filament\Agent\Resources\WorkItemStatuses\Pages\ListWorkItemStatuses;
use App\Filament\Agent\Resources\WorkItemStatuses\Schemas\WorkItemStatusForm;
use App\Filament\Agent\Resources\WorkItemStatuses\Tables\WorkItemStatusesTable;
use App\Models\WorkItemStatus;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class WorkItemStatusResource extends Resource
{
    protected static ?string $model = WorkItemStatus::class;

    protected static ?string $navigationLabel = 'Ticket statuses';

    protected static ?string $modelLabel = 'Ticket status';

    protected static ?string $pluralModelLabel = 'Ticket statuses';

    protected static ?int $navigationSort = 53;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

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
        $user = Auth::user();

        if ($user === null || ! $user->isTenantStaff()) {
            return false;
        }

        return $user->hasPermissionTo('tenant.admin');
    }

    public static function canEdit(Model $record): bool
    {
        $user = Auth::user();

        if ($user === null || ! $user->isTenantStaff()) {
            return false;
        }

        return $user->hasPermissionTo('tenant.admin');
    }

    public static function canDelete(Model $record): bool
    {
        $user = Auth::user();

        if ($user === null || ! $user->isTenantStaff()) {
            return false;
        }

        if (! $user->hasPermissionTo('tenant.admin')) {
            return false;
        }

        if ($record instanceof WorkItemStatus && $record->is_system) {
            return false;
        }

        return true;
    }

    public static function canDeleteAny(): bool
    {
        $user = Auth::user();

        if ($user === null || ! $user->isTenantStaff()) {
            return false;
        }

        return $user->hasPermissionTo('tenant.admin');
    }

    public static function form(Schema $schema): Schema
    {
        return WorkItemStatusForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkItemStatusesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkItemStatuses::route('/'),
            'create' => CreateWorkItemStatus::route('/create'),
            'edit' => EditWorkItemStatus::route('/{record}/edit'),
        ];
    }
}
