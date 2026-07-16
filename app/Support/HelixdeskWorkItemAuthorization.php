<?php

namespace App\Support;

use App\Models\Queue;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;

/**
 * Work item module permissions are stored per role+queue on {@see TenantRole::queues()}
 * pivot (`work_item_permissions` JSON). Legacy rows may use global `work_item.*` on `role_permissions`
 * when pivot JSON is null. `work_item.admin` on `role_permissions` still grants full work item access.
 */
final class HelixdeskWorkItemAuthorization
{
    public static function hasWorkItemPermission(User $user, string $action, int $queueId): bool
    {
        if (! $user->isTenantStaff()) {
            return false;
        }

        if ($user->hasPermissionTo('work_item.admin')) {
            return true;
        }

        return in_array($queueId, self::queueIdsForWorkItemAction($user, $action), true);
    }

    /**
     * Agents may open the tickets area if they are work item admins or have at least one readable queue.
     */
    public static function canAccessWorkItemWorkspace(User $user): bool
    {
        if (! $user->isTenantStaff()) {
            return false;
        }

        if ($user->hasPermissionTo('work_item.admin')) {
            return true;
        }

        return self::queueIdsForWorkItemAction($user, 'read') !== [];
    }

    /**
     * @return list<int>
     */
    public static function queueIdsForWorkItemAction(User $user, string $action): array
    {
        $tenantId = $user->effectiveTenantId();

        if ($tenantId === null) {
            return [];
        }

        if ($user->hasPermissionTo('work_item.admin')) {
            return Queue::query()
                ->where('tenant_id', $tenantId)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();
        }
        $guard = config('auth.defaults.guard', 'web');

        $roleIds = DB::table('model_has_roles')
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->where('team_id', $tenantId)
            ->pluck('role_id')
            ->unique()
            ->all();

        $queueIds = [];

        foreach ($roleIds as $roleId) {
            $pairs = DB::table('role_queue')
                ->where('role_id', $roleId)
                ->get(['queue_id', 'work_item_permissions']);

            foreach ($pairs as $pair) {
                if (self::roleQueueGrantsWorkItemAction((int) $roleId, (int) $pair->queue_id, $action, $pair->work_item_permissions, $guard)) {
                    $queueIds[(int) $pair->queue_id] = true;
                }
            }
        }

        return array_keys($queueIds);
    }

    private static function roleQueueGrantsWorkItemAction(int $roleId, int $queueId, string $action, ?string $workItemPermissionsJson, string $guard): bool
    {
        if (DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->where('guard_name', $guard)
            ->where('permission', 'work_item.admin')
            ->exists()) {
            return true;
        }

        if ($workItemPermissionsJson === null || $workItemPermissionsJson === '') {
            return DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('guard_name', $guard)
                ->where('permission', 'work_item.'.$action)
                ->exists();
        }

        $decoded = json_decode($workItemPermissionsJson, true);

        if (! is_array($decoded)) {
            return false;
        }

        // Per-queue "admin" means full work-item access on that queue (read/create/update/delete).
        if (! empty($decoded['admin'])) {
            return true;
        }

        return ! empty($decoded[$action]);
    }

    /**
     * Workflow transitions store permissions such as `work_item.update`; resolve against the work item's queue.
     */
    public static function satisfiesWorkflowPermission(User $user, ?string $permission, WorkItem $workItem): bool
    {
        if ($permission === null) {
            return true;
        }

        if (str_starts_with($permission, 'work_item.')) {
            $action = substr($permission, strlen('work_item.'));

            if ($workItem->queue_id === null) {
                return false;
            }

            return self::hasWorkItemPermission($user, $action, (int) $workItem->queue_id);
        }

        return $user->hasPermissionTo($permission);
    }
}
