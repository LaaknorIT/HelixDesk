<?php

namespace App\Jobs;

use App\Enums\WorkItemNotificationEvent;
use App\Models\WorkItem;
use App\Services\Notifications\WorkItemNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Works out the recipients of a work item notification off the request, then sends it.
 */
class SendWorkItemNotificationsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public WorkItem $workItem,
        public WorkItemNotificationEvent $event,
        public ?int $actorUserId = null,
        public ?string $detail = null,
    ) {}

    public function handle(WorkItemNotifier $notifier): void
    {
        $notifier->deliver($this->workItem, $this->event, $this->actorUserId, $this->detail);
    }
}
