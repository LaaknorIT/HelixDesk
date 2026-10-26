<?php

namespace Tests\Feature;

use App\Enums\WorkItemNotificationEvent;
use App\Models\Queue;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Notifications\WorkItemActivityNotification;
use App\Services\SlaTimerService;
use App\Services\WorkItemWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkItemNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $agent;

    private User $otherAgent;

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Http::fake();

        $this->tenant = Tenant::factory()->create();
        $this->agent = $this->makeAgent('agent@example.com');
        $this->otherAgent = $this->makeAgent('other@example.com');
        $this->requester = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'end_user',
            'email' => 'customer@example.com',
        ]);
    }

    protected function tearDown(): void
    {
        setPermissionsTeamId(null);

        parent::tearDown();
    }

    public function test_new_ticket_notifies_queue_agents_except_the_creator(): void
    {
        $this->actingAs($this->agent);
        setPermissionsTeamId($this->tenant->id);

        $workItem = app(WorkItemWorkflowService::class)->createWorkItem(['title' => 'Printer offline'], $this->agent);

        $this->assertNotifiedAbout($this->otherAgent, $workItem, WorkItemNotificationEvent::Created);
        Notification::assertNotSentTo($this->agent, WorkItemActivityNotification::class);
        Notification::assertNotSentTo($this->requester, WorkItemActivityNotification::class);
    }

    public function test_new_ticket_in_a_queue_the_agent_cannot_read_is_not_sent_to_them(): void
    {
        $privateQueue = Queue::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Finance']);

        $this->makeWorkItem(['queue_id' => $privateQueue->id]);

        Notification::assertNotSentTo([$this->agent, $this->otherAgent], WorkItemActivityNotification::class);
    }

    public function test_assignment_notifies_the_new_assignee_only(): void
    {
        $workItem = $this->makeWorkItem();
        Notification::fake();

        $this->actingAs($this->agent);
        $workItem->update(['assigned_agent_id' => $this->otherAgent->id]);

        $this->assertNotifiedAbout($this->otherAgent, $workItem, WorkItemNotificationEvent::Assigned);
        Notification::assertNotSentTo($this->agent, WorkItemActivityNotification::class);
    }

    public function test_assigning_a_ticket_to_yourself_sends_nothing(): void
    {
        $workItem = $this->makeWorkItem();
        Notification::fake();

        $this->actingAs($this->agent);
        $workItem->update(['assigned_agent_id' => $this->agent->id]);

        Notification::assertNothingSent();
    }

    public function test_ticket_created_with_an_assignee_sends_the_assignee_one_assignment_notification(): void
    {
        $workItem = $this->makeWorkItem(['assigned_agent_id' => $this->agent->id]);

        $this->assertNotifiedAbout($this->agent, $workItem, WorkItemNotificationEvent::Assigned);
        Notification::assertSentToTimes($this->agent, WorkItemActivityNotification::class, 1);
        $this->assertNotifiedAbout($this->otherAgent, $workItem, WorkItemNotificationEvent::Created);
    }

    public function test_customer_reply_notifies_the_assignee(): void
    {
        $workItem = $this->makeWorkItem(['assigned_agent_id' => $this->agent->id]);
        Notification::fake();

        $this->actingAs($this->requester);
        setPermissionsTeamId($this->tenant->id);
        app(WorkItemWorkflowService::class)->addMessage($workItem, 'It is still broken', $this->requester);

        $this->assertNotifiedAbout($this->agent, $workItem, WorkItemNotificationEvent::CustomerReplied, 'It is still broken');
        Notification::assertNotSentTo($this->otherAgent, WorkItemActivityNotification::class);
    }

    public function test_customer_reply_on_an_unassigned_ticket_notifies_the_queue_agents(): void
    {
        $workItem = $this->makeWorkItem();
        Notification::fake();

        $this->actingAs($this->requester);
        setPermissionsTeamId($this->tenant->id);
        app(WorkItemWorkflowService::class)->addMessage($workItem, 'Any news?', $this->requester);

        $this->assertNotifiedAbout($this->agent, $workItem, WorkItemNotificationEvent::CustomerReplied);
        $this->assertNotifiedAbout($this->otherAgent, $workItem, WorkItemNotificationEvent::CustomerReplied);
    }

    public function test_agent_reply_does_not_notify_as_a_customer_reply(): void
    {
        $workItem = $this->makeWorkItem(['assigned_agent_id' => $this->otherAgent->id]);
        Notification::fake();

        $this->actingAs($this->agent);
        setPermissionsTeamId($this->tenant->id);
        app(WorkItemWorkflowService::class)->addInternalNote($workItem, 'Checked the logs', $this->agent);

        Notification::assertNothingSent();
    }

    public function test_sla_at_risk_warns_the_assignee_once(): void
    {
        $workItem = $this->makeWorkItem([
            'assigned_agent_id' => $this->agent->id,
            'first_response_due_at' => now()->addMinutes(10),
            'resolution_due_at' => now()->addDay(),
        ]);
        Notification::fake();

        $service = app(SlaTimerService::class);

        $this->assertSame(1, $service->notifyAtRiskWorkItems());
        $this->assertNotifiedAbout($this->agent, $workItem, WorkItemNotificationEvent::SlaAtRisk, 'First response due');
        $this->assertNotNull($workItem->fresh()->response_warning_notified_at);
        $this->assertNull($workItem->fresh()->resolution_warning_notified_at);

        Notification::fake();
        $this->assertSame(0, $service->notifyAtRiskWorkItems());
        Notification::assertNothingSent();
    }

    public function test_sla_at_risk_skips_paused_answered_and_distant_targets(): void
    {
        $this->makeWorkItem(['first_response_due_at' => now()->addMinutes(10), 'sla_paused_at' => now()]);
        $this->makeWorkItem(['first_response_due_at' => now()->addMinutes(10), 'first_responded_at' => now()]);
        $this->makeWorkItem(['first_response_due_at' => now()->addHours(3)]);
        $this->makeWorkItem(['first_response_due_at' => now()->subMinute()]);
        Notification::fake();

        $this->assertSame(0, app(SlaTimerService::class)->notifyAtRiskWorkItems());
        Notification::assertNothingSent();
    }

    public function test_preferences_choose_the_channels(): void
    {
        $this->otherAgent->update(['notification_preferences' => [
            'mail' => [WorkItemNotificationEvent::Assigned->value],
            'database' => [WorkItemNotificationEvent::Created->value],
        ]]);

        $workItem = $this->makeWorkItem();

        Notification::assertSentTo(
            $this->otherAgent,
            WorkItemActivityNotification::class,
            fn (WorkItemActivityNotification $notification, array $channels): bool => $channels === ['database'],
        );
        Notification::assertSentTo(
            $this->agent,
            WorkItemActivityNotification::class,
            fn (WorkItemActivityNotification $notification, array $channels): bool => $channels === ['mail', 'database'],
        );

        $this->assertSame([], (new WorkItemActivityNotification($workItem, WorkItemNotificationEvent::CustomerReplied))
            ->via($this->otherAgent->fresh()));
    }

    public function test_mail_and_in_app_notifications_link_to_the_ticket(): void
    {
        $workItem = $this->makeWorkItem(['title' => 'VPN down', 'priority' => 'high']);
        $notification = new WorkItemActivityNotification($workItem, WorkItemNotificationEvent::Created);

        $mail = $notification->toMail($this->agent);
        $expectedUrl = url('/agent/'.$this->tenant->slug.'/work-items/'.$workItem->id);

        $this->assertSame('New ticket: #'.$workItem->public_id.' VPN down', $mail->subject);
        $this->assertSame($expectedUrl, $mail->actionUrl);
        $this->assertContains('Priority: High', $mail->introLines);

        $database = $notification->toDatabase($this->agent);
        $this->assertSame('filament', $database['format']);
        $this->assertStringContainsString('VPN down', $database['title']);
        $this->assertSame($expectedUrl, $database['actions'][0]['url']);
    }

    public function test_events_are_posted_to_the_queue_chat_channels(): void
    {
        $queue = $this->tenant->generalQueue();
        $queue->update([
            'slack_webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXX',
            'teams_webhook_url' => 'https://prod-01.westeurope.logic.azure.com/workflows/abc/triggers/manual/paths/invoke?sig=x',
        ]);

        $workItem = $this->makeWorkItem(['title' => 'Mail server down']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hooks.slack.com/services/T000/B000/XXXX'
            && str_contains($request['text'], 'Mail server down')
            && str_contains(json_encode($request['blocks'], JSON_UNESCAPED_SLASHES), '/work-items/'.$workItem->id));

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://prod-01.westeurope.logic.azure.com/')
            && $request['type'] === 'message'
            && $request['attachments'][0]['content']['type'] === 'AdaptiveCard'
            && $request['attachments'][0]['content']['body'][0]['text'] === 'New ticket');

        Http::assertSentCount(2);
    }

    public function test_chat_only_gets_the_events_the_queue_selected(): void
    {
        $queue = $this->tenant->generalQueue();
        $queue->update([
            'slack_webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXX',
            'chat_notification_events' => [WorkItemNotificationEvent::SlaAtRisk->value],
        ]);

        $workItem = $this->makeWorkItem();
        $workItem->update(['assigned_agent_id' => $this->agent->id]);

        Http::assertNothingSent();
    }

    public function test_chat_is_not_posted_to_a_webhook_on_another_host(): void
    {
        $queue = $this->tenant->generalQueue();
        $queue->forceFill(['slack_webhook_url' => 'https://internal.example.com/hook'])->save();

        $this->makeWorkItem();

        Http::assertNothingSent();
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
            'opened_at' => now(),
            ...$attributes,
        ]);
    }

    private function makeAgent(string $email): User
    {
        $agent = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'agent',
            'email' => $email,
        ]);

        setPermissionsTeamId($this->tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        return $agent->fresh();
    }

    private function assertNotifiedAbout(User $user, WorkItem $workItem, WorkItemNotificationEvent $event, ?string $detail = null): void
    {
        Notification::assertSentTo(
            $user,
            WorkItemActivityNotification::class,
            fn (WorkItemActivityNotification $notification): bool => $notification->event === $event
                && (int) $notification->workItem->id === (int) $workItem->id
                && ($detail === null || str_contains((string) $notification->detail, $detail)),
        );
    }
}
