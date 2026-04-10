<?php

namespace App\Filament\Agent\Resources\Assets;

use App\Filament\Agent\Resources\Assets\Pages\CreateAsset;
use App\Filament\Agent\Resources\Assets\Pages\EditAsset;
use App\Filament\Agent\Resources\Assets\Pages\ListAssets;
use App\Filament\Agent\Resources\Assets\RelationManagers\ChildAssetRelationshipsRelationManager;
use App\Filament\Agent\Resources\Assets\RelationManagers\ParentAssetRelationshipsRelationManager;
use App\Filament\Agent\Resources\Assets\Schemas\AssetForm;
use App\Filament\Agent\Resources\Assets\Tables\AssetsTable;
use App\Models\Asset;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class AssetResource extends Resource
{
    protected static ?string $model = Asset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServer;

    protected static string|UnitEnum|null $navigationGroup = 'CMDB';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        if (! $user->isTenantStaff()) {
            return false;
        }

        return $user->hasPermissionTo('asset.read');
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermissionTo('asset.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->hasPermissionTo('asset.update') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return Auth::user()?->hasPermissionTo('asset.delete') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->hasPermissionTo('asset.delete') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return AssetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AssetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ChildAssetRelationshipsRelationManager::class,
            ParentAssetRelationshipsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssets::route('/'),
            'create' => CreateAsset::route('/create'),
            'edit' => EditAsset::route('/{record}/edit'),
        ];
    }
}
