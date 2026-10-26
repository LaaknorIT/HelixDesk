<?php

namespace App\Observers;

use App\Models\WorkItem;
use App\Services\Notifications\WorkItemNotifier;

class WorkItemObserver
{
    public function __construct(private readonly WorkItemNotifier $notifier) {}

    public function created(WorkItem $workItem): void
    {
        $this->notifier->workItemCreated($workItem);
        $this->notifier->workItemAssigned($workItem);
    }

    public function updated(WorkItem $workItem): void
    {
        if ($workItem->wasChanged('assigned_agent_id')) {
            $this->notifier->workItemAssigned($workItem);
        }
    }
}
