<?php

namespace App\Jobs;

use App\Enums\ChatPlatform;
use App\Enums\WorkItemNotificationEvent;
use App\Models\WorkItem;
use App\Services\Notifications\ChatWebhookPlatform;
use App\Services\Notifications\WorkItemNotificationContent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Posts a work item notification to the Teams or Slack channel configured on the work item's queue.
 */
class SendWorkItemChatMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public function __construct(
        public WorkItem $workItem,
        public WorkItemNotificationEvent $event,
        public ChatPlatform $platform,
        public ?string $detail = null,
    ) {}

    public function handle(): void
    {
        $queue = $this->workItem->queue;
        $url = $queue?->getAttribute($this->platform->webhookAttribute());

        if ($queue === null || ! $queue->postsToChat($this->event)) {
            return;
        }

        if (! ChatWebhookPlatform::allowsUrl($this->platform, $url)) {
            Log::warning('Skipping chat notification: webhook URL is missing or not allowed', [
                'queue_id' => $queue->getKey(),
                'platform' => $this->platform->value,
            ]);

            return;
        }

        $content = WorkItemNotificationContent::for($this->workItem, $this->event, $this->detail);

        Http::timeout(10)
            ->withoutRedirecting()
            ->asJson()
            ->post($url, ChatWebhookPlatform::payload($this->platform, $content))
            ->throw();
    }
}
