<?php

namespace App\Observers;

use App\Enums\AutomationActionType;
use App\Enums\AutomationTrigger;
use App\Enums\SlaCalendarType;
use App\Enums\SlaTargetType;
use App\Models\AssetType;
use App\Models\AutomationRule;
use App\Models\AutomationRuleAction;
use App\Models\DashboardLayout;
use App\Models\MonitoringEventSource;
use App\Models\Queue;
use App\Models\SlaPolicy;
use App\Models\Tenant;
use App\Models\TenantRole;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowTransition;
use App\Models\WorkItemStatus;
use App\Services\RolePermissionSync;
use App\Support\HelixdeskPermissionCatalog;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\PermissionRegistrar;

class TenantObserver
{
    public function created(Tenant $tenant): void
    {
        $this->seedTenantPermissionsAndRoles($tenant);

        $this->seedDefaultWorkItemStatuses($tenant);
        $this->seedDefaultWorkItemWorkflow($tenant);
        $this->seedDefaultWorkItemAutomations($tenant);
        $this->seedDefaultSlaPolicies($tenant);
        $this->seedDefaultQueue($tenant);
        $this->seedDefaultChangeWorkflow($tenant);
        $this->seedDefaultAssetTypes($tenant);
        $this->seedDefaultMonitoringQueue($tenant);
        $this->seedDefaultRoleQueues($tenant);
        $this->seedDefaultMonitoringEventSource($tenant);
        $this->seedDefaultDashboardLayouts($tenant);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedTenantPermissionsAndRoles(Tenant $tenant): void
    {
        $roleModelClass = Config::get('permission.models.role');
        $teamForeignKey = Config::get('permission.column_names.team_foreign_key', 'team_id');
        $guard = Config::get('auth.defaults.guard', 'web');

        foreach (config('helixdesk_permissions.tenant_roles', []) as $roleConfig) {
            $roleName = $roleConfig['name'];

            $roleModelClass::query()->firstOrCreate([
                $teamForeignKey => $tenant->getKey(),
                'name' => $roleName,
                'guard_name' => $guard,
            ]);

            /** @var TenantRole $role */
            $role = $roleModelClass::query()
                ->where($teamForeignKey, $tenant->getKey())
                ->where('name', $roleName)
                ->where('guard_name', $guard)
                ->firstOrFail();

            $assignment = $roleConfig['permissions'] ?? [];
            $expanded = HelixdeskPermissionCatalog::expandRoleAssignment($assignment);
            $forRolePermissionsTable = array_values(array_filter(
                $expanded,
                static function (string $permission): bool {
                    if (! str_starts_with($permission, 'work_item.')) {
                        return true;
                    }

                    return $permission === 'work_item.admin';
                }
            ));

            RolePermissionSync::sync($role, $forRolePermissionsTable, $guard);
        }
    }

    private function seedDefaultQueue(Tenant $tenant): void
    {
        Queue::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'name' => 'General',
            ],
            [
                'description' => 'Default service desk queue',
            ]
        );
    }

    private function seedDefaultWorkItemStatuses(Tenant $tenant): void
    {
        foreach ($this->defaultWorkItemStatuses() as $index => $status) {
            WorkItemStatus::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->getKey(),
                    'name' => $status['name'],
                ],
                [
                    'sort_order' => $index,
                    'is_system' => true,
                    'is_default_open' => $status['is_default_open'],
                    'pauses_sla' => $status['pauses_sla'],
                    'sets_resolved_at' => $status['sets_resolved_at'],
                    'sets_closed_at' => $status['sets_closed_at'],
                ]
            );
        }
    }

    /**
     * @return array<int, array{name: string, is_default_open: bool, pauses_sla: bool, sets_resolved_at: bool, sets_closed_at: bool}>
     */
    private function defaultWorkItemStatuses(): array
    {
        return [
            [
                'name' => 'New',
                'is_default_open' => true,
                'pauses_sla' => false,
                'sets_resolved_at' => false,
                'sets_closed_at' => false,
            ],
            [
                'name' => 'Assigned',
                'is_default_open' => false,
                'pauses_sla' => false,
                'sets_resolved_at' => false,
                'sets_closed_at' => false,
            ],
            [
                'name' => 'Working',
                'is_default_open' => false,
                'pauses_sla' => false,
                'sets_resolved_at' => false,
                'sets_closed_at' => false,
            ],
            [
                'name' => 'Waiting for customer',
                'is_default_open' => false,
                'pauses_sla' => true,
                'sets_resolved_at' => false,
                'sets_closed_at' => false,
            ],
            [
                'name' => 'Replied',
                'is_default_open' => false,
                'pauses_sla' => false,
                'sets_resolved_at' => false,
                'sets_closed_at' => false,
            ],
            [
                'name' => 'Closed (pending)',
                'is_default_open' => false,
                'pauses_sla' => true,
                'sets_resolved_at' => true,
                'sets_closed_at' => false,
            ],
            [
                'name' => 'Closed',
                'is_default_open' => false,
                'pauses_sla' => false,
                'sets_resolved_at' => true,
                'sets_closed_at' => true,
            ],
        ];
    }

    private function seedDefaultWorkItemWorkflow(Tenant $tenant): void
    {
        $workflowDefinition = WorkflowDefinition::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'entity_type' => 'work_item',
                'is_default' => true,
            ],
            ['name' => 'Default Work Item Workflow']
        );

        $desired = $this->defaultWorkItemTransitions();
        $desiredKeys = [];

        foreach ($desired as $transition) {
            $desiredKeys[] = $transition['from_status'].'|'.$transition['to_status'];

            WorkflowTransition::query()->updateOrCreate([
                'tenant_id' => $tenant->getKey(),
                'workflow_definition_id' => $workflowDefinition->getKey(),
                'from_status' => $transition['from_status'],
                'to_status' => $transition['to_status'],
            ], [
                'required_permission' => $transition['required_permission'],
            ]);
        }

        // Drop legacy default transitions from earlier status names when reseeding.
        WorkflowTransition::query()
            ->where('workflow_definition_id', $workflowDefinition->getKey())
            ->get()
            ->each(function (WorkflowTransition $transition) use ($desiredKeys): void {
                $key = $transition->from_status.'|'.$transition->to_status;
                if (! in_array($key, $desiredKeys, true)) {
                    $legacyFrom = in_array($transition->from_status, ['In Progress', 'Waiting for User', 'Resolved'], true);
                    $legacyTo = in_array($transition->to_status, ['In Progress', 'Waiting for User', 'Resolved'], true);
                    if ($legacyFrom || $legacyTo) {
                        $transition->delete();
                    }
                }
            });
    }

    /**
     * @return array<int, array{from_status: string, to_status: string, required_permission: ?string}>
     */
    private function defaultWorkItemTransitions(): array
    {
        $permission = 'work_item.update';

        return [
            [
                'from_status' => 'New',
                'to_status' => 'Assigned',
                'required_permission' => $permission,
            ],
            [
                'from_status' => 'Assigned',
                'to_status' => 'Working',
                'required_permission' => $permission,
            ],
            [
                'from_status' => 'Working',
                'to_status' => 'Waiting for customer',
                'required_permission' => $permission,
            ],
            [
                'from_status' => 'Working',
                'to_status' => 'Closed (pending)',
                'required_permission' => $permission,
            ],
            [
                'from_status' => 'Waiting for customer',
                'to_status' => 'Working',
                'required_permission' => $permission,
            ],
            [
                'from_status' => 'Replied',
                'to_status' => 'Working',
                'required_permission' => $permission,
            ],
            [
                'from_status' => 'Replied',
                'to_status' => 'Waiting for customer',
                'required_permission' => $permission,
            ],
            [
                'from_status' => 'Closed (pending)',
                'to_status' => 'Closed',
                // End users can close their own tickets once pending close.
                'required_permission' => null,
            ],
            [
                'from_status' => 'Closed (pending)',
                'to_status' => 'Working',
                'required_permission' => $permission,
            ],
            [
                'from_status' => 'Closed',
                'to_status' => 'Working',
                'required_permission' => $permission,
            ],
        ];
    }

    private function seedDefaultWorkItemAutomations(Tenant $tenant): void
    {
        $repliedRule = AutomationRule::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'name' => 'Customer reply → Replied',
                'is_system' => true,
            ],
            [
                'is_enabled' => true,
                'trigger' => AutomationTrigger::CustomerReplied->value,
                'conditions_json' => [
                    'statuses' => [
                        'Assigned',
                        'Working',
                        'Waiting for customer',
                        'Closed (pending)',
                    ],
                ],
                'sort_order' => 10,
            ]
        );

        AutomationRuleAction::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'automation_rule_id' => $repliedRule->getKey(),
                'action_type' => AutomationActionType::SetStatus->value,
                'sort_order' => 0,
            ],
            [
                'action_config_json' => ['status' => 'Replied'],
            ]
        );

        $newTicketRule = AutomationRule::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'name' => 'Customer reply on Closed → new ticket',
                'is_system' => true,
            ],
            [
                'is_enabled' => true,
                'trigger' => AutomationTrigger::CustomerReplied->value,
                'conditions_json' => [
                    'statuses' => ['Closed'],
                ],
                'sort_order' => 20,
            ]
        );

        AutomationRuleAction::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'automation_rule_id' => $newTicketRule->getKey(),
                'action_type' => AutomationActionType::CreateWorkItem->value,
                'sort_order' => 0,
            ],
            [
                'action_config_json' => [
                    'link_to_original' => true,
                    'move_reply_message' => true,
                ],
            ]
        );
    }

    private function seedDefaultSlaPolicies(Tenant $tenant): void
    {
        SlaPolicy::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'name' => 'Normal',
            ],
            [
                'is_active' => true,
                'target_type' => SlaTargetType::FirstResponse->value,
                'first_response_minutes' => 8 * 60,
                'resolution_minutes' => null,
                'calendar_type' => SlaCalendarType::WorkingHours->value,
                'applies_to_priority' => 'normal',
                'match_priority' => 0,
            ]
        );

        SlaPolicy::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'name' => 'Gold',
            ],
            [
                'is_active' => true,
                'target_type' => SlaTargetType::Resolution->value,
                'first_response_minutes' => null,
                'resolution_minutes' => 8 * 60,
                'calendar_type' => SlaCalendarType::Continuous->value,
                'applies_to_priority' => 'urgent',
                'match_priority' => 10,
            ]
        );
    }

    private function seedDefaultChangeWorkflow(Tenant $tenant): void
    {
        $workflowDefinition = WorkflowDefinition::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'entity_type' => 'change',
                'is_default' => true,
            ],
            ['name' => 'Default Change Workflow']
        );

        $manage = 'change.update';
        $approve = 'change.approve';

        foreach ([
            ['from_status' => 'Draft', 'to_status' => 'Review', 'required_permission' => $manage],
            ['from_status' => 'Review', 'to_status' => 'Approved', 'required_permission' => $approve],
            ['from_status' => 'Approved', 'to_status' => 'Scheduled', 'required_permission' => $manage],
            ['from_status' => 'Scheduled', 'to_status' => 'Implemented', 'required_permission' => $manage],
            ['from_status' => 'Implemented', 'to_status' => 'Closed', 'required_permission' => $manage],
        ] as $transition) {
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

    private function seedDefaultAssetTypes(Tenant $tenant): void
    {
        foreach (['PC', 'Server', 'Network equipment', 'Application', 'License'] as $name) {
            AssetType::query()->firstOrCreate(
                [
                    'tenant_id' => $tenant->getKey(),
                    'name' => $name,
                ],
            );
        }
    }

    private function seedDefaultMonitoringQueue(Tenant $tenant): void
    {
        Queue::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'name' => 'Monitoring',
            ],
            [
                'description' => 'Monitoring and NOC alert routing',
            ]
        );
    }

    private function seedDefaultRoleQueues(Tenant $tenant): void
    {
        $guard = Config::get('auth.defaults.guard', 'web');
        $tenantAdminName = config('helixdesk_permissions.tenant_roles.tenant_admin.name');
        $agentName = config('helixdesk_permissions.tenant_roles.agent.name');

        $allQueueIds = Queue::query()->where('tenant_id', $tenant->getKey())->pluck('id')->all();

        $tenantAdmin = TenantRole::query()
            ->where('team_id', $tenant->getKey())
            ->where('name', $tenantAdminName)
            ->where('guard_name', $guard)
            ->first();

        if ($tenantAdmin !== null && $allQueueIds !== []) {
            $tenantAdminConfig = config('helixdesk_permissions.tenant_roles.tenant_admin', []);
            $expanded = HelixdeskPermissionCatalog::expandRoleAssignment($tenantAdminConfig['permissions'] ?? []);
            $workItemPayload = $this->workItemPermissionBoolMapFromExpanded($expanded);
            $sync = [];

            foreach ($allQueueIds as $queueId) {
                $sync[(int) $queueId] = ['work_item_permissions' => json_encode($workItemPayload)];
            }

            $tenantAdmin->queues()->sync($sync);
        }

        $agentRole = TenantRole::query()
            ->where('team_id', $tenant->getKey())
            ->where('name', $agentName)
            ->where('guard_name', $guard)
            ->first();

        if ($agentRole === null) {
            return;
        }

        $ids = array_values(array_filter([
            $tenant->generalQueue()?->getKey(),
            $tenant->monitoringQueue()?->getKey(),
        ]));

        if ($ids !== []) {
            $agentConfig = config('helixdesk_permissions.tenant_roles.agent', []);
            $expanded = HelixdeskPermissionCatalog::expandRoleAssignment($agentConfig['permissions'] ?? []);
            $workItemPayload = $this->workItemPermissionBoolMapFromExpanded($expanded);
            $sync = [];

            foreach ($ids as $queueId) {
                $sync[(int) $queueId] = ['work_item_permissions' => json_encode($workItemPayload)];
            }

            $agentRole->queues()->sync($sync);
        }
    }

    /**
     * @param  list<string>  $expandedNames
     * @return array<string, bool>
     */
    private function workItemPermissionBoolMapFromExpanded(array $expandedNames): array
    {
        $actions = config('helixdesk_permissions.modules.work_item.actions', []);
        $map = array_fill_keys($actions, false);

        foreach ($expandedNames as $name) {
            if ($name === 'work_item.admin') {
                foreach ($actions as $action) {
                    $map[$action] = true;
                }

                break;
            }

            if (str_starts_with($name, 'work_item.')) {
                $action = substr($name, strlen('work_item.'));

                if (array_key_exists($action, $map)) {
                    $map[$action] = true;
                }
            }
        }

        return $map;
    }

    private function seedDefaultMonitoringEventSource(Tenant $tenant): void
    {
        MonitoringEventSource::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'slug' => 'api',
            ],
            [
                'name' => 'API / Webhook',
                'provider' => 'api',
                'configuration_json' => null,
            ]
        );
    }

    private function seedDefaultDashboardLayouts(Tenant $tenant): void
    {
        $agentRoleName = config('helixdesk_permissions.tenant_roles.agent.name');
        $tenantAdminRoleName = config('helixdesk_permissions.tenant_roles.tenant_admin.name');

        DashboardLayout::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'scope' => DashboardLayout::SCOPE_ROLE_DEFAULT,
                'role_name' => $agentRoleName,
            ],
            [
                'user_id' => null,
                'layout_json' => [
                    ['widget' => 'my_work_items', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 3],
                    ['widget' => 'work_items_in_queue', 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 3],
                    ['widget' => 'monitoring_events', 'x' => 0, 'y' => 3, 'w' => 12, 'h' => 3],
                ],
                'locked_widgets' => ['my_work_items'],
                'is_active' => true,
            ],
        );

        DashboardLayout::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'scope' => DashboardLayout::SCOPE_ROLE_DEFAULT,
                'role_name' => $tenantAdminRoleName,
            ],
            [
                'user_id' => null,
                'layout_json' => [
                    ['widget' => 'major_work_items', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 3],
                    ['widget' => 'sla_breaches', 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 3],
                    ['widget' => 'kpis', 'x' => 0, 'y' => 3, 'w' => 12, 'h' => 3],
                ],
                'locked_widgets' => ['major_work_items'],
                'is_active' => true,
            ],
        );
    }
}
