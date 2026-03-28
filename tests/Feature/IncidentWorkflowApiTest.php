<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class IncidentWorkflowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_create_incident_and_move_through_workflow(): void
    {
        $tenant = Tenant::factory()->create();

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $this->actingAs($agent);

        $createResponse = $this->postJson('/api/v1/incidents', [
            'title' => 'Printer not working',
            'description' => 'The printer is not responding.',
            'priority' => 'High',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.status', 'New');

        $incidentId = $createResponse->json('data.id');

        $this->patchJson("/api/v1/incidents/{$incidentId}/status", [
            'to_status' => 'Assigned',
        ])->assertOk();

        $incident = Incident::query()->findOrFail($incidentId);
        $this->assertSame('Assigned', $incident->status);

        $this->patchJson("/api/v1/incidents/{$incidentId}/status", [
            'to_status' => 'Closed',
        ])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_transition_requires_manage_incidents_permission(): void
    {
        $tenant = Tenant::factory()->create();

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $viewer = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        $this->actingAs($agent);

        $incident = Incident::query()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Server down',
            'description' => 'Core app server is down.',
            'priority' => 'Critical',
            'status' => 'In Progress',
            'opened_at' => now(),
        ]);

        $this->actingAs($viewer);

        $this->patchJson("/api/v1/incidents/{$incident->id}/status", [
            'to_status' => 'Resolved',
        ])->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_end_user_can_create_and_only_see_own_incidents(): void
    {
        $tenant = Tenant::factory()->create();

        $endUser1 = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
        ]);

        $endUser2 = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
        ]);

        $this->actingAs($endUser1);

        $this->postJson('/api/v1/incidents', [
            'title' => 'Laptop broken',
            'description' => 'Keyboard not working.',
            'priority' => 'High',
        ])->assertCreated();

        $incidentOther = Incident::query()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Other incident',
            'description' => 'Not visible to endUser1.',
            'priority' => 'Low',
            'status' => 'New',
            'reported_by_user_id' => $endUser2->id,
            'opened_at' => now(),
        ]);

        $indexResponse = $this->getJson('/api/v1/incidents');

        $indexResponse->assertOk();
        $this->assertSame(1, count($indexResponse->json('data')));
        $this->assertSame('Laptop broken', $indexResponse->json('data.0.title'));
        $this->assertNotSame($incidentOther->id, $indexResponse->json('data.0.id'));
    }

    public function test_end_user_can_close_own_resolved_incident(): void
    {
        $tenant = Tenant::factory()->create();

        $endUser1 = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
        ]);

        $endUser2 = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
        ]);

        $incident1 = Incident::query()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Printer resolved',
            'description' => 'Resolved by support.',
            'priority' => 'Medium',
            'status' => 'Resolved',
            'reported_by_user_id' => $endUser1->id,
            'opened_at' => now(),
            'resolved_at' => now(),
        ]);

        $incident2 = Incident::query()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Other resolved',
            'description' => 'Resolved but belongs to other user.',
            'priority' => 'Medium',
            'status' => 'Resolved',
            'reported_by_user_id' => $endUser2->id,
            'opened_at' => now(),
            'resolved_at' => now(),
        ]);

        $this->actingAs($endUser1);

        $this->patchJson("/api/v1/incidents/{$incident1->id}/status", [
            'to_status' => 'Closed',
        ])->assertOk();

        $this->patchJson("/api/v1/incidents/{$incident2->id}/status", [
            'to_status' => 'Closed',
        ])->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_end_user_can_reply_to_own_incident(): void
    {
        $tenant = Tenant::factory()->create();

        $endUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
        ]);

        $incident = Incident::query()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Need help',
            'description' => 'Something is broken.',
            'priority' => 'Low',
            'status' => 'New',
            'reported_by_user_id' => $endUser->id,
            'opened_at' => now(),
        ]);

        $this->actingAs($endUser);

        $this->postJson("/api/v1/incidents/{$incident->id}/messages", [
            'body' => 'Thanks, I will restart the service.',
        ])->assertCreated();

        $this->assertDatabaseHas('incident_messages', [
            'tenant_id' => $tenant->id,
            'incident_id' => $incident->id,
            'author_user_id' => $endUser->id,
        ]);
    }
}
