<?php

namespace Tests\Feature;

use App\Enums\MailOutboundProtocol;
use App\Enums\WorkItemSource;
use App\Filament\Agent\Resources\WorkItems\Pages\ViewWorkItem;
use App\Models\MailAccount;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Services\WorkItemWorkflowService;
use App\Support\OrganizationIntegrationSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class ViewWorkItemSyncQueueReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_queue_graph_send_failure_does_not_break_reply_ui(): void
    {
        config(['queue.default' => 'sync']);

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 't']),
            'https://graph.microsoft.com/v1.0/*' => Http::response([
                'error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied.'],
            ], 403),
        ]);

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'id' => 'entra-out',
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            'config' => [
                'enabled' => true,
                'azure_tenant_id' => 'tenant-guid',
                'application_client_id' => 'client-guid',
            ],
            'credentials' => ['client_secret' => 'secret'],
        ]);

        $agent = User::factory()->create(['tenant_id' => $tenant->id, 'type' => 'agent']);
        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Tenant Admin');
        setPermissionsTeamId(null);

        $account = MailAccount::factory()->microsoftGraph()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'email_address' => 'shared-'.uniqid().'@example.com',
            'outbound_protocol' => MailOutboundProtocol::MicrosoftGraph,
            'integration_type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            'integration_id' => (string) $integration->getKey(),
            'connection_settings' => ['mailbox_upn' => 'shared@example.com'],
        ]);

        $requester = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
            'email' => 'req-'.uniqid().'@example.com',
        ]);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $tenant->generalQueue()?->id,
            'reported_by_user_id' => $requester->id,
            'title' => 'Email ticket',
            'priority' => 'normal',
            'status' => 'New',
            'source' => WorkItemSource::Email,
            'mail_account_id' => $account->id,
            'inbound_message_id' => '<in@example.com>',
            'opened_at' => now(),
        ]);

        $this->actingAs($agent);
        Filament::setCurrentPanel('agent');
        Filament::setTenant($tenant, isQuiet: true);
        setPermissionsTeamId($tenant->id);

        try {
            Livewire::test(ViewWorkItem::class, [
                'tenant' => $tenant,
                'record' => $workItem->getKey(),
            ])
                ->fillForm([
                    'body' => 'Reply that sends via Graph',
                    'is_internal' => false,
                ], 'workZoneForm')
                ->call('sendReply')
                ->assertHasNoErrors()
                ->assertNotified('Reply saved');
        } finally {
            setPermissionsTeamId(null);
        }

        $this->assertDatabaseHas('work_item_messages', [
            'work_item_id' => $workItem->id,
            'author_user_id' => $agent->id,
            'body' => 'Reply that sends via Graph',
            'is_internal' => false,
        ]);

        $this->assertNull(
            WorkItemMessage::query()
                ->where('work_item_id', $workItem->id)
                ->where('body', 'Reply that sends via Graph')
                ->value('email_sent_at')
        );
    }

    public function test_permission_denied_shows_notification_instead_of_page_error(): void
    {
        $tenant = Tenant::factory()->create();
        $agent = User::factory()->create(['tenant_id' => $tenant->id, 'type' => 'agent']);
        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Tenant Admin');
        setPermissionsTeamId(null);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $tenant->generalQueue()?->id,
            'reported_by_user_id' => User::factory()->create([
                'tenant_id' => $tenant->id,
                'type' => 'end_user',
                'email' => 'req-'.uniqid().'@example.com',
            ])->id,
            'title' => 'Ticket',
            'priority' => 'normal',
            'status' => 'New',
            'opened_at' => now(),
        ]);

        $workflow = Mockery::mock(WorkItemWorkflowService::class)->makePartial();
        $workflow->shouldReceive('addInternalNote')
            ->once()
            ->andThrow(new AccessDeniedHttpException('Forbidden.'));
        $this->app->instance(WorkItemWorkflowService::class, $workflow);

        $this->actingAs($agent);
        Filament::setCurrentPanel('agent');
        Filament::setTenant($tenant, isQuiet: true);
        setPermissionsTeamId($tenant->id);

        try {
            Livewire::test(ViewWorkItem::class, [
                'tenant' => $tenant,
                'record' => $workItem->getKey(),
            ])
                ->fillForm([
                    'body' => 'Should be denied',
                    'is_internal' => true,
                ], 'workZoneForm')
                ->call('sendReply')
                ->assertHasNoErrors()
                ->assertNotified('Not allowed to add an internal note');
        } finally {
            setPermissionsTeamId(null);
        }
    }
}
