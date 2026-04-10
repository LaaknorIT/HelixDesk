<?php

namespace App\Support;

use App\Models\Incident;
use App\Models\Queue;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Incident module permissions are stored per role+queue on {@see TenantRole::queues()}
 * pivot (`incident_permissions` JSON). Legacy rows may use global `incident.*` on `role_permissions`
 * when pivot JSON is null. `incident.admin` on `role_permissions` still grants full incident access.
 */
final class HelixdeskIncidentAuthorization
{
    public static function hasIncidentPermission(User $user, string $action, int $queueId): bool
    {
        if ($user->tenant_id === null || $user->type !== 'agent') {
            return false;
        }

        if ($user->hasPermissionTo('incident.admin')) {
            return true;
        }

        return in_array($queueId, self::queueIdsForIncidentAction($user, $action), true);
    }

    /**
     * Agents may open the incidents area if they are incident admins or have at least one readable queue.
     */
    public static function canAccessIncidentWorkspace(User $user): bool
    {
        if ($user->tenant_id === null || $user->type !== 'agent') {
            return false;
        }

        if ($user->hasPermissionTo('incident.admin')) {
            return true;
        }

        return self::queueIdsForIncidentAction($user, 'read') !== [];
    }

    /**
     * @return list<int>
     */
    public static function queueIdsForIncidentAction(User $user, string $action): array
    {
        if ($user->tenant_id === null) {
            return [];
        }

        if ($user->hasPermissionTo('incident.admin')) {
            return Queue::query()
                ->where('tenant_id', $user->tenant_id)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();
        }

        $tenantId = (int) $user->tenant_id;
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
                ->get(['queue_id', 'incident_permissions']);

            foreach ($pairs as $pair) {
                if (self::roleQueueGrantsIncidentAction((int) $roleId, (int) $pair->queue_id, $action, $pair->incident_permissions, $guard)) {
                    $queueIds[(int) $pair->queue_id] = true;
                }
            }
        }

        return array_keys($queueIds);
    }

    private static function roleQueueGrantsIncidentAction(int $roleId, int $queueId, string $action, ?string $incidentPermissionsJson, string $guard): bool
    {
        if (DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->where('guard_name', $guard)
            ->where('permission', 'incident.admin')
            ->exists()) {
            return true;
        }

        if ($incidentPermissionsJson === null || $incidentPermissionsJson === '') {
            return DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('guard_name', $guard)
                ->where('permission', 'incident.'.$action)
                ->exists();
        }

        $decoded = json_decode($incidentPermissionsJson, true);

        if (! is_array($decoded)) {
            return false;
        }

        return ! empty($decoded[$action]);
    }

    /**
     * Workflow transitions store permissions such as `incident.update`; resolve against the incident's queue.
     */
    public static function satisfiesWorkflowPermission(User $user, ?string $permission, Incident $incident): bool
    {
        if ($permission === null) {
            return true;
        }

        if (str_starts_with($permission, 'incident.')) {
            $action = substr($permission, strlen('incident.'));

            if ($incident->queue_id === null) {
                return false;
            }

            return self::hasIncidentPermission($user, $action, (int) $incident->queue_id);
        }

        return $user->hasPermissionTo($permission);
    }
}
