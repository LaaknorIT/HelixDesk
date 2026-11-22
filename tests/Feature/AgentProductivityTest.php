<?php

namespace Tests\Feature;

use App\Enums\MailOutboundProtocol;
use App\Enums\WorkItemNotificationEvent;
use App\Enums\WorkItemSource;
use App\Filament\Agent\Resources\CannedResponses\CannedResponseResource;
use App\Filament\Agent\Resources\CannedResponses\Pages\CreateCannedResponse;
use App\Filament\Agent\Resources\WorkItems\Pages\ViewWorkItem;
use App\Jobs\SendWorkItemReplyMailJob;
use App\Livewire\WorkItemViewers;
use App\Models\CannedResponse;
use App\Models\MailAccount;
use App\Models\Queue;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Notifications\WorkItemActivityNotification;
use App\Services\Mail\InboundMailIngestionService;
use App\Services\Mail\OutboundWorkItemMailService;
use App\Services\WorkItems\CannedResponseRenderer;
use App\Services\WorkItems\WorkItemCollaborationService;
use App\Services\WorkItems\WorkItemPresence;
use App\Services\WorkItemWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class AgentProductivityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $agent;

    private User $otherAgent;

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([SendWorkItemReplyMailJob::class]);

        $this->tenant = Tenant::factory()->create();
        $this->agent = $this->makeUser('agent@example.com', 'Agent', 'Alex Agent');
        $this->otherAgent = $this->makeUser('other@example.com', 'Agent', 'Olivia Other');
        $this->requester = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'end_user',
            'name' => 'Rita Requester',
            'email' => 'rita@example.com',
        ]);
    }

    protected function tearDown(): void
    {
        setPermissionsTeamId(null);

        parent::tearDown();
    }

    public function test_canned_response_placeholders_are_filled_in(): void
    {
        $workItem = $this->makeWorkItem(['title' => 'VPN down']);

        $text = app(CannedResponseRenderer::class)->render(
            "Hi {{requester.first_name}} ({{ requester.name }}),\n{{ticket.id}} {{ticket.title}} in {{queue.name}}. {{agent.name}} {{unknown.thing}}",
            $workItem,
            $this->agent,
        );

        $this->assertSame(
            "Hi Rita (Rita Requester),\n#{$workItem->id} VPN down in {$workItem->queue->name}. Alex Agent {{unknown.thing}}",
            $text,
        );
    }

    public function test_canned_responses_are_offered_for_all_queues_or_the_tickets_queue(): void
    {
        $otherQueue = Queue::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Finance']);
        $shared = $this->makeCannedResponse(['title' => 'Shared']);
        $general = $this->makeCannedResponse(['title' => 'General only', 'queue_id' => $this->tenant->generalQueue()->id]);
        $this->makeCannedResponse(['title' => 'Finance only', 'queue_id' => $otherQueue->id]);
        $otherTenant = Tenant::factory()->create();
        CannedResponse::query()->create(['tenant_id' => $otherTenant->id, 'title' => 'Other tenant', 'body' => 'x']);

        $ids = CannedResponse::query()->availableFor($this->makeWorkItem())->pluck('id')->sort()->values()->all();

        $this->assertSame([$shared->id, $general->id], $ids);
    }

    public function test_picking_a_canned_response_appends_it_to_the_message(): void
    {
        $response = $this->makeCannedResponse(['title' => 'Greeting', 'body' => 'Thanks {{requester.first_name}}, we are on it.']);
        $workItem = $this->makeWorkItem();

        $this->viewPage($workItem)
            ->fillForm(['body' => 'Hello.'], 'workZoneForm')
            ->set('workZoneData.canned_response_id', $response->id)
            ->assertSet('workZoneData.body', "Hello.\n\nThanks Rita, we are on it.")
            ->assertSet('workZoneData.canned_response_id', null);
    }

    public function test_tenant_admins_manage_canned_responses_and_agents_cannot(): void
    {
        $admin = $this->makeUser('admin@example.com', 'Tenant Admin', 'Ada Admin');
        $this->actInPanel($admin);

        Livewire::test(CreateCannedResponse::class, ['tenant' => $this->tenant])
            ->fillForm(['title' => 'Password reset', 'body' => 'Use the self-service portal.'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('canned_responses', [
            'tenant_id' => $this->tenant->id,
            'title' => 'Password reset',
            'queue_id' => null,
        ]);
        $this->assertTrue(CannedResponseResource::canAccess());

        $this->actInPanel($this->agent);
        $this->assertFalse(CannedResponseResource::canAccess());
    }

    public function test_agents_link_and_unlink_tickets_from_the_page(): void
    {
        $workItem = $this->makeWorkItem(['title' => 'Mail is slow']);
        $other = $this->makeWorkItem(['title' => 'Outlook hangs']);

        $this->viewPage($workItem)
            ->callAction('linkTicket', ['linked_work_item_id' => $other->id])
            ->assertNotified('Linked to #'.$other->id.' Outlook hangs');

        $this->assertSame([$other->id], $workItem->linkedWorkItems()->pluck('id')->all());
        $this->assertSame([$workItem->id], $other->linkedWorkItems()->pluck('id')->all());

        $this->viewPage($other)
            ->callAction('unlinkTicket', ['linked_work_item_id' => $workItem->id])
            ->assertNotified('Link removed');

        $this->assertTrue($workItem->linkedWorkItems()->isEmpty());
    }

    public function test_ticket_search_finds_readable_tickets_by_number_or_title(): void
    {
        $workItem = $this->makeWorkItem(['title' => 'Mail is slow']);
        $match = $this->makeWorkItem(['title' => 'Outlook Hangs']);
        $this->makeWorkItem(['title' => 'Outlook in HR', 'queue_id' => Queue::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'HR'])->id]);

        $page = $this->viewPage($workItem)->instance();
        $search = fn (string $term): array => array_keys((fn () => $this->searchTickets($term))->call($page));

        $this->assertSame([$match->id], $search('outlook'));
        $this->assertSame([$match->id], $search('#'.$match->id));
        $this->assertSame([], $search((string) $workItem->id));
    }

    public function test_tickets_cannot_be_linked_to_themselves_or_to_unreadable_tickets(): void
    {
        $service = app(WorkItemCollaborationService::class);
        $workItem = $this->makeWorkItem();
        $private = $this->makeWorkItem(['queue_id' => Queue::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'HR'])->id]);
        setPermissionsTeamId($this->tenant->id);

        try {
            $service->link($workItem, $workItem, $this->agent);
            $this->fail('Linking a ticket to itself should fail.');
        } catch (UnprocessableEntityHttpException) {
        }

        $this->expectException(AccessDeniedHttpException::class);
        $service->link($workItem, $private, $this->agent);
    }

    public function test_merging_moves_the_conversation_and_closes_the_duplicate(): void
    {
        $secondRequester = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'end_user',
            'email' => 'Bob@Example.com',
        ]);
        $target = $this->makeWorkItem(['title' => 'Printer broken', 'cc_addresses' => ['boss@example.com']]);
        $source = $this->makeWorkItem([
            'title' => 'Printer still broken',
            'reported_by_user_id' => $secondRequester->id,
            'cc_addresses' => ['boss@example.com', 'it@example.com'],
        ]);
        $sourceMessage = $this->addCustomerMessage($source, 'Second report');
        $source->watchers()->attach($this->otherAgent->id);

        $this->viewPage($source)
            ->callAction('mergeTicket', ['target_work_item_id' => $target->id])
            ->assertNotified('Merged into #'.$target->id.' Printer broken')
            ->assertRedirect();

        $source->refresh();
        $target->refresh();

        $this->assertSame($target->id, $source->merged_into_work_item_id);
        $this->assertSame('Closed', $source->status);
        $this->assertNotNull($source->closed_at);
        $this->assertSame($target->id, $sourceMessage->fresh()->work_item_id);
        $this->assertSame(['boss@example.com', 'it@example.com', 'bob@example.com'], $target->cc_addresses);
        $this->assertTrue($target->watchers()->whereKey($this->otherAgent->id)->exists());
        $this->assertSame([$target->id], $source->linkedWorkItems()->pluck('id')->all());
        $this->assertDatabaseHas('work_item_messages', [
            'work_item_id' => $target->id,
            'is_internal' => true,
            'body' => "Ticket #{$source->id} (Printer still broken) was merged into this ticket by Alex Agent.",
        ]);
        $this->assertDatabaseHas('work_item_messages', [
            'work_item_id' => $source->id,
            'is_internal' => true,
        ]);

        $this->viewPage($source)
            ->assertSee('Merged into #'.$target->id)
            ->assertActionHidden('mergeTicket');
    }

    public function test_merge_is_refused_for_itself_merged_tickets_and_unwritable_queues(): void
    {
        $service = app(WorkItemCollaborationService::class);
        $a = $this->makeWorkItem();
        $b = $this->makeWorkItem();
        $c = $this->makeWorkItem();
        $private = $this->makeWorkItem(['queue_id' => Queue::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'HR'])->id]);
        setPermissionsTeamId($this->tenant->id);
        $this->actingAs($this->agent);

        $this->assertRefused(fn () => $service->merge($a, $a, $this->agent), UnprocessableEntityHttpException::class);

        $service->merge($a, $b, $this->agent);

        $this->assertRefused(fn () => $service->merge($a->refresh(), $c, $this->agent), UnprocessableEntityHttpException::class);
        $this->assertRefused(fn () => $service->merge($c, $a, $this->agent), UnprocessableEntityHttpException::class);
        $this->assertRefused(fn () => $service->merge($c, $private, $this->agent), AccessDeniedHttpException::class);
        $this->assertNull($c->refresh()->merged_into_work_item_id);
    }

    public function test_email_replies_to_a_merged_ticket_are_filed_on_the_target(): void
    {
        $account = MailAccount::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email_address' => 'support@example.com',
        ]);
        $this->tenant->generalQueue()->mailAccounts()->attach($account->id);
        $target = $this->makeWorkItem(['title' => 'Original']);
        $source = $this->makeWorkItem(['title' => 'Duplicate', 'mail_account_id' => $account->id]);
        setPermissionsTeamId($this->tenant->id);
        $this->actingAs($this->agent);
        app(WorkItemCollaborationService::class)->merge($source, $target, $this->agent);
        auth()->logout();

        $raw = implode("\r\n", [
            'Message-ID: <late-reply@example.com>',
            'From: rita@example.com',
            'To: support@example.com',
            'Subject: Re: Duplicate [#'.$source->public_id.']',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Any news?',
            '',
        ]);
        app(InboundMailIngestionService::class)->ingest($account, $raw);

        $this->assertDatabaseHas('work_item_messages', [
            'work_item_id' => $target->id,
            'external_message_id' => '<late-reply@example.com>',
        ]);
        $this->assertSame(2, WorkItem::query()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_agents_watch_tickets_and_get_customer_replies(): void
    {
        Notification::fake();
        $workItem = $this->makeWorkItem(['assigned_agent_id' => $this->agent->id]);

        $this->viewPage($workItem, $this->otherAgent)
            ->assertActionVisible('watch')
            ->callAction('watch')
            ->assertNotified('You are watching this ticket');

        $this->assertTrue($workItem->watchers()->whereKey($this->otherAgent->id)->exists());

        $this->actingAs($this->requester);
        setPermissionsTeamId($this->tenant->id);
        app(WorkItemWorkflowService::class)->addMessage($workItem, 'Still broken', $this->requester);

        foreach ([$this->agent, $this->otherAgent] as $user) {
            Notification::assertSentTo(
                $user,
                WorkItemActivityNotification::class,
                fn (WorkItemActivityNotification $notification): bool => $notification->event === WorkItemNotificationEvent::CustomerReplied,
            );
        }
    }

    public function test_watchers_who_lost_access_are_not_notified(): void
    {
        $workItem = $this->makeWorkItem(['assigned_agent_id' => $this->agent->id]);
        $workItem->watchers()->attach($this->otherAgent->id);
        $workItem->update(['queue_id' => Queue::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'HR'])->id]);
        Notification::fake();

        $this->actingAs($this->requester);
        setPermissionsTeamId($this->tenant->id);
        app(WorkItemWorkflowService::class)->addMessage($workItem, 'Hello?', $this->requester);

        Notification::assertNotSentTo($this->otherAgent, WorkItemActivityNotification::class);
    }

    public function test_agents_with_update_manage_watchers_for_others(): void
    {
        $workItem = $this->makeWorkItem();

        $this->viewPage($workItem)
            ->callAction('manageWatchers', ['watcher_ids' => [$this->otherAgent->id]])
            ->assertNotified('Watchers updated');

        $this->assertSame([$this->otherAgent->id], $workItem->watchers()->pluck('users.id')->all());

        $this->viewPage($workItem)
            ->callAction('manageWatchers', ['watcher_ids' => [$this->agent->id]]);

        $this->assertSame([$this->agent->id], $workItem->watchers()->pluck('users.id')->all());
    }

    public function test_cc_addresses_are_cleaned_and_sent_with_replies(): void
    {
        $account = MailAccount::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email_address' => 'support@example.com',
            'outbound_protocol' => MailOutboundProtocol::Smtp,
        ]);
        $workItem = $this->makeWorkItem(['mail_account_id' => $account->id, 'source' => WorkItemSource::Email]);

        $this->viewPage($workItem)
            ->callAction('editCc', ['cc_addresses' => ['Manager@Example.com', 'manager@example.com', 'rita@example.com', 'team@example.com']])
            ->assertNotified('CC recipients updated');

        $this->assertSame(['manager@example.com', 'team@example.com'], $workItem->refresh()->cc_addresses);

        $sent = null;
        Event::listen(MessageSending::class, function (MessageSending $event) use (&$sent): bool {
            $sent = $event->message;

            return false;
        });

        $message = WorkItemMessage::query()->create([
            'tenant_id' => $this->tenant->id,
            'work_item_id' => $workItem->id,
            'author_user_id' => $this->agent->id,
            'body' => 'Fixed now.',
            'is_internal' => false,
        ]);
        app(OutboundWorkItemMailService::class)->sendAgentReply($message);

        $this->assertInstanceOf(Email::class, $sent);
        $this->assertSame(['rita@example.com'], array_map(fn (Address $address): string => $address->getAddress(), $sent->getTo()));
        $this->assertSame(['manager@example.com', 'team@example.com'], array_map(fn (Address $address): string => $address->getAddress(), $sent->getCc()));
        $this->assertSame(['manager@example.com', 'team@example.com'], $message->refresh()->cc_addresses);
    }

    public function test_invalid_cc_addresses_are_rejected(): void
    {
        $workItem = $this->makeWorkItem();

        $this->viewPage($workItem)
            ->callAction('editCc', ['cc_addresses' => ['not-an-address']])
            ->assertHasActionErrors();

        $this->assertNull($workItem->refresh()->cc_addresses);
    }

    public function test_presence_lists_other_recent_viewers(): void
    {
        $presence = app(WorkItemPresence::class);

        $this->assertSame([], $presence->heartbeat(42, $this->agent));
        $this->assertSame(['Alex Agent'], $presence->heartbeat(42, $this->otherAgent));
        $this->assertSame(['Olivia Other'], $presence->otherViewers(42, $this->agent));
        $this->assertSame([], $presence->otherViewers(43, $this->agent));

        $this->travel(WorkItemPresence::EXPIRES_AFTER_SECONDS + 1)->seconds();
        $this->assertSame([], $presence->otherViewers(42, $this->agent));

        $presence->heartbeat(42, $this->agent);
        $presence->leave(42, $this->agent);
        $this->assertSame([], $presence->otherViewers(42, $this->otherAgent));
    }

    public function test_ticket_page_warns_when_another_agent_has_it_open(): void
    {
        $workItem = $this->makeWorkItem();
        app(WorkItemPresence::class)->heartbeat($workItem->id, $this->otherAgent);

        $this->actingAs($this->agent);
        Livewire::test(WorkItemViewers::class, ['workItemId' => $workItem->id])
            ->assertSee('Olivia Other is also viewing this ticket')
            ->assertSet('viewers', ['Olivia Other']);

        $this->viewPage($workItem)->assertSee('Olivia Other is also viewing this ticket');
    }

    public function test_reply_is_held_once_when_someone_else_replied_meanwhile(): void
    {
        $workItem = $this->makeWorkItem();
        $page = $this->viewPage($workItem);

        $this->addCustomerMessage($workItem, 'Never mind, it works now');

        $page->fillForm(['body' => 'Have you tried restarting?'], 'workZoneForm')
            ->call('sendReply')
            ->assertNotified('New reply since you opened this ticket')
            ->assertSet('workZoneData.body', 'Have you tried restarting?');

        $this->assertDatabaseMissing('work_item_messages', ['body' => 'Have you tried restarting?']);

        $page->call('sendReply')->assertNotified('Reply saved');

        $this->assertDatabaseHas('work_item_messages', ['body' => 'Have you tried restarting?', 'author_user_id' => $this->agent->id]);
    }

    public function test_agents_can_reply_on_tickets_with_portal_priority_names(): void
    {
        $workItem = $this->makeWorkItem(['priority' => 'Medium']);

        // Older priority names are stored as the current ones, so the priority picker can show them.
        $this->assertSame('normal', $workItem->fresh()->priority);

        $this->viewPage($workItem)
            ->assertSet('workZoneData.priority', 'normal')
            ->fillForm(['body' => 'On it'], 'workZoneForm')
            ->call('sendReply')
            ->assertHasNoErrors()
            ->assertNotified('Reply saved');
    }

    public function test_internal_notes_and_own_messages_do_not_hold_a_reply(): void
    {
        $workItem = $this->makeWorkItem();
        $page = $this->viewPage($workItem);

        WorkItemMessage::query()->create([
            'tenant_id' => $this->tenant->id,
            'work_item_id' => $workItem->id,
            'author_user_id' => $this->otherAgent->id,
            'is_internal' => true,
            'body' => 'Internal remark',
        ]);

        $page->fillForm(['body' => 'First reply'], 'workZoneForm')
            ->call('sendReply')
            ->assertNotified('Reply saved')
            ->fillForm(['body' => 'Second reply'], 'workZoneForm')
            ->call('sendReply')
            ->assertNotified('Reply saved');

        $this->assertDatabaseHas('work_item_messages', ['body' => 'Second reply']);
    }

    private function assertRefused(callable $callback, string $exception): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e);

            return;
        }

        $this->fail("Expected {$exception}.");
    }

    private function viewPage(WorkItem $workItem, ?User $user = null): Testable
    {
        $this->actInPanel($user ?? $this->agent);

        return Livewire::test(ViewWorkItem::class, [
            'tenant' => $this->tenant,
            'record' => $workItem->getKey(),
        ])->assertOk();
    }

    private function actInPanel(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('agent');
        Filament::setTenant($this->tenant, isQuiet: true);
        setPermissionsTeamId($this->tenant->id);
    }

    private function addCustomerMessage(WorkItem $workItem, string $body): WorkItemMessage
    {
        return WorkItemMessage::query()->create([
            'tenant_id' => $workItem->tenant_id,
            'work_item_id' => $workItem->id,
            'author_user_id' => $workItem->reported_by_user_id,
            'is_internal' => false,
            'body' => $body,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeCannedResponse(array $attributes = []): CannedResponse
    {
        return CannedResponse::query()->create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Response',
            'body' => 'Text',
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeWorkItem(array $attributes = []): WorkItem
    {
        return WorkItem::query()->create([
            'tenant_id' => $this->tenant->id,
            'queue_id' => $this->tenant->generalQueue()->id,
            'reported_by_user_id' => $this->requester->id,
            'title' => 'Something broke',
            'priority' => 'normal',
            'status' => 'New',
            'source' => WorkItemSource::Portal,
            'opened_at' => now(),
            ...$attributes,
        ]);
    }

    private function makeUser(string $email, string $role, string $name): User
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'agent',
            'email' => $email,
            'name' => $name,
        ]);

        setPermissionsTeamId($this->tenant->id);
        $user->assignRole($role);
        setPermissionsTeamId(null);

        return $user->fresh();
    }
}
