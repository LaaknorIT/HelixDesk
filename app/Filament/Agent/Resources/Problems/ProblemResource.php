<?php

namespace App\Filament\Agent\Resources\Problems;

use App\Filament\Agent\Resources\Problems\Pages\CreateProblem;
use App\Filament\Agent\Resources\Problems\Pages\EditProblem;
use App\Filament\Agent\Resources\Problems\Pages\ListProblems;
use App\Filament\Agent\Resources\Problems\RelationManagers\WorkItemsRelationManager;
use App\Filament\Agent\Resources\Problems\Schemas\ProblemForm;
use App\Filament\Agent\Resources\Problems\Tables\ProblemsTable;
use App\Models\Problem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ProblemResource extends Resource
{
    protected static ?string $model = Problem::class;

    protected static ?int $navigationSort = 21;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Service management';

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
            'problem.read',
            'problem.create',
            'problem.update',
            'problem.delete',
            'problem.admin',
        ]);
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermissionTo('problem.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->hasPermissionTo('problem.update') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return Auth::user()?->hasPermissionTo('problem.delete') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->hasPermissionTo('problem.delete') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return ProblemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProblemsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            WorkItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProblems::route('/'),
            'create' => CreateProblem::route('/create'),
            'edit' => EditProblem::route('/{record}/edit'),
        ];
    }
}
