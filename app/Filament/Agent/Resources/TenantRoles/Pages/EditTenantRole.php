<?php

namespace App\Filament\Agent\Resources\TenantRoles\Pages;

use App\Filament\Agent\Resources\TenantRoles\TenantRoleResource;
use App\Models\Queue;
use App\Models\TenantRole;
use App\Services\RolePermissionMatrixSync;
use App\Services\RolePermissionSync;
use App\Support\HelixdeskPermissionCatalog;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class EditTenantRole extends EditRecord
{
    protected static string $resource = TenantRoleResource::class;

    /**
     * @return array<string, array<string, bool>>
     */
    private function emptyGlobalMatrix(): array
    {
        $matrix = [];

        foreach (HelixdeskPermissionCatalog::modulesWithActionsExcludingIncident() as $moduleKey => $module) {
            $matrix[$moduleKey] = [];
            foreach ($module['actions'] as $action) {
                $matrix[$moduleKey][$action] = false;
            }
        }

        return $matrix;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var TenantRole $record */
        $record = $this->getRecord();
        $guard = config('auth.defaults.guard', 'web');

        $matrix = $this->emptyGlobalMatrix();

        foreach (RolePermissionSync::namesForRole($record, $guard) as $name) {
            $parts = explode('.', $name, 2);

            if (count($parts) === 2) {
                [$moduleKey, $action] = $parts;

                if (isset($matrix[$moduleKey][$action])) {
                    $matrix[$moduleKey][$action] = true;
                }
            }
        }

        $incidentRows = [];

        foreach ($record->queues()->orderBy('name')->get() as $queue) {
            $raw = $queue->pivot->incident_permissions ?? null;
            $decoded = [];

            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
            }

            if (! is_array($decoded)) {
                $decoded = [];
            }

            $incidentRows[] = [
                'queue_id' => $queue->getKey(),
                'actions' => RolePermissionMatrixSync::normalizeIncidentActions($decoded),
            ];
        }

        return [
            ...$data,
            'name' => $record->name,
            'global_matrix' => $matrix,
            'incident_queue_permissions' => $incidentRows,
        ];
    }

    public function form(Schema $schema): Schema
    {
        $matrixActions = HelixdeskPermissionCatalog::orderedMatrixActions();
        $modules = HelixdeskPermissionCatalog::modulesWithActionsExcludingIncident();
        $actionLabels = HelixdeskPermissionCatalog::actionLabels();

        $matrixRows = [];

        $headerCells = [
            Placeholder::make('matrix_header_module')
                ->hiddenLabel()
                ->content('Module'),
        ];

        foreach ($matrixActions as $action) {
            $headerCells[] = Placeholder::make('matrix_header_action_'.$action)
                ->hiddenLabel()
                ->content($actionLabels[$action] ?? ucfirst($action));
        }

        $matrixRows[] = Grid::make(count($matrixActions) + 1)
            ->schema($headerCells);

        foreach ($modules as $moduleKey => $module) {
            $cells = [
                Placeholder::make('matrix_row_label_'.$moduleKey)
                    ->hiddenLabel()
                    ->content($module['label']),
            ];

            foreach ($matrixActions as $action) {
                if (! in_array($action, $module['actions'], true)) {
                    $cells[] = Placeholder::make('matrix_cell_empty_'.$moduleKey.'_'.$action)
                        ->hiddenLabel()
                        ->content('');

                    continue;
                }

                $cells[] = Toggle::make("global_matrix.{$moduleKey}.{$action}")
                    ->hiddenLabel()
                    ->default(false);
            }

            $matrixRows[] = Grid::make(count($matrixActions) + 1)
                ->schema($cells);
        }

        $incidentActions = HelixdeskPermissionCatalog::incidentModuleActions();
        $repeaterSchema = [
            Select::make('queue_id')
                ->label('Queue')
                ->options(function (): array {
                    $tenant = Filament::getTenant();
                    abort_if($tenant === null, 404);

                    return Queue::query()
                        ->where('tenant_id', $tenant->getKey())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all();
                })
                ->required()
                ->searchable()
                ->preload(),
        ];

        foreach ($incidentActions as $action) {
            $repeaterSchema[] = Toggle::make("actions.{$action}")
                ->label($actionLabels[$action] ?? $action)
                ->default(false);
        }

        return $this->defaultForm($schema)
            ->columns(1)
            ->components([
                Section::make('Role')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                    ]),
                Section::make('Permissions')
                    ->description('Capabilities for this role. Incident actions are configured per queue below.')
                    ->schema($matrixRows),
                Section::make('Incident permissions by queue')
                    ->description('Add a queue, then choose which incident actions apply in that queue. Users with this role only see incidents in queues listed here.')
                    ->schema([
                        Repeater::make('incident_queue_permissions')
                            ->label('')
                            ->addActionLabel('Add queue')
                            ->reorderable(false)
                            ->schema($repeaterSchema)
                            ->default([]),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var TenantRole $record */
        $record->update([
            'name' => $data['name'],
        ]);

        RolePermissionMatrixSync::sync(
            $record,
            $data['global_matrix'] ?? [],
            $data['incident_queue_permissions'] ?? [],
        );

        return $record;
    }
}
