<?php

namespace Tests\Feature;

use App\Filament\Agent\Resources\WorkItems\Pages\ViewWorkItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemStatus;
use App\Services\WorkItemWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkItemStatusCatalogOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_status_options_include_newly_created_catalog_statuses(): void
    {
        $tenant = Tenant::factory()->create();

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        WorkItemStatus::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'On hold',
            'sort_order' => 50,
            'is_system' => false,
            'is_default_open' => false,
            'pauses_sla' => true,
            'sets_resolved_at' => false,
            'sets_closed_at' => false,
        ]);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $tenant->fresh()->generalQueue()?->id,
            'title' => 'Needs custom status',
            'priority' => 'normal',
            'status' => 'New',
            'opened_at' => now(),
            'reported_by_user_id' => $agent->id,
        ]);

        $options = app(WorkItemWorkflowService::class)->availableStatusOptions($workItem, $agent);

        $this->assertArrayHasKey('On hold', $options);
        $this->assertArrayHasKey('Working', $options);
        $this->assertArrayNotHasKey('New', $options);

        $updated = app(WorkItemWorkflowService::class)->transition($workItem, 'On hold', $agent);
        $this->assertSame('On hold', $updated->status);
    }

    public function test_ticket_view_status_select_lists_custom_status(): void
    {
        $tenant = Tenant::factory()->create();

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        WorkItemStatus::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Escalated',
            'sort_order' => 40,
            'is_system' => false,
        ]);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $tenant->fresh()->generalQueue()?->id,
            'title' => 'Show custom status',
            'priority' => 'normal',
            'status' => 'Assigned',
            'opened_at' => now(),
            'reported_by_user_id' => $agent->id,
        ]);

        $this->actingAs($agent);

        Filament::setCurrentPanel('agent');
        Filament::setTenant($tenant, isQuiet: true);

        Livewire::test(ViewWorkItem::class, [
            'tenant' => $tenant,
            'record' => $workItem->getKey(),
        ])
            ->assertOk()
            ->assertSee('Escalated');
    }
}
