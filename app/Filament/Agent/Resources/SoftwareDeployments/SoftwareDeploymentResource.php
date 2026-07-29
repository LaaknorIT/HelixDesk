<?php

namespace App\Filament\Agent\Resources\SoftwareDeployments;

use App\Filament\Agent\Resources\SoftwareDeployments\Pages\CreateSoftwareDeployment;
use App\Filament\Agent\Resources\SoftwareDeployments\Pages\EditSoftwareDeployment;
use App\Filament\Agent\Resources\SoftwareDeployments\Pages\ListSoftwareDeployments;
use App\Filament\Agent\Resources\SoftwareDeployments\RelationManagers\AssetsRelationManager;
use App\Filament\Agent\Resources\SoftwareDeployments\RelationManagers\DocumentsRelationManager;
use App\Filament\Agent\Resources\SoftwareDeployments\RelationManagers\LicensesRelationManager;
use App\Filament\Agent\Resources\SoftwareDeployments\RelationManagers\ServiceOfferingsRelationManager;
use App\Filament\Agent\Resources\SoftwareDeployments\Schemas\SoftwareDeploymentForm;
use App\Filament\Agent\Resources\SoftwareDeployments\Tables\SoftwareDeploymentsTable;
use App\Models\SoftwareDeployment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class SoftwareDeploymentResource extends Resource
{
    protected static ?string $model = SoftwareDeployment::class;

    protected static ?string $navigationLabel = 'Software deployments';

    protected static ?string $modelLabel = 'Software deployment';

    protected static ?string $pluralModelLabel = 'Software deployments';

    protected static ?int $navigationSort = 35;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null || ! $user->isTenantStaff()) {
            return false;
        }

        return $user->hasAnyPermission([
            'software_deployment.read',
            'software_deployment.create',
            'software_deployment.update',
            'software_deployment.delete',
            'software_deployment.admin',
        ]);
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermissionTo('software_deployment.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->hasPermissionTo('software_deployment.update') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return Auth::user()?->hasPermissionTo('software_deployment.delete') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->hasPermissionTo('software_deployment.delete') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return SoftwareDeploymentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SoftwareDeploymentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
            LicensesRelationManager::class,
            AssetsRelationManager::class,
            ServiceOfferingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSoftwareDeployments::route('/'),
            'create' => CreateSoftwareDeployment::route('/create'),
            'edit' => EditSoftwareDeployment::route('/{record}/edit'),
        ];
    }
}
