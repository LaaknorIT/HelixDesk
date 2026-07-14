<?php

namespace Tests\Feature;

use App\Enums\WorkItemType;
use App\Filament\Agent\Resources\WorkItems\Pages\ViewWorkItem;
use App\Filament\Agent\Resources\WorkItems\WorkItemResource;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Services\WorkItemWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ViewWorkItemPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_view_redesigned_work_item_page(): void
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

        $workItem = app(WorkItemWorkflowService::class)->createWorkItem([
            'title' => 'Printer not working',
            'description' => 'Paper jam in tray 2.',
            'priority' => 'high',
            'type' => WorkItemType::Incident,
        ], $agent);

        $url = route('filament.agent.resources.work-items.view', [
            'tenant' => $tenant,
            'record' => $workItem,
        ]);

        $this->get($url)
            ->assertOk()
            ->assertSee('Printer not working')
            ->assertSee('Conversation')
            ->assertSee('Quick actions')
            ->assertSee('Compose response')
            ->assertSee('Metadata');
    }

    public function test_view_work_item_page_hides_bottom_relation_managers(): void
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
            'title' => 'VPN drops',
            'type' => WorkItemType::Incident,
        ], $agent);

        $this->actingAs($agent);

        Filament::setCurrentPanel('agent');
        Filament::setTenant($tenant, isQuiet: true);

        Livewire::test(ViewWorkItem::class, [
            'tenant' => $tenant,
            'record' => $workItem->getKey(),
        ])
            ->assertOk()
            ->assertSet('activeMetaTab', 'details');
    }

    public function test_inline_reply_adds_message_via_work_zone_form(): void
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
            'title' => 'Email not syncing',
            'type' => WorkItemType::Incident,
        ], $agent);

        $this->actingAs($agent);

        Filament::setCurrentPanel('agent');
        Filament::setTenant($tenant, isQuiet: true);

        Livewire::test(ViewWorkItem::class, [
            'tenant' => $tenant,
            'record' => $workItem->getKey(),
        ])
            ->fillForm([
                'body' => 'We are investigating this now.',
            ], 'workZoneForm')
            ->call('sendReply')
            ->assertNotified();

        $this->assertDatabaseHas('work_item_messages', [
            'work_item_id' => $workItem->id,
            'author_user_id' => $agent->id,
            'body' => 'We are investigating this now.',
            'is_internal' => false,
        ]);
    }

    public function test_build_activity_timeline_includes_messages_and_lifecycle_events(): void
    {
        $tenant = Tenant::factory()->create();
        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);
        $requester = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
        ]);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'reported_by_user_id' => $requester->id,
            'queue_id' => $tenant->fresh()->generalQueue()?->id,
            'type' => WorkItemType::Incident,
            'title' => 'Test ticket',
            'status' => 'New',
            'priority' => 'normal',
            'opened_at' => now()->subHour(),
            'resolved_at' => now(),
            'closed_at' => null,
        ]);

        WorkItemMessage::query()->create([
            'tenant_id' => $tenant->id,
            'work_item_id' => $workItem->id,
            'author_user_id' => $agent->id,
            'is_internal' => true,
            'body' => 'Checking logs.',
        ]);

        $timeline = WorkItemResource::buildActivityTimeline($workItem);

        $events = collect($timeline)->pluck('event')->all();

        $this->assertContains('Ticket created', $events);
        $this->assertContains('Added internal note', $events);
        $this->assertContains('Ticket resolved', $events);
    }
}
