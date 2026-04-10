<?php

namespace App\Filament\Agent\Resources\Departments;

use App\Filament\Agent\Resources\Departments\Pages\CreateDepartment;
use App\Filament\Agent\Resources\Departments\Pages\EditDepartment;
use App\Filament\Agent\Resources\Departments\Pages\ListDepartments;
use App\Filament\Agent\Resources\Departments\RelationManagers\ChildDepartmentsRelationManager;
use App\Filament\Agent\Resources\Departments\RelationManagers\DepartmentUsersRelationManager;
use App\Filament\Agent\Resources\Departments\Schemas\DepartmentForm;
use App\Filament\Agent\Resources\Departments\Tables\DepartmentsTable;
use App\Models\Department;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class DepartmentResource extends Resource
{
    protected static ?string $model = Department::class;

    protected static ?string $navigationLabel = 'Departments';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Directory';

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
        return DepartmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DepartmentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ChildDepartmentsRelationManager::class,
            DepartmentUsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDepartments::route('/'),
            'create' => CreateDepartment::route('/create'),
            'edit' => EditDepartment::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount('users');
    }
}
