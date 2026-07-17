<?php

namespace App\Filament\Agent\Resources\AutomationRules;

use App\Filament\Agent\Resources\AutomationRules\Pages\CreateAutomationRule;
use App\Filament\Agent\Resources\AutomationRules\Pages\EditAutomationRule;
use App\Filament\Agent\Resources\AutomationRules\Pages\ListAutomationRules;
use App\Filament\Agent\Resources\AutomationRules\Schemas\AutomationRuleForm;
use App\Filament\Agent\Resources\AutomationRules\Tables\AutomationRulesTable;
use App\Models\AutomationRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class AutomationRuleResource extends Resource
{
    protected static ?string $model = AutomationRule::class;

    protected static ?string $navigationLabel = 'Ticket automations';

    protected static ?string $modelLabel = 'Ticket automation';

    protected static ?string $pluralModelLabel = 'Ticket automations';

    protected static ?int $navigationSort = 56;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

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

        if (! $user->hasPermissionTo('tenant.admin')) {
            return false;
        }

        if ($record instanceof AutomationRule && $record->is_system) {
            return false;
        }

        return true;
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
        return AutomationRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AutomationRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAutomationRules::route('/'),
            'create' => CreateAutomationRule::route('/create'),
            'edit' => EditAutomationRule::route('/{record}/edit'),
        ];
    }
}
