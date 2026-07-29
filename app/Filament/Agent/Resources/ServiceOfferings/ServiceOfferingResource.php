<?php

namespace App\Filament\Agent\Resources\ServiceOfferings;

use App\Filament\Agent\Resources\ServiceOfferings\Pages\CreateServiceOffering;
use App\Filament\Agent\Resources\ServiceOfferings\Pages\EditServiceOffering;
use App\Filament\Agent\Resources\ServiceOfferings\Pages\ListServiceOfferings;
use App\Filament\Agent\Resources\ServiceOfferings\RelationManagers\DocumentsRelationManager;
use App\Filament\Agent\Resources\ServiceOfferings\RelationManagers\RealizationAssetsRelationManager;
use App\Filament\Agent\Resources\ServiceOfferings\RelationManagers\SoftwareDeploymentsRelationManager;
use App\Filament\Agent\Resources\ServiceOfferings\RelationManagers\SuppliersRelationManager;
use App\Filament\Agent\Resources\ServiceOfferings\Schemas\ServiceOfferingForm;
use App\Filament\Agent\Resources\ServiceOfferings\Tables\ServiceOfferingsTable;
use App\Models\ServiceOffering;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ServiceOfferingResource extends Resource
{
    protected static ?string $model = ServiceOffering::class;

    protected static ?string $navigationLabel = 'Service offerings';

    protected static ?string $modelLabel = 'Service offering';

    protected static ?string $pluralModelLabel = 'Service offerings';

    protected static ?int $navigationSort = 31;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

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
            'service_offering.read',
            'service_offering.create',
            'service_offering.update',
            'service_offering.delete',
            'service_offering.admin',
        ]);
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermissionTo('service_offering.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->hasPermissionTo('service_offering.update') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return Auth::user()?->hasPermissionTo('service_offering.delete') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->hasPermissionTo('service_offering.delete') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return ServiceOfferingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServiceOfferingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SuppliersRelationManager::class,
            RealizationAssetsRelationManager::class,
            SoftwareDeploymentsRelationManager::class,
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceOfferings::route('/'),
            'create' => CreateServiceOffering::route('/create'),
            'edit' => EditServiceOffering::route('/{record}/edit'),
        ];
    }
}
