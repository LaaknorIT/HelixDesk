<?php

namespace App\Filament\Agent\Resources\DashboardLayouts;

use App\Filament\Agent\Resources\DashboardLayouts\Pages\CreateDashboardLayout;
use App\Filament\Agent\Resources\DashboardLayouts\Pages\EditDashboardLayout;
use App\Filament\Agent\Resources\DashboardLayouts\Pages\ListDashboardLayouts;
use App\Filament\Agent\Resources\DashboardLayouts\Schemas\DashboardLayoutForm;
use App\Filament\Agent\Resources\DashboardLayouts\Tables\DashboardLayoutsTable;
use App\Models\DashboardLayout;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class DashboardLayoutResource extends Resource
{
    protected static ?string $model = DashboardLayout::class;

    protected static ?string $navigationLabel = 'Dashboard layouts';

    protected static ?int $navigationSort = 53;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        if (! $user->isTenantStaff()) {
            return false;
        }

        return $user->hasPermissionTo('dashboard.admin');
    }

    public static function form(Schema $schema): Schema
    {
        return DashboardLayoutForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DashboardLayoutsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDashboardLayouts::route('/'),
            'create' => CreateDashboardLayout::route('/create'),
            'edit' => EditDashboardLayout::route('/{record}/edit'),
        ];
    }
}
