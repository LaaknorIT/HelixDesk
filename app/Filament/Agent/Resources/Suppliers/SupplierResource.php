<?php

namespace App\Filament\Agent\Resources\Suppliers;

use App\Filament\Agent\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Agent\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Agent\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Agent\Resources\Suppliers\RelationManagers\DocumentsRelationManager;
use App\Filament\Agent\Resources\Suppliers\Schemas\SupplierForm;
use App\Filament\Agent\Resources\Suppliers\Tables\SuppliersTable;
use App\Models\Supplier;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?int $navigationSort = 32;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Infrastructure';

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
            'supplier.read',
            'supplier.create',
            'supplier.update',
            'supplier.delete',
            'supplier.admin',
        ]);
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermissionTo('supplier.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->hasPermissionTo('supplier.update') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return Auth::user()?->hasPermissionTo('supplier.delete') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->hasPermissionTo('supplier.delete') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return SupplierForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SuppliersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
