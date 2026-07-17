<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Models\WorkItemStatus;
use App\Services\WorkItemAutomationService;
use App\Services\WorkItemWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkItemStatusAndAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_observer_seeds_default_statuses_and_automation_rules(): void
    {
        $tenant = Tenant::factory()->create();

        $names = WorkItemStatus::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->pluck('name')
            ->all();

        $this->assertSame([
            'New',
            'Assigned',
            'Working',
            'Waiting for customer',
            'Replied',
            'Closed (pending)',
            'Closed',
        ], $names);

        $this->assertTrue(
            WorkItemStatus::query()
                ->where('tenant_id', $tenant->id)
                ->where('name', 'Waiting for customer')
                ->where('pauses_sla', true)
                ->exists()
        );

        $this->assertDatabaseHas('automation_rules', [
            'tenant_id' => $tenant->id,
            'name' => 'Customer reply → Replied',
            'is_system' => true,
            'is_enabled' => true,
        ]);

        $this->assertDatabaseHas('automation_rules', [
            'tenant_id' => $tenant->id,
            'name' => 'Customer reply on Closed → new ticket',
            'is_system' => true,
            'is_enabled' => true,
        ]);
    }

    public function test_customer_reply_sets_status_to_replied(): void
    {
        $tenant = Tenant::factory()->create();

        $endUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
        ]);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $tenant->fresh()->generalQueue()?->id,
            'title' => 'Need help',
            'description' => 'Something broke',
            'priority' => 'normal',
            'status' => 'Waiting for customer',
            'reported_by_user_id' => $endUser->id,
            'opened_at' => now(),
        ]);

        $this->actingAs($endUser);

        app(WorkItemWorkflowService::class)->addMessage($workItem, 'Here is more info.', $endUser);

        $this->assertSame('Replied', $workItem->fresh()->status);
    }

    public function test_customer_reply_on_closed_creates_new_ticket_and_moves_message(): void
    {
        $tenant = Tenant::factory()->create();

        $endUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
        ]);

        $closed = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $tenant->fresh()->generalQueue()?->id,
            'title' => 'Old ticket',
            'description' => 'Already done',
            'priority' => 'normal',
            'status' => 'Closed',
            'reported_by_user_id' => $endUser->id,
            'opened_at' => now()->subDay(),
            'resolved_at' => now()->subDay(),
            'closed_at' => now()->subDay(),
        ]);

        $message = WorkItemMessage::query()->create([
            'tenant_id' => $tenant->id,
            'work_item_id' => $closed->id,
            'author_user_id' => $endUser->id,
            'is_internal' => false,
            'body' => 'This is a new problem.',
        ]);

        $target = app(WorkItemAutomationService::class)->handleCustomerReplied($closed, $message);

        $this->assertNotSame($closed->id, $target->id);
        $this->assertSame('New', $target->status);
        $this->assertSame('Closed', $closed->fresh()->status);
        $this->assertSame($target->id, $message->fresh()->work_item_id);
        $this->assertSame(2, WorkItem::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_system_automation_rules_are_seeded_with_actions(): void
    {
        $tenant = Tenant::factory()->create();

        $rule = AutomationRule::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', 'Customer reply → Replied')
            ->with('actions')
            ->firstOrFail();

        $this->assertCount(1, $rule->actions);
        $this->assertSame('set_status', $rule->actions->first()->action_type);
        $this->assertSame('Replied', $rule->actions->first()->action_config_json['status'] ?? null);
    }
}
