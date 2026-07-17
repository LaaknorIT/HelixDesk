<?php

namespace App\Filament\Agent\Resources\WorkflowTransitions;

use App\Filament\Agent\Resources\WorkflowTransitions\Pages\CreateWorkflowTransition;
use App\Filament\Agent\Resources\WorkflowTransitions\Pages\EditWorkflowTransition;
use App\Filament\Agent\Resources\WorkflowTransitions\Pages\ListWorkflowTransitions;
use App\Filament\Agent\Resources\WorkflowTransitions\Schemas\WorkflowTransitionForm;
use App\Filament\Agent\Resources\WorkflowTransitions\Tables\WorkflowTransitionsTable;
use App\Models\WorkflowTransition;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class WorkflowTransitionResource extends Resource
{
    protected static ?string $model = WorkflowTransition::class;

    protected static ?string $navigationLabel = 'Ticket transitions';

    protected static ?string $modelLabel = 'Ticket transition';

    protected static ?string $pluralModelLabel = 'Ticket transitions';

    protected static ?int $navigationSort = 54;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('workflowDefinition', function (Builder $query): void {
                $query->where('entity_type', 'work_item')
                    ->where('is_default', true);
            });
    }

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
        return static::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canDeleteAny(): bool
    {
        return static::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return WorkflowTransitionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkflowTransitionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkflowTransitions::route('/'),
            'create' => CreateWorkflowTransition::route('/create'),
            'edit' => EditWorkflowTransition::route('/{record}/edit'),
        ];
    }
}
