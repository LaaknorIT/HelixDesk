<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Incident;
use App\Models\IncidentMessage;
use App\Models\Organization;
use App\Models\ServiceOffering;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Observers\TenantObserver;
use App\Services\IncidentWorkflowService;
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
                'parent_organization_id' => null,
                'external_reference' => null,
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

        // Tenant admin: full catalog including tenant.admin and dashboard.admin (Settings navigation).
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

        // Two end users to demonstrate "only see your own incidents".
        $endUser1 = User::query()->updateOrCreate(
            ['email' => 'demo-enduser1@helixdesk.local'],
            [
                'name' => 'Demo End User 1',
                'password' => $password,
                'is_admin' => false,
                'tenant_id' => $tenant->getKey(),
                'organization_id' => $organization->getKey(),
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
                'organization_id' => $organization->getKey(),
                'type' => 'end_user',
            ],
        );

        // Clear prior demo incidents/messages to keep the seeder idempotent.
        $demoIncidentIds = Incident::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('title', 'like', 'Demo%')
            ->pluck('id');

        if ($demoIncidentIds->isNotEmpty()) {
            IncidentMessage::query()
                ->where('tenant_id', $tenant->getKey())
                ->whereIn('incident_id', $demoIncidentIds)
                ->delete();

            Incident::query()
                ->where('tenant_id', $tenant->getKey())
                ->whereIn('id', $demoIncidentIds)
                ->delete();
        }

        $workflow = app(IncidentWorkflowService::class);

        // End-user 1: create an open incident + a reply.
        $endUser1Incident = $workflow->createIncident([
            'title' => 'Demo (End User 1) - Open incident',
            'description' => 'Demo incident created in local environment.',
            'priority' => 'High',
        ], $endUser1);

        $workflow->addMessage(
            $endUser1Incident,
            'Demo reply from End User 1.',
            $endUser1
        );

        // End-user 2: create a Resolved incident (end-user should be able to close it).
        Incident::query()->create([
            'tenant_id' => $tenant->getKey(),
            'organization_id' => $organization->getKey(),
            'department_id' => null,
            'reported_by_user_id' => $endUser2->getKey(),
            'assigned_agent_id' => null,
            'queue_id' => $generalQueue?->getKey(),
            'title' => 'Demo (End User 2) - Resolved incident',
            'description' => 'Demo incident already resolved; End User 2 can close it.',
            'priority' => 'Medium',
            'category_id' => null,
            'status' => 'Resolved',
            'opened_at' => now(),
            'resolved_at' => now(),
            'closed_at' => null,
        ]);

        // Agent: create an in-progress incident to exercise agent transitions.
        setPermissionsTeamId($tenant->getKey());

        $agentIncident = $workflow->createIncident([
            'title' => 'Demo (Agent) - In progress incident',
            'description' => 'Demo incident for agent workflow transitions.',
            'priority' => 'Medium',
        ], $agent);

        $workflow->transition($agentIncident, 'Assigned', $agent);
        $workflow->transition($agentIncident, 'In Progress', $agent);

        setPermissionsTeamId(null);
    }
}
