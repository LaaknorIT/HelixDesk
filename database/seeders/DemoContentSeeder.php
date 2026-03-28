<?php

namespace Database\Seeders;

use App\Models\Incident;
use App\Models\IncidentMessage;
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
                'billing_mode' => 'Stripe',
                'region' => 'eu',
            ],
        );

        // Ensure tenant roles, permissions, and the default incident workflow are present
        // and up-to-date (idempotent).
        app(TenantObserver::class)->created($tenant);

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

        // Create a tenant agent user and assign the tenant-scoped Agent role.
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

        setPermissionsTeamId($tenant->getKey());
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        // Two end users to demonstrate "only see your own incidents".
        $endUser1 = User::query()->updateOrCreate(
            ['email' => 'demo-enduser1@helixdesk.local'],
            [
                'name' => 'Demo End User 1',
                'password' => $password,
                'is_admin' => false,
                'tenant_id' => $tenant->getKey(),
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
            'organization_id' => null,
            'department_id' => null,
            'reported_by_user_id' => $endUser2->getKey(),
            'assigned_agent_id' => null,
            'queue_id' => null,
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
