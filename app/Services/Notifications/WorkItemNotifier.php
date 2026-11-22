<?php

namespace App\Services\Notifications;

use App\Enums\ChatPlatform;
use App\Enums\WorkItemNotificationEvent;
use App\Jobs\SendWorkItemChatMessageJob;
use App\Jobs\SendWorkItemNotificationsJob;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Notifications\WorkItemActivityNotification;
use App\Support\HelixdeskWorkItemAuthorization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Decides who hears about work item activity and on which channels. See docs/notifications.md.
 */
class WorkItemNotifier
{
    public function workItemCreated(WorkItem $workItem): void
    {
        $this->queue($workItem, WorkItemNotificationEvent::Created);
    }

    public function workItemAssigned(WorkItem $workItem): void
    {
        if ($workItem->assigned_agent_id === null) {
            return;
        }

        $this->queue($workItem, WorkItemNotificationEvent::Assigned);
    }

    public function customerReplied(WorkItem $workItem, WorkItemMessage $message): void
    {
        $this->queue($workItem, WorkItemNotificationEvent::CustomerReplied, $message->body);
    }

    public function slaAtRisk(WorkItem $workItem, string $detail): void
    {
        $this->queue($workItem, WorkItemNotificationEvent::SlaAtRisk, $detail);
    }

    /**
     * Send the notification now. Runs inside {@see SendWorkItemNotificationsJob}.
     */
    public function deliver(WorkItem $workItem, WorkItemNotificationEvent $event, ?int $actorUserId, ?string $detail): void
    {
        $recipients = $this->recipients($workItem, $event)
            ->reject(fn (User $user): bool => $actorUserId !== null && (int) $user->getKey() === $actorUserId)
            ->unique(fn (User $user): int => (int) $user->getKey())
            ->values();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new WorkItemActivityNotification($workItem, $event, $detail));
        }

        $queue = $workItem->queue;

        if ($queue === null || ! $queue->postsToChat($event)) {
            return;
        }

        foreach (ChatPlatform::cases() as $platform) {
            if (filled($queue->getAttribute($platform->webhookAttribute()))) {
                SendWorkItemChatMessageJob::dispatch($workItem, $event, $platform, $detail);
            }
        }
    }

    /**
     * Tenant staff who can read tickets in the work item's queue.
     *
     * @return Collection<int, User>
     */
    public function queueAgents(WorkItem $workItem): Collection
    {
        if ($workItem->queue_id === null || $workItem->tenant_id === null) {
            return new Collection;
        }

        $tenantId = (int) $workItem->tenant_id;
        $queueId = (int) $workItem->queue_id;
        $previousTeamId = getPermissionsTeamId();

        setPermissionsTeamId($tenantId);

        try {
            $roleUserIds = DB::table('model_has_roles')
                ->where('team_id', $tenantId)
                ->where('model_type', (new User)->getMorphClass())
                ->pluck('model_id');

            return User::query()
                ->where(function ($query) use ($tenantId, $roleUserIds): void {
                    $query->where(function ($query) use ($tenantId): void {
                        $query->where('tenant_id', $tenantId)->where('type', 'agent');
                    })->orWhereIn('id', $roleUserIds);
                })
                ->orderBy('id')
                ->get()
                ->filter(fn (User $user): bool => HelixdeskWorkItemAuthorization::hasWorkItemPermission($user, 'read', $queueId))
                ->values()
                ->toBase();
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }

    private function queue(WorkItem $workItem, WorkItemNotificationEvent $event, ?string $detail = null): void
    {
        $actorUserId = Auth::id();

        SendWorkItemNotificationsJob::dispatch(
            $workItem,
            $event,
            $actorUserId !== null ? (int) $actorUserId : null,
            $detail,
        )->afterCommit();
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(WorkItem $workItem, WorkItemNotificationEvent $event): Collection
    {
        $assignee = $workItem->assignedAgent;

        return match ($event) {
            WorkItemNotificationEvent::Created => $this->queueAgents($workItem)
                ->reject(fn (User $user): bool => (int) $user->getKey() === (int) $workItem->assigned_agent_id),
            WorkItemNotificationEvent::Assigned => collect($assignee !== null ? [$assignee] : []),
            WorkItemNotificationEvent::CustomerReplied,
            WorkItemNotificationEvent::SlaAtRisk => $assignee !== null ? collect([$assignee]) : $this->queueAgents($workItem),
        };
    }
}
