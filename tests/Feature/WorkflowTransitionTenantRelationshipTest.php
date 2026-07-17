<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkflowTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowTransitionTenantRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_transition_defines_tenant_ownership_relationship(): void
    {
        $tenant = Tenant::factory()->create();
        $transition = WorkflowTransition::query()
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $this->assertTrue($transition->isRelation('tenant'));
        $this->assertTrue($transition->tenant()->exists());
        $this->assertSame($tenant->id, $transition->tenant->id);
    }

    public function test_agent_can_open_ticket_with_workflow_transition_resource_registered(): void
    {
        $tenant = Tenant::factory()->create();

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $admin->assignRole('Tenant Admin');
        setPermissionsTeamId(null);

        $this->actingAs($admin);

        $this->assertTrue((new WorkflowTransition)->isRelation('tenant'));

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $tenant->fresh()->generalQueue()?->id,
            'title' => 'Open me',
            'priority' => 'normal',
            'status' => 'New',
            'opened_at' => now(),
            'reported_by_user_id' => $admin->id,
        ]);

        $this->get('/agent/'.$tenant->slug.'/work-items/'.$workItem->getKey())
            ->assertOk();
    }
}
