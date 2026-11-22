<?php

namespace App\Filament\Agent\Resources\CannedResponses;

use App\Filament\Agent\Resources\CannedResponses\Pages\CreateCannedResponse;
use App\Filament\Agent\Resources\CannedResponses\Pages\EditCannedResponse;
use App\Filament\Agent\Resources\CannedResponses\Pages\ListCannedResponses;
use App\Filament\Agent\Resources\CannedResponses\Schemas\CannedResponseForm;
use App\Filament\Agent\Resources\CannedResponses\Tables\CannedResponsesTable;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Models\CannedResponse;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class CannedResponseResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = CannedResponse::class;

    protected static ?string $navigationLabel = 'Canned responses';

    protected static ?string $modelLabel = 'canned response';

    protected static ?string $pluralModelLabel = 'canned responses';

    protected static ?int $navigationSort = 57;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Ticket setup';

    protected static ?string $recordTitleAttribute = 'title';

    public static function canAccess(): bool
    {
        return static::isTenantAdmin();
    }

    public static function canCreate(): bool
    {
        return static::isTenantAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        return static::isTenantAdmin();
    }

    public static function canDelete(Model $record): bool
    {
        return static::isTenantAdmin();
    }

    public static function canDeleteAny(): bool
    {
        return static::isTenantAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return CannedResponseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CannedResponsesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCannedResponses::route('/'),
            'create' => CreateCannedResponse::route('/create'),
            'edit' => EditCannedResponse::route('/{record}/edit'),
        ];
    }

    private static function isTenantAdmin(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->isTenantStaff() && $user->hasPermissionTo('tenant.admin');
    }
}
