<?php

namespace App\Filament\Agent\Resources\Documents;

use App\Filament\Agent\Resources\Documents\Pages\CreateDocument;
use App\Filament\Agent\Resources\Documents\Pages\EditDocument;
use App\Filament\Agent\Resources\Documents\Pages\ListDocuments;
use App\Filament\Agent\Resources\Documents\RelationManagers\IncidentsRelationManager;
use App\Filament\Agent\Resources\Documents\Schemas\DocumentForm;
use App\Filament\Agent\Resources\Documents\Tables\DocumentsTable;
use App\Models\Document;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $navigationLabel = 'Documentation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Documentation';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        if (! $user->isTenantStaff()) {
            return false;
        }

        return $user->hasPermissionTo('document.read');
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermissionTo('document.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->hasPermissionTo('document.update') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return Auth::user()?->hasPermissionTo('document.delete') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->hasPermissionTo('document.delete') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return DocumentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocumentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            IncidentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocuments::route('/'),
            'create' => CreateDocument::route('/create'),
            'edit' => EditDocument::route('/{record}/edit'),
        ];
    }
}
