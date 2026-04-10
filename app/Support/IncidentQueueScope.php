<?php

namespace App\Support;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class IncidentQueueScope
{
    /**
     * Queues this user may read incidents in (per-role queue pivot + incident permissions).
     *
     * @return list<int>
     */
    public static function assignedQueueIds(User $user): array
    {
        return HelixdeskIncidentAuthorization::queueIdsForIncidentAction($user, 'read');
    }

    public static function canAccess(User $user, Incident $incident): bool
    {
        if ($user->type === 'end_user') {
            return (int) $incident->reported_by_user_id === (int) $user->id;
        }

        $queueId = $incident->queue_id;

        if ($queueId === null) {
            return false;
        }

        return HelixdeskIncidentAuthorization::hasIncidentPermission($user, 'read', (int) $queueId);
    }

    public static function applyToIncidentQuery(Builder $query, User $user): Builder
    {
        if ($user->type === 'end_user') {
            return $query->where('reported_by_user_id', $user->id);
        }

        if ($user->hasPermissionTo('incident.admin')) {
            return $query;
        }

        $queueIds = self::assignedQueueIds($user);

        if ($queueIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('queue_id', $queueIds);
    }
}
