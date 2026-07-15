<?php

namespace App\Filament\Agent\Resources\AssetIdentityAmbiguities;

use App\Filament\Agent\Resources\AssetIdentityAmbiguities\Pages\ListAssetIdentityAmbiguities;
use App\Filament\Agent\Resources\AssetIdentityAmbiguities\Pages\ViewAssetIdentityAmbiguity;
use App\Filament\Agent\Resources\AssetIdentityAmbiguities\Tables\AssetIdentityAmbiguitiesTable;
use App\Models\AssetIdentityAmbiguity;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class AssetIdentityAmbiguityResource extends Resource
{
    protected static ?string $model = AssetIdentityAmbiguity::class;

    protected static ?string $navigationLabel = 'Identity ambiguities';

    protected static ?string $modelLabel = 'Identity ambiguity';

    protected static ?string $pluralModelLabel = 'Identity ambiguities';

    protected static ?int $navigationSort = 33;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Infrastructure';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null || ! $user->isTenantStaff()) {
            return false;
        }

        return $user->hasPermissionTo('asset.read');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return AssetIdentityAmbiguitiesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['organization', 'organizationIntegration', 'resolvedAsset']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssetIdentityAmbiguities::route('/'),
            'view' => ViewAssetIdentityAmbiguity::route('/{record}'),
        ];
    }
}
