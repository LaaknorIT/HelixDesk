<?php

namespace Database\Seeders;

use App\Enums\OrganizationType;
use App\Enums\WorkItemSource;
use App\Enums\WorkItemType;
use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Organization;
use App\Models\ServiceOffering;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Observers\TenantObserver;
use App\Services\WorkItemWorkflowService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoContentSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            [
                'slug' => 'demo-tenant',
            ],
            [
                'name' => 'Demo Tenant',
                'status' => 'active',
                'region' => 'eu',
            ],
        );

        // Ensure tenant roles, permissions, default queues/workflows are present
        // and up-to-date (idempotent).
        app(TenantObserver::class)->created($tenant);

        $organization = Organization::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'name' => 'Demo Customer',
            ],
            [
                'type' => OrganizationType::Customer,
                'parent_id' => Organization::defaultParentKey(),
                'is_billable' => true,
                'external_reference' => null,
            ],
        );

        $demoDepartment = Organization::query()->withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'parent_id' => $organization->getKey(),
                'type' => OrganizationType::Department,
                'name' => 'Customer Care',
            ],
            [
                'code' => 'CC',
                'external_reference' => null,
                'is_billable' => false,
                'order' => 0,
            ],
        );

        $demoSupplier = Supplier::query()->withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'name' => 'Demo Carrier',
            ],
            [
                'notes' => 'Sample supplier for the service catalog.',
            ],
        );

        $demoOffering = ServiceOffering::query()->withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'organization_id' => $organization->getKey(),
                'name' => 'Demo Internet connectivity',
            ],
            [
                'description' => 'Illustrative managed connectivity offering.',
                'code' => 'DEMO-INET',
                'kind' => 'internet_connectivity',
                'attributes_json' => ['line_number' => 'LN-DEMO-001'],
            ],
        );

        if (! $demoOffering->suppliers()->whereKey($demoSupplier->getKey())->exists()) {
            $demoOffering->suppliers()->attach($demoSupplier->getKey(), [
                'role' => 'primary',
                'notes' => null,
            ]);
        }

        $networkEquipmentType = AssetType::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('name', 'Network equipment')
            ->first();

        if ($networkEquipmentType !== null) {
            $demoCpe = Asset::query()->withoutGlobalScopes()->firstOrCreate(
                [
                    'tenant_id' => $tenant->getKey(),
                    'organization_id' => $organization->getKey(),
                    'asset_type_id' => $networkEquipmentType->getKey(),
                    'name' => 'Demo primary CPE',
                ],
                [
                    'lifecycle_status' => 'in_use',
                ],
            );

            if (! $demoOffering->realizationAssets()->whereKey($demoCpe->getKey())->exists()) {
                $demoOffering->realizationAssets()->attach($demoCpe->getKey(), [
                    'role' => 'primary_access',
                ]);
            }
        }

        $generalQueue = $tenant->generalQueue();

        $password = Hash::make('password');

        $globalAdmin = User::query()->updateOrCreate(
            ['email' => 'demo-globaladmin@helixdesk.local'],
            [
                'name' => 'Demo Global Admin',
                'password' => $password,
                'is_admin' => true,
                'tenant_id' => null,
                'type' => 'global_admin',
            ],
        );

        // Line agent: Agent role only (no Settings / tenant.admin in navigation).
        $agent = User::query()->updateOrCreate(
            ['email' => 'demo-agent@helixdesk.local'],
            [
                'name' => 'Demo Agent',
                'password' => $password,
                'is_admin' => false,
                'tenant_id' => $tenant->getKey(),
                'type' => 'agent',
            ],
        );

        // Tenant admin: full catalog including tenant.admin and dashboard.admin (Administration navigation).
        $tenantAdminUser = User::query()->updateOrCreate(
            ['email' => 'demo-tenantadmin@helixdesk.local'],
            [
                'name' => 'Demo Tenant Admin',
                'password' => $password,
                'is_admin' => false,
                'tenant_id' => $tenant->getKey(),
                'type' => 'agent',
            ],
        );

        setPermissionsTeamId($tenant->getKey());
        $agent->syncRoles(['Agent']);
        $tenantAdminUser->syncRoles(['Tenant Admin']);
        setPermissionsTeamId(null);

        // Two end users to demonstrate "only see your own tickets".
        $endUser1 = User::query()->updateOrCreate(
            ['email' => 'demo-enduser1@helixdesk.local'],
            [
                'name' => 'Demo End User 1',
                'password' => $password,
                'is_admin' => false,
                'tenant_id' => $tenant->getKey(),
                'organization_id' => $demoDepartment->getKey(),
                'type' => 'end_user',
            ],
        );

        $endUser2 = User::query()->updateOrCreate(
            ['email' => 'demo-enduser2@helixdesk.local'],
            [
                'name' => 'Demo End User 2',
                'password' => $password,
                'is_admin' => false,
                'tenant_id' => $tenant->getKey(),
                'organization_id' => $demoDepartment->getKey(),
                'type' => 'end_user',
            ],
        );

        // Clear prior demo tickets/messages to keep the seeder idempotent.
        $demoWorkItemIds = WorkItem::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('title', 'like', 'Demo%')
            ->pluck('id');

        if ($demoWorkItemIds->isNotEmpty()) {
            WorkItemMessage::query()
                ->where('tenant_id', $tenant->getKey())
                ->whereIn('work_item_id', $demoWorkItemIds)
                ->delete();

            WorkItem::query()
                ->where('tenant_id', $tenant->getKey())
                ->whereIn('id', $demoWorkItemIds)
                ->delete();
        }

        $workflow = app(WorkItemWorkflowService::class);

        // End-user 1: open ticket + a reply.
        $endUser1WorkItem = $workflow->createWorkItem([
            'title' => 'Demo (End User 1) - Open ticket',
            'description' => 'Demo work item created in local environment.',
            'priority' => 'High',
            'type' => WorkItemType::Incident,
        ], $endUser1);

        $workflow->addMessage(
            $endUser1WorkItem,
            'Demo reply from End User 1.',
            $endUser1
        );

        // End-user 2: closed-pending ticket (end-user should be able to close it).
        WorkItem::query()->create([
            'tenant_id' => $tenant->getKey(),
            'organization_id' => $organization->getKey(),
            'reported_by_user_id' => $endUser2->getKey(),
            'assigned_agent_id' => null,
            'queue_id' => $generalQueue?->getKey(),
            'type' => WorkItemType::Incident,
            'title' => 'Demo (End User 2) - Closed (pending) ticket',
            'description' => 'Demo work item pending close; End User 2 can close it.',
            'priority' => 'Medium',
            'category_id' => null,
            'status' => 'Closed (pending)',
            'source' => WorkItemSource::Portal,
            'opened_at' => now(),
            'resolved_at' => now(),
            'closed_at' => null,
        ]);

        // Agent: working ticket to exercise agent transitions.
        setPermissionsTeamId($tenant->getKey());

        $agentWorkItem = $workflow->createWorkItem([
            'title' => 'Demo (Agent) - Working ticket',
            'description' => 'Demo work item for agent workflow transitions.',
            'priority' => 'Medium',
            'type' => WorkItemType::ServiceRequest,
        ], $agent);

        $workflow->transition($agentWorkItem, 'Assigned', $agent);
        $workflow->transition($agentWorkItem, 'Working', $agent);

        setPermissionsTeamId(null);
    }
}
