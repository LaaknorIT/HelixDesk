<?php

namespace Tests\Feature;

use App\Enums\ChatPlatform;
use App\Enums\WorkItemNotificationEvent;
use App\Filament\Agent\Pages\NotificationSettings;
use App\Filament\Agent\Resources\Queues\Pages\EditQueue;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notifications\ChatWebhookPlatform;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        Filament::setCurrentPanel('agent');
        Filament::setTenant($this->tenant, isQuiet: true);
    }

    protected function tearDown(): void
    {
        setPermissionsTeamId(null);

        parent::tearDown();
    }

    public function test_agent_sees_every_notification_enabled_by_default(): void
    {
        $this->actingAs($this->makeStaff('Agent'));

        Livewire::test(NotificationSettings::class, ['tenant' => $this->tenant])
            ->assertOk()
            ->assertSet('data.mail', array_keys(WorkItemNotificationEvent::options()))
            ->assertSet('data.database', array_keys(WorkItemNotificationEvent::options()));
    }

    public function test_agent_can_save_notification_preferences(): void
    {
        $agent = $this->makeStaff('Agent');
        $this->actingAs($agent);

        Livewire::test(NotificationSettings::class, ['tenant' => $this->tenant])
            ->set('data.mail', [WorkItemNotificationEvent::Assigned->value])
            ->set('data.database', [])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([
            'mail' => [WorkItemNotificationEvent::Assigned->value],
            'database' => [],
        ], $agent->fresh()->notification_preferences);
    }

    public function test_unknown_events_are_rejected(): void
    {
        $agent = $this->makeStaff('Agent');
        $this->actingAs($agent);

        Livewire::test(NotificationSettings::class, ['tenant' => $this->tenant])
            ->set('data.mail', ['not-an-event'])
            ->call('save')
            ->assertHasErrors(['data.mail.0']);

        $this->assertNull($agent->fresh()->notification_preferences);
    }

    public function test_end_users_cannot_open_notification_settings(): void
    {
        $this->actingAs(User::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'end_user']));

        $this->assertFalse(NotificationSettings::canAccess());
    }

    public function test_queue_saves_chat_webhooks_encrypted(): void
    {
        $this->actingAs($this->makeStaff('Tenant Admin'));
        $queue = $this->tenant->generalQueue();

        Livewire::test(EditQueue::class, ['tenant' => $this->tenant, 'record' => $queue->getKey()])
            ->assertSet('data.chat_notification_events', WorkItemNotificationEvent::defaultChatEvents())
            ->set('data.slack_webhook_url', 'https://hooks.slack.com/services/T000/B000/XXXX')
            ->set('data.chat_notification_events', [WorkItemNotificationEvent::Created->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $queue->refresh();
        $this->assertSame('https://hooks.slack.com/services/T000/B000/XXXX', $queue->slack_webhook_url);
        $this->assertSame([WorkItemNotificationEvent::Created->value], $queue->chat_notification_events);
        $this->assertStringNotContainsString('hooks.slack.com', (string) DB::table('queues')->where('id', $queue->id)->value('slack_webhook_url'));
    }

    public function test_queue_rejects_a_webhook_url_on_another_host(): void
    {
        $this->actingAs($this->makeStaff('Tenant Admin'));
        $queue = $this->tenant->generalQueue();

        Livewire::test(EditQueue::class, ['tenant' => $this->tenant, 'record' => $queue->getKey()])
            ->set('data.teams_webhook_url', 'https://169.254.169.254/latest/meta-data')
            ->call('save')
            ->assertHasFormErrors(['teams_webhook_url']);

        $this->assertNull($queue->fresh()->teams_webhook_url);
    }

    public function test_webhook_urls_must_be_https_on_the_platform_hosts(): void
    {
        $this->assertTrue(ChatWebhookPlatform::allowsUrl(ChatPlatform::Slack, 'https://hooks.slack.com/services/T/B/X'));
        $this->assertTrue(ChatWebhookPlatform::allowsUrl(ChatPlatform::Teams, 'https://prod-12.westeurope.logic.azure.com:443/workflows/x'));
        $this->assertTrue(ChatWebhookPlatform::allowsUrl(ChatPlatform::Teams, 'https://default0123.ab.environment.api.powerplatform.com/powerautomate/x'));

        $this->assertFalse(ChatWebhookPlatform::allowsUrl(ChatPlatform::Slack, 'http://hooks.slack.com/services/T/B/X'));
        $this->assertFalse(ChatWebhookPlatform::allowsUrl(ChatPlatform::Slack, 'https://hooks.slack.com.evil.example/x'));
        $this->assertFalse(ChatWebhookPlatform::allowsUrl(ChatPlatform::Slack, 'https://user@hooks.slack.com/x'));
        $this->assertFalse(ChatWebhookPlatform::allowsUrl(ChatPlatform::Teams, 'https://logic.azure.com.example.net/x'));
        $this->assertFalse(ChatWebhookPlatform::allowsUrl(ChatPlatform::Teams, 'https://hooks.slack.com/services/T/B/X'));
        $this->assertFalse(ChatWebhookPlatform::allowsUrl(ChatPlatform::Teams, null));
    }

    private function makeStaff(string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'agent']);

        setPermissionsTeamId($this->tenant->id);
        $user->assignRole($role);

        return $user->fresh();
    }
}
