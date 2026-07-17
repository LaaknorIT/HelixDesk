<?php

namespace App\Filament\Agent\Resources\WorkItemCategories;

use App\Filament\Agent\Resources\WorkItemCategories\Pages\CreateWorkItemCategory;
use App\Filament\Agent\Resources\WorkItemCategories\Pages\EditWorkItemCategory;
use App\Filament\Agent\Resources\WorkItemCategories\Pages\ListWorkItemCategories;
use App\Filament\Agent\Resources\WorkItemCategories\Schemas\WorkItemCategoryForm;
use App\Filament\Agent\Resources\WorkItemCategories\Tables\WorkItemCategoriesTable;
use App\Models\WorkItemCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class WorkItemCategoryResource extends Resource
{
    protected static ?string $model = WorkItemCategory::class;

    protected static ?string $navigationLabel = 'Categories';

    protected static ?string $modelLabel = 'Category';

    protected static ?string $pluralModelLabel = 'Categories';

    protected static ?int $navigationSort = 55;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

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

        return $user->hasPermissionTo('tenant.admin');
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
        return WorkItemCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkItemCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkItemCategories::route('/'),
            'create' => CreateWorkItemCategory::route('/create'),
            'edit' => EditWorkItemCategory::route('/{record}/edit'),
        ];
    }
}
