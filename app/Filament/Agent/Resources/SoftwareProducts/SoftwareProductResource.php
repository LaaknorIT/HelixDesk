<?php

namespace App\Filament\Agent\Resources\SoftwareProducts;

use App\Filament\Agent\Resources\SoftwareProducts\Pages\CreateSoftwareProduct;
use App\Filament\Agent\Resources\SoftwareProducts\Pages\EditSoftwareProduct;
use App\Filament\Agent\Resources\SoftwareProducts\Pages\ListSoftwareProducts;
use App\Filament\Agent\Resources\SoftwareProducts\RelationManagers\DeploymentsRelationManager;
use App\Filament\Agent\Resources\SoftwareProducts\RelationManagers\DocumentsRelationManager;
use App\Filament\Agent\Resources\SoftwareProducts\Schemas\SoftwareProductForm;
use App\Filament\Agent\Resources\SoftwareProducts\Tables\SoftwareProductsTable;
use App\Models\SoftwareProduct;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class SoftwareProductResource extends Resource
{
    protected static ?string $model = SoftwareProduct::class;

    protected static ?string $navigationLabel = 'Software products';

    protected static ?string $modelLabel = 'Software product';

    protected static ?string $pluralModelLabel = 'Software products';

    protected static ?int $navigationSort = 34;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null || ! $user->isTenantStaff()) {
            return false;
        }

        return $user->hasAnyPermission([
            'software_product.read',
            'software_product.create',
            'software_product.update',
            'software_product.delete',
            'software_product.admin',
        ]);
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermissionTo('software_product.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->hasPermissionTo('software_product.update') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return Auth::user()?->hasPermissionTo('software_product.delete') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->hasPermissionTo('software_product.delete') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return SoftwareProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SoftwareProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DeploymentsRelationManager::class,
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSoftwareProducts::route('/'),
            'create' => CreateSoftwareProduct::route('/create'),
            'edit' => EditSoftwareProduct::route('/{record}/edit'),
        ];
    }
}
