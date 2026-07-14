<?php

namespace Tests\Feature;

use App\Enums\MailOutboundProtocol;
use App\Enums\WorkItemSource;
use App\Jobs\SendWorkItemReplyMailJob;
use App\Models\MailAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Services\Mail\OutboundWorkItemMailService;
use App\Services\WorkItemWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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
}
