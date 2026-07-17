<?php

namespace Tests\Feature;

use App\Enums\SlaCalendarType;
use App\Enums\SlaTargetType;
use App\Enums\WorkItemType;
use App\Models\Organization;
use App\Models\SlaPolicy;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemCategory;
use App\Services\SlaPolicyResolver;
use App\Services\SlaTimerService;
use App\Services\WorkItemWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SlaPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_observer_seeds_default_sla_policies(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertDatabaseHas('sla_policies', [
            'tenant_id' => $tenant->id,
            'name' => 'Normal',
            'target_type' => SlaTargetType::FirstResponse->value,
            'calendar_type' => SlaCalendarType::WorkingHours->value,
        ]);

        $this->assertDatabaseHas('sla_policies', [
            'tenant_id' => $tenant->id,
            'name' => 'Gold',
            'target_type' => SlaTargetType::Resolution->value,
            'calendar_type' => SlaCalendarType::Continuous->value,
        ]);
    }

    public function test_resolver_prefers_more_specific_policy(): void
    {
        $tenant = Tenant::factory()->create();
        $org = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $category = WorkItemCategory::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Email',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        SlaPolicy::query()->where('tenant_id', $tenant->id)->delete();

        SlaPolicy::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Generic normal',
            'is_active' => true,
            'target_type' => SlaTargetType::FirstResponse->value,
            'first_response_minutes' => 480,
            'calendar_type' => SlaCalendarType::WorkingHours->value,
            'applies_to_priority' => 'normal',
            'match_priority' => 0,
        ]);

        $specific = SlaPolicy::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Org + category',
            'is_active' => true,
            'target_type' => SlaTargetType::Both->value,
            'first_response_minutes' => 60,
            'resolution_minutes' => 240,
            'calendar_type' => SlaCalendarType::Continuous->value,
            'applies_to_priority' => 'normal',
            'applies_to_organization_id' => $org->id,
            'applies_to_category_id' => $category->id,
            'match_priority' => 0,
        ]);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $org->id,
            'category_id' => $category->id,
            'title' => 'SLA match',
            'priority' => 'normal',
            'status' => 'New',
            'type' => WorkItemType::Incident,
            'opened_at' => now(),
        ]);

        $resolved = app(SlaPolicyResolver::class)->resolveForWorkItem($workItem);

        $this->assertNotNull($resolved);
        $this->assertSame($specific->id, $resolved->id);
    }

    public function test_create_work_item_attaches_sla_and_starts_timers(): void
    {
        $tenant = Tenant::factory()->create();

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $workItem = app(WorkItemWorkflowService::class)->createWorkItem([
            'title' => 'With SLA',
            'description' => 'Timer should start',
            'priority' => 'normal',
        ], $agent);

        $this->assertNotNull($workItem->sla_policy_id);
        $this->assertNotNull($workItem->first_response_due_at);
    }

    public function test_sla_pauses_in_waiting_for_customer_and_resumes(): void
    {
        $tenant = Tenant::factory()->create();

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $workItem = app(WorkItemWorkflowService::class)->createWorkItem([
            'title' => 'Pause SLA',
            'priority' => 'urgent',
        ], $agent);

        // Gold policy matches urgent → resolution timer
        $this->assertNotNull($workItem->resolution_due_at);
        $dueBefore = $workItem->resolution_due_at->copy();

        $workItem = app(WorkItemWorkflowService::class)->transition($workItem, 'Assigned', $agent);
        $workItem = app(WorkItemWorkflowService::class)->transition($workItem, 'Working', $agent);

        Carbon::setTestNow(now()->startOfMinute());
        $workItem = app(WorkItemWorkflowService::class)->transition($workItem->fresh(), 'Waiting for customer', $agent);

        $this->assertNotNull($workItem->fresh()->sla_paused_at);

        Carbon::setTestNow(now()->addHour());

        $workItem = app(WorkItemWorkflowService::class)->transition($workItem->fresh(), 'Working', $agent);

        $fresh = $workItem->fresh();
        $this->assertNull($fresh->sla_paused_at);
        $this->assertGreaterThanOrEqual(3600, $fresh->sla_paused_seconds);
        $this->assertTrue($fresh->resolution_due_at->gte($dueBefore->copy()->addMinutes(60)));

        Carbon::setTestNow();
    }

    public function test_evaluate_breaches_marks_overdue_tickets(): void
    {
        $tenant = Tenant::factory()->create();

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Breached',
            'priority' => 'normal',
            'status' => 'New',
            'opened_at' => now()->subDays(2),
            'first_response_due_at' => now()->subHour(),
        ]);

        $count = app(SlaTimerService::class)->evaluateBreaches();

        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertNotNull($workItem->fresh()->response_breached_at);
    }
}
