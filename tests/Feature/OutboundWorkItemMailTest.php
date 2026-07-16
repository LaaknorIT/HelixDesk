<?php

namespace Tests\Feature;

use App\Enums\MailOutboundProtocol;
use App\Enums\WorkItemSource;
use App\Jobs\SendWorkItemReplyMailJob;
use App\Models\MailAccount;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Services\Mail\OutboundWorkItemMailService;
use App\Services\WorkItemWorkflowService;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OutboundWorkItemMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_reply_dispatches_outbound_mail_job(): void
    {
        Bus::fake([SendWorkItemReplyMailJob::class]);

        $tenant = Tenant::factory()->create();
        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $requester = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
            'email' => 'customer@example.com',
        ]);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $tenant->generalQueue()?->id,
            'reported_by_user_id' => $requester->id,
            'title' => 'Help',
            'priority' => 'normal',
            'status' => 'New',
            'source' => WorkItemSource::Portal,
            'opened_at' => now(),
        ]);

        app(WorkItemWorkflowService::class)->addMessage($workItem, 'We are looking into it.', $agent);

        Bus::assertDispatched(SendWorkItemReplyMailJob::class);
    }

    public function test_internal_note_does_not_dispatch_outbound_mail_job(): void
    {
        Bus::fake([SendWorkItemReplyMailJob::class]);

        $tenant = Tenant::factory()->create();
        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $tenant->generalQueue()?->id,
            'reported_by_user_id' => User::factory()->create([
                'tenant_id' => $tenant->id,
                'type' => 'end_user',
            ])->id,
            'title' => 'Help',
            'priority' => 'normal',
            'status' => 'New',
            'opened_at' => now(),
        ]);

        app(WorkItemWorkflowService::class)->addInternalNote($workItem, 'Private note', $agent);

        Bus::assertNotDispatched(SendWorkItemReplyMailJob::class);
    }

    public function test_outbound_uses_incident_mail_account_when_present(): void
    {
        $tenant = Tenant::factory()->create();
        $queue = $tenant->generalQueue();
        $this->assertNotNull($queue);

        $inboundAccount = MailAccount::factory()->create([
            'tenant_id' => $tenant->id,
            'email_address' => 'support@example.com',
            'outbound_protocol' => MailOutboundProtocol::Smtp,
        ]);

        $defaultAccount = MailAccount::factory()->create([
            'tenant_id' => $tenant->id,
            'email_address' => 'default@example.com',
            'outbound_protocol' => MailOutboundProtocol::Smtp,
        ]);

        $queue->update(['default_mail_account_id' => $defaultAccount->id]);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $queue->id,
            'reported_by_user_id' => User::factory()->create([
                'tenant_id' => $tenant->id,
                'type' => 'end_user',
                'email' => 'customer@example.com',
            ])->id,
            'title' => 'Email ticket',
            'priority' => 'normal',
            'status' => 'New',
            'source' => WorkItemSource::Email,
            'mail_account_id' => $inboundAccount->id,
            'inbound_message_id' => '<orig@example.com>',
            'opened_at' => now(),
        ]);

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
            'name' => 'Agent Smith',
        ]);

        $message = WorkItemMessage::query()->create([
            'tenant_id' => $tenant->id,
            'work_item_id' => $workItem->id,
            'author_user_id' => $agent->id,
            'body' => 'Reply body',
            'is_internal' => false,
        ]);

        $service = $this->partialMock(OutboundWorkItemMailService::class, function ($mock): void {
            $mock->shouldAllowMockingProtectedMethods();
        });

        $resolved = (new \ReflectionClass(OutboundWorkItemMailService::class))
            ->getMethod('resolveOutboundAccount');
        $resolved->setAccessible(true);

        $account = $resolved->invoke($service, $workItem->fresh(['mailAccount', 'queue.defaultMailAccount']));

        $this->assertNotNull($account);
        $this->assertSame($inboundAccount->id, $account->id);
    }

    public function test_outbound_falls_back_to_queue_default_mail_account(): void
    {
        $tenant = Tenant::factory()->create();
        $queue = $tenant->generalQueue();
        $this->assertNotNull($queue);

        $defaultAccount = MailAccount::factory()->create([
            'tenant_id' => $tenant->id,
            'email_address' => 'default@example.com',
        ]);
        $queue->update(['default_mail_account_id' => $defaultAccount->id]);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $queue->id,
            'reported_by_user_id' => User::factory()->create([
                'tenant_id' => $tenant->id,
                'type' => 'end_user',
            ])->id,
            'title' => 'Portal ticket',
            'priority' => 'normal',
            'status' => 'New',
            'source' => WorkItemSource::Portal,
            'opened_at' => now(),
        ]);

        $service = app(OutboundWorkItemMailService::class);
        $resolved = (new \ReflectionClass(OutboundWorkItemMailService::class))
            ->getMethod('resolveOutboundAccount');
        $resolved->setAccessible(true);

        $account = $resolved->invoke($service, $workItem->fresh(['mailAccount', 'queue.defaultMailAccount']));

        $this->assertNotNull($account);
        $this->assertSame($defaultAccount->id, $account->id);
    }

    public function test_graph_outbound_uses_extended_properties_instead_of_internet_message_headers(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'token']),
            'https://graph.microsoft.com/v1.0/*' => Http::response([], 202),
        ]);

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'id' => 'entra-graph-out',
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

        $account = MailAccount::factory()->microsoftGraph()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'email_address' => 'shared@example.com',
            'outbound_protocol' => MailOutboundProtocol::MicrosoftGraph,
            'integration_type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            'integration_id' => (string) $integration->getKey(),
            'connection_settings' => ['mailbox_upn' => 'shared@example.com'],
        ]);

        $inboundMessageId = '<inbound-thread@example.com>';
        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
            'name' => 'Agent Smith',
        ]);
        $requester = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
            'email' => 'customer@example.com',
        ]);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $tenant->generalQueue()?->id,
            'reported_by_user_id' => $requester->id,
            'title' => 'Need help',
            'priority' => 'normal',
            'status' => 'New',
            'source' => WorkItemSource::Email,
            'mail_account_id' => $account->id,
            'inbound_message_id' => $inboundMessageId,
            'opened_at' => now(),
        ]);

        $message = WorkItemMessage::query()->create([
            'tenant_id' => $tenant->id,
            'work_item_id' => $workItem->id,
            'author_user_id' => $agent->id,
            'body' => 'We are looking into it.',
            'is_internal' => false,
        ]);

        app(OutboundWorkItemMailService::class)->sendAgentReply($message->fresh(['author', 'workItem']));

        $message->refresh();
        $this->assertNotNull($message->email_sent_at);
        $this->assertSame($account->id, $message->mail_account_id);
        $this->assertSame($inboundMessageId, $message->in_reply_to);

        Http::assertSent(function ($request) use ($inboundMessageId, $workItem): bool {
            if (! str_contains($request->url(), '/sendMail')) {
                return false;
            }

            $payload = $request->data();
            $messagePayload = $payload['message'] ?? [];

            if (array_key_exists('internetMessageHeaders', $messagePayload)) {
                return false;
            }

            if (array_key_exists('replyTo', $messagePayload)) {
                return false;
            }

            if (! str_contains((string) ($messagePayload['subject'] ?? ''), '[#'.$workItem->public_id.']')) {
                return false;
            }

            $extended = $messagePayload['singleValueExtendedProperties'] ?? [];
            $byId = collect($extended)->keyBy('id');

            return ($byId->get('String 0x1042')['value'] ?? null) === $inboundMessageId
                && is_string($byId->get('String 0x1039')['value'] ?? null);
        });
    }

    public function test_missing_outbound_account_is_logged_on_mail_channel(): void
    {
        $logPath = storage_path('logs/testing-outbound-mail-'.uniqid('', true).'.log');
        config([
            'logging.channels.mail' => [
                'driver' => 'single',
                'path' => $logPath,
                'level' => 'debug',
            ],
        ]);
        Log::forgetChannel('mail');

        $tenant = Tenant::factory()->create();
        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);
        $requester = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
            'email' => 'customer@example.com',
        ]);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $tenant->generalQueue()?->id,
            'reported_by_user_id' => $requester->id,
            'title' => 'Portal ticket',
            'priority' => 'normal',
            'status' => 'New',
            'source' => WorkItemSource::Portal,
            'opened_at' => now(),
        ]);

        $message = WorkItemMessage::query()->create([
            'tenant_id' => $tenant->id,
            'work_item_id' => $workItem->id,
            'author_user_id' => $agent->id,
            'body' => 'Reply without mail account',
            'is_internal' => false,
        ]);

        try {
            app(OutboundWorkItemMailService::class)->sendAgentReply($message->fresh(['author', 'workItem']));

            $this->assertNull($message->fresh()->email_sent_at);
            $this->assertFileExists($logPath);
            $this->assertStringContainsString(
                'No outbound mail account configured for work item reply.',
                (string) file_get_contents($logPath),
            );
        } finally {
            if (is_file($logPath)) {
                unlink($logPath);
            }
        }
    }
}
