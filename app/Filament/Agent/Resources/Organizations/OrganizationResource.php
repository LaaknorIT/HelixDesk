<?php

namespace App\Filament\Agent\Resources\Organizations;

use App\Filament\Agent\Resources\Organizations\Pages\CreateOrganization;
use App\Filament\Agent\Resources\Organizations\Pages\CreateOrganizationIntegration;
use App\Filament\Agent\Resources\Organizations\Pages\EditOrganization;
use App\Filament\Agent\Resources\Organizations\Pages\EditOrganizationIntegration;
use App\Filament\Agent\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Agent\Resources\Organizations\Pages\ListOrganizationSettings;
use App\Filament\Agent\Resources\Organizations\Pages\OrganizationTree;
use App\Filament\Agent\Resources\Organizations\RelationManagers\ChildOrganizationsRelationManager;
use App\Filament\Agent\Resources\Organizations\RelationManagers\OrganizationAssetsRelationManager;
use App\Filament\Agent\Resources\Organizations\RelationManagers\OrganizationServiceOfferingsRelationManager;
use App\Filament\Agent\Resources\Organizations\RelationManagers\OrganizationUsersRelationManager;
use App\Filament\Agent\Resources\Organizations\Schemas\OrganizationForm;
use App\Filament\Agent\Resources\Organizations\Tables\OrganizationsTable;
use App\Models\Organization;
use BackedEnum;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static ?string $modelLabel = null;

    protected static ?string $pluralModelLabel = null;

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    protected static ?string $navigationLabel = 'Customers';

    public static function getNavigationLabel(): string
    {
        return (string) config('helixdesk.organizations.navigation_label', static::$navigationLabel ?? 'Customers');
    }

    public static function getModelLabel(): string
    {
        return (string) config('helixdesk.organizations.model_label', 'Customer');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) config('helixdesk.organizations.plural_model_label', 'Customers');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return config('helixdesk.organizations.navigation_group', static::$navigationGroup ?? 'Customers');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null || ! $user->isTenantStaff()) {
            return false;
        }

        return $user->hasPermissionTo('organization.read')
            || $user->hasPermissionTo('tenant.admin');
    }

    public static function canCreate(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return $user->hasPermissionTo('organization.create')
            || $user->hasPermissionTo('tenant.admin');
    }

    public static function canEdit(Model $record): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return $user->hasPermissionTo('organization.update')
            || $user->hasPermissionTo('tenant.admin');
    }

    public static function canDelete(Model $record): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return $user->hasPermissionTo('organization.delete')
            || $user->hasPermissionTo('tenant.admin');
    }

    public static function canDeleteAny(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return $user->hasPermissionTo('organization.delete')
            || $user->hasPermissionTo('tenant.admin');
    }

    public static function form(Schema $schema): Schema
    {
        return OrganizationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrganizationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ChildOrganizationsRelationManager::class,
            OrganizationAssetsRelationManager::class,
            OrganizationServiceOfferingsRelationManager::class,
            OrganizationUsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => OrganizationTree::route('/'),
            'list' => ListOrganizations::route('/list'),
            'create' => CreateOrganization::route('/create'),
            'edit' => EditOrganization::route('/{record}/edit'),
            'settings.integrations.create' => CreateOrganizationIntegration::route('/{record}/settings/integrations/create'),
            'settings.integrations.edit' => EditOrganizationIntegration::route('/{record}/settings/integrations/{integration}'),
            'settings' => ListOrganizationSettings::route('/{record}/settings'),
        ];
    }

    /**
     * @return array<NavigationItem>
     */
    public static function getRecordSubNavigation(Page $page): array
    {
        return [
            NavigationItem::make(__('Details'))
                ->icon(Heroicon::OutlinedBuildingOffice)
                ->url(static::getUrl('edit', ['record' => $page->getRecord()]))
                ->isActiveWhen(fn (): bool => $page instanceof EditOrganization),
            NavigationItem::make(__('Settings'))
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->url(static::getUrl('settings', ['record' => $page->getRecord()]))
                ->isActiveWhen(fn (): bool => $page instanceof ListOrganizationSettings
                    || $page instanceof CreateOrganizationIntegration
                    || $page instanceof EditOrganizationIntegration),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount(['users', 'children', 'assets']);
    }
}
