<?php

namespace Tests\Feature;

use App\Enums\WorkItemSource;
use App\Models\MailAccount;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Services\Mail\InboundMailIngestionService;
use App\Services\Mail\OutboundWorkItemMailService;
use App\Services\Mail\WorkItemMailReference;
use App\Services\Mail\WorkItemPublicIdGenerator;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WorkItemMailThreadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_item_gets_tenant_unique_public_id_on_create(): void
    {
        $tenant = Tenant::factory()->create();

        $first = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $tenant->generalQueue()?->id,
            'reported_by_user_id' => User::factory()->create(['tenant_id' => $tenant->id])->id,
            'title' => 'One',
            'priority' => 'normal',
            'status' => 'New',
            'opened_at' => now(),
        ]);

        $second = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $tenant->generalQueue()?->id,
            'reported_by_user_id' => User::factory()->create(['tenant_id' => $tenant->id])->id,
            'title' => 'Two',
            'priority' => 'normal',
            'status' => 'New',
            'opened_at' => now(),
        ]);

        $this->assertNotNull($first->public_id);
        $this->assertNotNull($second->public_id);
        $this->assertNotSame($first->public_id, $second->public_id);
        $this->assertSame(
            (int) config('helixdesk.mail.work_item_public_id_length', 5),
            strlen($first->public_id),
        );
        $this->assertNotSame((string) $first->id, $first->public_id);
    }

    public function test_inbound_reply_threads_via_subject_token(): void
    {
        $logPath = storage_path('logs/testing-thread-subject-'.uniqid('', true).'.log');
        config([
            'logging.channels.mail' => [
                'driver' => 'single',
                'path' => $logPath,
                'level' => 'debug',
            ],
        ]);
        Log::forgetChannel('mail');

        $tenant = Tenant::factory()->create();
        $queue = $tenant->generalQueue();
        $account = MailAccount::factory()->create(['tenant_id' => $tenant->id]);
        $queue?->mailAccounts()->attach($account->id);

        $requester = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
            'email' => 'customer@example.com',
        ]);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $queue?->id,
            'reported_by_user_id' => $requester->id,
            'title' => 'Testmail fra utsid1',
            'priority' => 'normal',
            'status' => 'New',
            'source' => WorkItemSource::Email,
            'mail_account_id' => $account->id,
            'inbound_message_id' => '<original@example.com>',
            'opened_at' => now(),
        ]);

        WorkItemMessage::query()->create([
            'tenant_id' => $tenant->id,
            'work_item_id' => $workItem->id,
            'author_user_id' => $requester->id,
            'body' => 'Initial',
            'external_message_id' => '<original@example.com>',
            'from_address' => 'customer@example.com',
        ]);

        $raw = implode("\r\n", [
            'Message-ID: <reply-no-headers@example.com>',
            'From: customer@example.com',
            'To: '.$account->email_address,
            'Subject: Re: Testmail fra utsid1 [#'.$workItem->public_id.']',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Still broken.',
            '',
        ]);

        try {
            app(InboundMailIngestionService::class)->ingest($account, $raw);

            $this->assertSame(1, WorkItem::query()->where('tenant_id', $tenant->id)->count());
            $this->assertSame(2, WorkItemMessage::query()->where('work_item_id', $workItem->id)->count());

            $log = (string) file_get_contents($logPath);
            $this->assertStringContainsString('"match_method":"subject_token"', $log);
            $this->assertStringContainsString('"subject_token":"'.$workItem->public_id.'"', $log);
        } finally {
            if (is_file($logPath)) {
                unlink($logPath);
            }
        }
    }

    public function test_inbound_reply_threads_via_plus_address(): void
    {
        $tenant = Tenant::factory()->create();
        $queue = $tenant->generalQueue();
        $account = MailAccount::factory()->create([
            'tenant_id' => $tenant->id,
            'email_address' => 'support@example.com',
            'supports_plus_addressing' => true,
        ]);
        $queue?->mailAccounts()->attach($account->id);

        $requester = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
            'email' => 'customer@example.com',
        ]);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $queue?->id,
            'reported_by_user_id' => $requester->id,
            'title' => 'Plus address ticket',
            'priority' => 'normal',
            'status' => 'New',
            'source' => WorkItemSource::Email,
            'mail_account_id' => $account->id,
            'inbound_message_id' => '<plus-original@example.com>',
            'opened_at' => now(),
        ]);

        $plusTo = WorkItemMailReference::plusAddress($account->email_address, $workItem->public_id);
        $this->assertNotNull($plusTo);

        $raw = implode("\r\n", [
            'Message-ID: <plus-reply@example.com>',
            'From: customer@example.com',
            'To: '.$plusTo,
            'Subject: Re: Plus address ticket',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Following up.',
            '',
        ]);

        app(InboundMailIngestionService::class)->ingest($account, $raw);

        $this->assertSame(1, WorkItem::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, WorkItemMessage::query()
            ->where('work_item_id', $workItem->id)
            ->where('external_message_id', '<plus-reply@example.com>')
            ->count());
    }

    public function test_outbound_subject_includes_public_id_and_reply_to_when_plus_enabled(): void
    {
        Mail::fake();

        $tenant = Tenant::factory()->create();
        $account = MailAccount::factory()->create([
            'tenant_id' => $tenant->id,
            'email_address' => 'support@example.com',
            'supports_plus_addressing' => true,
        ]);

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
            'name' => 'Agent',
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
            'inbound_message_id' => '<in@example.com>',
            'opened_at' => now(),
        ]);

        $message = WorkItemMessage::query()->create([
            'tenant_id' => $tenant->id,
            'work_item_id' => $workItem->id,
            'author_user_id' => $agent->id,
            'body' => 'Working on it.',
            'is_internal' => false,
        ]);

        $expectedSubject = WorkItemMailReference::appendSubjectToken('Re: Need help', $workItem->public_id);
        $expectedReplyTo = WorkItemMailReference::plusAddress($account->email_address, $workItem->public_id);

        app(OutboundWorkItemMailService::class)->sendAgentReply($message->fresh(['author', 'workItem']));

        $message->refresh();
        $this->assertNotNull($message->email_sent_at);
        $this->assertSame('Re: Need help [#'.$workItem->public_id.']', $expectedSubject);
        $this->assertSame('support+'.$workItem->public_id.'@example.com', $expectedReplyTo);
    }

    public function test_graph_outbound_sets_reply_to_only_when_plus_addressing_enabled(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'token']),
            'https://graph.microsoft.com/v1.0/*' => Http::response([], 202),
        ]);

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'id' => 'entra-plus-out',
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
            'supports_plus_addressing' => true,
            'integration_type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            'integration_id' => (string) $integration->getKey(),
            'connection_settings' => ['mailbox_upn' => 'shared@example.com'],
        ]);

        $agent = User::factory()->create(['tenant_id' => $tenant->id, 'type' => 'agent', 'name' => 'Agent']);
        $requester = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
            'email' => 'customer@example.com',
        ]);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $tenant->generalQueue()?->id,
            'reported_by_user_id' => $requester->id,
            'title' => 'Graph plus',
            'priority' => 'normal',
            'status' => 'New',
            'source' => WorkItemSource::Email,
            'mail_account_id' => $account->id,
            'inbound_message_id' => '<g@example.com>',
            'opened_at' => now(),
        ]);

        $message = WorkItemMessage::query()->create([
            'tenant_id' => $tenant->id,
            'work_item_id' => $workItem->id,
            'author_user_id' => $agent->id,
            'body' => 'Hello',
            'is_internal' => false,
        ]);

        app(OutboundWorkItemMailService::class)->sendAgentReply($message->fresh(['author', 'workItem']));

        Http::assertSent(function ($request) use ($workItem): bool {
            if (! str_contains($request->url(), '/sendMail')) {
                return false;
            }

            $messagePayload = $request->data()['message'] ?? [];
            $subject = (string) ($messagePayload['subject'] ?? '');
            $replyTo = $messagePayload['replyTo'][0]['emailAddress']['address'] ?? null;

            return str_contains($subject, '[#'.$workItem->public_id.']')
                && $replyTo === 'shared+'.$workItem->public_id.'@example.com';
        });
    }

    public function test_public_id_length_respects_config(): void
    {
        config(['helixdesk.mail.work_item_public_id_length' => 8]);

        $tenant = Tenant::factory()->create();
        $id = app(WorkItemPublicIdGenerator::class)->generate((int) $tenant->id);

        $this->assertSame(8, strlen($id));
    }
}
