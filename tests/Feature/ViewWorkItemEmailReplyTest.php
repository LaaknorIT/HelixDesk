<?php

namespace Tests\Feature;

use App\Enums\WorkItemSource;
use App\Filament\Agent\Resources\WorkItems\Pages\ViewWorkItem;
use App\Jobs\SendWorkItemReplyMailJob;
use App\Models\MailAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Services\Mail\InboundMailIngestionService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class ViewWorkItemEmailReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_reply_and_add_internal_note_on_email_ticket(): void
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

        $account = MailAccount::factory()->create([
            'tenant_id' => $tenant->id,
            'email_address' => 'support-'.uniqid().'@example.com',
        ]);
        $tenant->generalQueue()?->mailAccounts()->attach($account->id);

        $messageId = '<email-ticket-'.uniqid().'@example.com>';
        $from = 'customer-'.uniqid().'@example.com';
        $raw = implode("\r\n", [
            'Message-ID: '.$messageId,
            'From: '.$from,
            'To: '.$account->email_address,
            'Subject: Email ticket for reply',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Please help',
            '',
        ]);

        app(InboundMailIngestionService::class)->ingest($account, $raw);

        $workItem = WorkItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('source', WorkItemSource::Email)
            ->first();

        $this->assertNotNull($workItem);
        $this->assertSame(1, WorkItemMessage::query()->where('work_item_id', $workItem->id)->count());

        $this->actingAs($agent);
        Filament::setCurrentPanel('agent');
        Filament::setTenant($tenant, isQuiet: true);
        setPermissionsTeamId($tenant->id);

        try {
            Livewire::test(ViewWorkItem::class, [
                'tenant' => $tenant,
                'record' => $workItem->getKey(),
            ])
                ->assertOk()
                ->fillForm([
                    'body' => 'We are on it.',
                    'is_internal' => false,
                ], 'workZoneForm')
                ->call('sendReply')
                ->assertHasNoErrors()
                ->assertNotified('Reply saved')
                ->fillForm([
                    'body' => 'Internal follow-up',
                    'is_internal' => true,
                ], 'workZoneForm')
                ->call('sendReply')
                ->assertHasNoErrors()
                ->assertNotified('Internal note added');
        } finally {
            setPermissionsTeamId(null);
        }

        $this->assertSame(3, WorkItemMessage::query()->where('work_item_id', $workItem->id)->count());
        Bus::assertDispatched(SendWorkItemReplyMailJob::class);
    }

    public function test_graph_outbound_failure_does_not_prevent_saving_reply_when_queued(): void
    {
        Bus::fake([SendWorkItemReplyMailJob::class]);

        $tenant = Tenant::factory()->create();
        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Tenant Admin');
        setPermissionsTeamId(null);

        $account = MailAccount::factory()->microsoftGraph()->create([
            'tenant_id' => $tenant->id,
            'email_address' => 'shared-'.uniqid().'@example.com',
        ]);
        $tenant->generalQueue()?->mailAccounts()->attach($account->id);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $tenant->generalQueue()?->id,
            'reported_by_user_id' => User::factory()->create([
                'tenant_id' => $tenant->id,
                'type' => 'end_user',
                'email' => 'requester-'.uniqid().'@example.com',
            ])->id,
            'title' => 'Graph mail ticket',
            'priority' => 'normal',
            'status' => 'New',
            'source' => WorkItemSource::Email,
            'mail_account_id' => $account->id,
            'inbound_message_id' => '<inbound-'.uniqid().'@example.com>',
            'opened_at' => now(),
        ]);

        WorkItemMessage::query()->create([
            'tenant_id' => $tenant->id,
            'work_item_id' => $workItem->id,
            'author_user_id' => $workItem->reported_by_user_id,
            'is_internal' => false,
            'body' => 'Inbound question',
            'external_message_id' => $workItem->inbound_message_id,
            'from_address' => 'requester@example.com',
            'mail_account_id' => $account->id,
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
                    'body' => 'Reply via graph account',
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
            'body' => 'Reply via graph account',
            'is_internal' => false,
        ]);
    }
}
