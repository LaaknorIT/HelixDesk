<?php

namespace App\Services;

use App\Models\TenantRole;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final class RolePermissionMatrixSync
{
    /**
     * @param  array<string, array<string, bool>>  $globalMatrix  module => action => on
     * @param  list<array{queue_id: int|string|null, actions: array<string, bool>}>  $workItemQueueRows
     */
    public static function sync(TenantRole $role, array $globalMatrix, array $workItemQueueRows, ?string $guardName = null): void
    {
        $guardName ??= config('auth.defaults.guard', 'web');

        $names = [];

        foreach ($globalMatrix as $module => $actions) {
            foreach ($actions as $action => $on) {
                if ($on) {
                    $names[] = $module.'.'.$action;
                }
            }
        }

        // The role UI configures work items per queue only. Re-attach work_item.admin when
        // every linked queue grants Admin so Tenant Admin keeps tenant-wide ticket access
        // after save (seeded roles store work_item.admin on role_permissions).
        $queueIds = [];
        $sync = [];
        $queueCount = 0;
        $adminQueueCount = 0;

        foreach ($workItemQueueRows as $row) {
            $queueId = isset($row['queue_id']) ? (int) $row['queue_id'] : 0;

            if ($queueId <= 0) {
                continue;
            }

            if (isset($queueIds[$queueId])) {
                throw ValidationException::withMessages([
                    'work_item_queue_permissions' => __('Each queue may only appear once.'),
                ]);
            }

            $queueIds[$queueId] = true;
            $queueCount++;

            $actions = self::normalizeWorkItemActions($row['actions'] ?? []);
            if (! empty($actions['admin'])) {
                $adminQueueCount++;
            }

            $sync[$queueId] = [
                'work_item_permissions' => json_encode($actions),
            ];
        }

        if ($queueCount > 0 && $adminQueueCount === $queueCount) {
            $names[] = 'work_item.admin';
        }

        RolePermissionSync::sync($role, $names, $guardName);

        $role->queues()->sync($sync);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<string, bool>  $actions
     * @return array<string, bool>
     */
    public static function normalizeWorkItemActions(array $actions): array
    {
        $keys = config('helixdesk_permissions.modules.work_item.actions', []);
        $out = array_fill_keys($keys, false);

        foreach ($keys as $key) {
            if (! empty($actions[$key])) {
                $out[$key] = true;
            }
        }

        return $out;
    }
}
