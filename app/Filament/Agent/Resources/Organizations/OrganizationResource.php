<?php

namespace App\Filament\Agent\Resources\Organizations;

use App\Filament\Agent\Resources\Organizations\Pages\CreateOrganization;
use App\Filament\Agent\Resources\Organizations\Pages\CreateOrganizationIntegration;
use App\Filament\Agent\Resources\Organizations\Pages\EditOrganization;
use App\Filament\Agent\Resources\Organizations\Pages\EditOrganizationIntegration;
use App\Filament\Agent\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Agent\Resources\Organizations\Pages\ListOrganizationSettings;
use App\Filament\Agent\Resources\Organizations\RelationManagers\ChildOrganizationsRelationManager;
use App\Filament\Agent\Resources\Organizations\RelationManagers\DepartmentsRelationManager;
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

    protected static ?string $navigationLabel = 'Organizations';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

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
            DepartmentsRelationManager::class,
            OrganizationUsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
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
            ->withCount(['users', 'children', 'departments']);
    }
}
