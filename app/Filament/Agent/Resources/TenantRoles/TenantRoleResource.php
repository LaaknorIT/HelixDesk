<?php

namespace App\Filament\Agent\Resources\TenantRoles;

use App\Filament\Agent\Resources\TenantRoles\Pages\CreateTenantRole;
use App\Filament\Agent\Resources\TenantRoles\Pages\EditTenantRole;
use App\Filament\Agent\Resources\TenantRoles\Pages\ListTenantRoles;
use App\Filament\Agent\Resources\TenantRoles\Tables\TenantRolesTable;
use App\Models\TenantRole;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class TenantRoleResource extends Resource
{
    protected static ?string $model = TenantRole::class;

    protected static ?string $navigationLabel = 'Roles';

    protected static ?string $modelLabel = 'Role';

    protected static ?string $pluralModelLabel = 'Roles';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

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
        if (! (Auth::user()?->hasPermissionTo('tenant.admin') ?? false)) {
            return false;
        }

        return ! self::isReservedSystemRole($record);
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->hasPermissionTo('tenant.admin') ?? false;
    }

    public static function isReservedSystemRole(Model $record): bool
    {
        $names = collect(config('helixdesk_permissions.tenant_roles', []))
            ->pluck('name')
            ->map(fn ($n): string => (string) $n)
            ->all();

        return in_array($record->name, $names, true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Role name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return TenantRolesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenantRoles::route('/'),
            'create' => CreateTenantRole::route('/create'),
            'edit' => EditTenantRole::route('/{record}/edit'),
        ];
    }
}
