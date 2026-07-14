<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Builder;

final class WorkItemQueueScope
{
    /**
     * Queues this user may read work_items in (per-role queue pivot + incident permissions).
     *
     * @return list<int>
     */
    public static function assignedQueueIds(User $user): array
    {
        return HelixdeskWorkItemAuthorization::queueIdsForWorkItemAction($user, 'read');
    }

    public static function canAccess(User $user, WorkItem $workItem): bool
    {
        if ($user->type === 'end_user') {
            return (int) $workItem->reported_by_user_id === (int) $user->id;
        }

        $queueId = $workItem->queue_id;

        if ($queueId === null) {
            return false;
        }

        return HelixdeskWorkItemAuthorization::hasWorkItemPermission($user, 'read', (int) $queueId);
    }

    public static function applyToWorkItemQuery(Builder $query, User $user): Builder
    {
        if ($user->type === 'end_user') {
            return $query->where('reported_by_user_id', $user->id);
        }

        if ($user->hasPermissionTo('work_item.admin')) {
            return $query;
        }

        $queueIds = self::assignedQueueIds($user);

        if ($queueIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('queue_id', $queueIds);
    }
}
