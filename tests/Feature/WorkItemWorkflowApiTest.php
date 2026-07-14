<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\RolePermissionMatrixSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class WorkItemWorkflowApiTest extends TestCase
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

        $createResponse = $this->postJson('/api/v1/work-items', [
            'title' => 'Printer not working',
            'description' => 'The printer is not responding.',
            'priority' => 'High',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.status', 'New');

        $workItemId = $createResponse->json('data.id');
        $generalQueueId = $tenant->fresh()->generalQueue()?->id;
        $this->assertNotNull($generalQueueId);
        $this->assertSame($generalQueueId, $createResponse->json('data.queue_id'));

        $this->patchJson("/api/v1/work-items/{$workItemId}/status", [
            'to_status' => 'Assigned',
        ])->assertOk();

        $workItem = WorkItem::query()->findOrFail($workItemId);
        $this->assertSame('Assigned', $workItem->status);

        $this->patchJson("/api/v1/work-items/{$workItemId}/status", [
            'to_status' => 'Closed',
        ])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_transition_requires_incident_update_permission(): void
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

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $tenant->fresh()->generalQueue()?->id,
            'title' => 'Server down',
            'description' => 'Core app server is down.',
            'priority' => 'Critical',
            'status' => 'In Progress',
            'opened_at' => now(),
        ]);

        $this->actingAs($viewer);

        $this->patchJson("/api/v1/work-items/{$workItem->id}/status", [
            'to_status' => 'Resolved',
        ])->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_agent_cannot_read_work_items_outside_assigned_queues(): void
    {
        $tenant = Tenant::factory()->create();

        $supportQueue = $tenant->queues()->create([
            'name' => 'Support',
            'description' => null,
        ]);

        $salesQueue = $tenant->queues()->create([
            'name' => 'Sales',
            'description' => null,
        ]);

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        $agentRole = TenantRole::query()
            ->where('team_id', $tenant->id)
            ->where('name', 'Agent')
            ->firstOrFail();
        $agentRole->queues()->sync([
            $supportQueue->getKey() => [
                'work_item_permissions' => json_encode(RolePermissionMatrixSync::normalizeWorkItemActions([
                    'read' => true,
                    'create' => true,
                    'update' => true,
                ])),
            ],
        ]);
        setPermissionsTeamId(null);

        $supportIncident = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $supportQueue->getKey(),
            'title' => 'Support queue incident',
            'description' => 'Visible to support agent.',
            'priority' => 'High',
            'status' => 'New',
            'opened_at' => now(),
        ]);

        $salesIncident = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $salesQueue->getKey(),
            'title' => 'Sales queue incident',
            'description' => 'Should be hidden from support agent.',
            'priority' => 'High',
            'status' => 'New',
            'opened_at' => now(),
        ]);

        $this->actingAs($agent);

        $indexResponse = $this->getJson('/api/v1/work-items');
        $indexResponse->assertOk();
        $this->assertCount(1, $indexResponse->json('data'));
        $this->assertSame($supportIncident->id, $indexResponse->json('data.0.id'));

        $this->getJson("/api/v1/work-items/{$supportIncident->id}")
            ->assertOk();

        $this->getJson("/api/v1/work-items/{$salesIncident->id}")
            ->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_agent_gets_queue_access_via_role_queues(): void
    {
        $tenant = Tenant::factory()->create();

        $supportQueue = $tenant->queues()->create([
            'name' => 'Support',
            'description' => null,
        ]);

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        $agentRole = TenantRole::query()
            ->where('team_id', $tenant->id)
            ->where('name', 'Agent')
            ->firstOrFail();
        $agentRole->queues()->sync([
            $supportQueue->getKey() => [
                'work_item_permissions' => json_encode(RolePermissionMatrixSync::normalizeWorkItemActions([
                    'read' => true,
                    'create' => true,
                    'update' => true,
                ])),
            ],
        ]);
        setPermissionsTeamId(null);

        $supportIncident = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $supportQueue->getKey(),
            'title' => 'Via group',
            'description' => 'Queue from group only.',
            'priority' => 'High',
            'status' => 'New',
            'opened_at' => now(),
        ]);

        $this->actingAs($agent);

        $this->getJson("/api/v1/work-items/{$supportIncident->id}")
            ->assertOk();
    }

    public function test_agent_without_incident_read_on_queue_cannot_access_incident_workspace(): void
    {
        $tenant = Tenant::factory()->create();

        $queue = $tenant->queues()->create([
            'name' => 'Locked read',
            'description' => null,
        ]);

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        $agentRole = TenantRole::query()
            ->where('team_id', $tenant->id)
            ->where('name', 'Agent')
            ->firstOrFail();
        $agentRole->queues()->sync([
            $queue->getKey() => [
                'work_item_permissions' => json_encode(RolePermissionMatrixSync::normalizeWorkItemActions([
                    'read' => false,
                    'create' => true,
                    'update' => true,
                ])),
            ],
        ]);
        setPermissionsTeamId(null);

        WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $queue->getKey(),
            'title' => 'No read',
            'description' => 'Agent lacks read on this queue.',
            'priority' => 'High',
            'status' => 'New',
            'opened_at' => now(),
        ]);

        $this->actingAs($agent);

        $this->getJson('/api/v1/work-items')->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_end_user_can_create_and_only_see_own_work_items(): void
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

        $this->postJson('/api/v1/work-items', [
            'title' => 'Laptop broken',
            'description' => 'Keyboard not working.',
            'priority' => 'High',
        ])->assertCreated();

        $workItemOther = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Other incident',
            'description' => 'Not visible to endUser1.',
            'priority' => 'Low',
            'status' => 'New',
            'reported_by_user_id' => $endUser2->id,
            'opened_at' => now(),
        ]);

        $indexResponse = $this->getJson('/api/v1/work-items');

        $indexResponse->assertOk();
        $this->assertSame(1, count($indexResponse->json('data')));
        $this->assertSame('Laptop broken', $indexResponse->json('data.0.title'));
        $this->assertNotSame($workItemOther->id, $indexResponse->json('data.0.id'));
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

        $workItem1 = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Printer resolved',
            'description' => 'Resolved by support.',
            'priority' => 'Medium',
            'status' => 'Resolved',
            'reported_by_user_id' => $endUser1->id,
            'opened_at' => now(),
            'resolved_at' => now(),
        ]);

        $workItem2 = WorkItem::query()->create([
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

        $this->patchJson("/api/v1/work-items/{$workItem1->id}/status", [
            'to_status' => 'Closed',
        ])->assertOk();

        $this->patchJson("/api/v1/work-items/{$workItem2->id}/status", [
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

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Need help',
            'description' => 'Something is broken.',
            'priority' => 'Low',
            'status' => 'New',
            'reported_by_user_id' => $endUser->id,
            'opened_at' => now(),
        ]);

        $this->actingAs($endUser);

        $this->postJson("/api/v1/work-items/{$workItem->id}/messages", [
            'body' => 'Thanks, I will restart the service.',
        ])->assertCreated();

        $this->assertDatabaseHas('work_item_messages', [
            'tenant_id' => $tenant->id,
            'work_item_id' => $workItem->id,
            'author_user_id' => $endUser->id,
        ]);
    }
}
