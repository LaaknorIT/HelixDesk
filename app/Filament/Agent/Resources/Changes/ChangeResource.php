<?php

namespace App\Filament\Agent\Resources\Changes;

use App\Filament\Agent\Resources\Changes\Pages\CreateChange;
use App\Filament\Agent\Resources\Changes\Pages\EditChange;
use App\Filament\Agent\Resources\Changes\Pages\ListChanges;
use App\Filament\Agent\Resources\Changes\Schemas\ChangeForm;
use App\Filament\Agent\Resources\Changes\Tables\ChangesTable;
use App\Models\Change;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ChangeResource extends Resource
{
    protected static ?string $model = Change::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'ITIL';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        if (! $user->isTenantStaff()) {
            return false;
        }

        return $user->hasAnyPermission([
            'change.read',
            'change.create',
            'change.update',
            'change.delete',
            'change.approve',
            'change.admin',
        ]);
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermissionTo('change.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return $user->hasAnyPermission(['change.update', 'change.approve', 'change.admin']);
    }

    public static function canDelete(Model $record): bool
    {
        return Auth::user()?->hasPermissionTo('change.delete') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->hasPermissionTo('change.delete') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return ChangeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChangesTable::configure($table);
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
            'index' => ListChanges::route('/'),
            'create' => CreateChange::route('/create'),
            'edit' => EditChange::route('/{record}/edit'),
        ];
    }
}
