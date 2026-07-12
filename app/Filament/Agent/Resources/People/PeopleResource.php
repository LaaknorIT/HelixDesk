<?php

namespace App\Filament\Agent\Resources\People;

use App\Filament\Agent\Resources\People\Pages\CreatePeople;
use App\Filament\Agent\Resources\People\Pages\EditPeople;
use App\Filament\Agent\Resources\People\Pages\ListPeople;
use App\Filament\Agent\Resources\People\Schemas\PeopleForm;
use App\Filament\Agent\Resources\People\Tables\PeopleTable;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class PeopleResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationLabel = 'People';

    protected static ?string $modelLabel = 'Person';

    protected static ?string $pluralModelLabel = 'People';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

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
        return PeopleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PeopleTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPeople::route('/'),
            'create' => CreatePeople::route('/create'),
            'edit' => EditPeople::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Filament::getTenant()->id);
    }
}
