<?php

namespace App\Observers;

use App\Models\Tenant;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowTransition;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class TenantObserver
{
    public function created(Tenant $tenant): void
    {
        $roleModelClass = Config::get('permission.models.role');
        $permissionModelClass = Config::get('permission.models.permission');
        $teamForeignKey = Config::get('permission.column_names.team_foreign_key', 'team_id');
        $guard = Config::get('auth.defaults.guard', 'web');

        $permissions = collect($this->defaultPermissions())
            ->map(fn (string $permissionName) => $permissionModelClass::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => $guard,
            ]));

        foreach (['Admin', 'Agent'] as $roleName) {
            $roleModelClass::query()->firstOrCreate([
                $teamForeignKey => $tenant->getKey(),
                'name' => $roleName,
                'guard_name' => $guard,
            ]);
        }

        /** @var Role $adminRole */
        $adminRole = $roleModelClass::query()
            ->where($teamForeignKey, $tenant->getKey())
            ->where('name', 'Admin')
            ->where('guard_name', $guard)
            ->firstOrFail();

        $adminRole->syncPermissions($permissions);

        $agentRole = $roleModelClass::query()
            ->where($teamForeignKey, $tenant->getKey())
            ->where('name', 'Agent')
            ->where('guard_name', $guard)
            ->firstOrFail();

        $agentRole->syncPermissions($this->defaultAgentPermissions());

        $this->seedDefaultIncidentWorkflow($tenant);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return list<string>
     */
    private function defaultPermissions(): array
    {
        return [
            'view_incidents',
            'manage_incidents',
            'manage_problems',
            'manage_changes',
            'view_assets',
            'manage_assets',
            'view_docs',
            'edit_docs',
            'manage_permissions',
            'approve_changes',
        ];
    }

    /**
     * Permissions required for v1 incident management actions.
     *
     * @return array<string>
     */
    private function defaultAgentPermissions(): array
    {
        return [
            'view_incidents',
            'manage_incidents',
        ];
    }

    private function seedDefaultIncidentWorkflow(Tenant $tenant): void
    {
        $workflowDefinition = WorkflowDefinition::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'entity_type' => 'incident',
                'is_default' => true,
            ],
            ['name' => 'Default Incident Workflow']
        );

        foreach ($this->defaultIncidentTransitions() as $transition) {
            WorkflowTransition::query()->updateOrCreate([
                'tenant_id' => $tenant->getKey(),
                'workflow_definition_id' => $workflowDefinition->getKey(),
                'from_status' => $transition['from_status'],
                'to_status' => $transition['to_status'],
            ], [
                'required_permission' => $transition['required_permission'],
            ]);
        }
    }

    /**
     * @return array<int, array{from_status: string, to_status: string, required_permission: ?string}>
     */
    private function defaultIncidentTransitions(): array
    {
        $permission = 'manage_incidents';

        return [
            [
                'from_status' => 'New',
                'to_status' => 'Assigned',
                'required_permission' => $permission,
            ],
            [
                'from_status' => 'Assigned',
                'to_status' => 'In Progress',
                'required_permission' => $permission,
            ],
            [
                'from_status' => 'In Progress',
                'to_status' => 'Waiting for User',
                'required_permission' => $permission,
            ],
            [
                'from_status' => 'In Progress',
                'to_status' => 'Resolved',
                'required_permission' => $permission,
            ],
            [
                'from_status' => 'Waiting for User',
                'to_status' => 'In Progress',
                'required_permission' => $permission,
            ],
            [
                'from_status' => 'Resolved',
                'to_status' => 'Closed',
                // End users can close their own incidents once they are resolved.
                'required_permission' => null,
            ],
            [
                'from_status' => 'Resolved',
                'to_status' => 'In Progress',
                'required_permission' => $permission,
            ],
        ];
    }
}
