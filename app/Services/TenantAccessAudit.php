<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;

final class TenantAccessAudit
{
    public const LOG_NAME = 'tenant_access';

    public const EVENT_ASSIGNED = 'tenant_admin_assigned';

    public const EVENT_ACCESSED = 'tenant_accessed';

    public function logAssignment(Tenant $tenant, User $assignee, ?User $causer = null): void
    {
        activity(self::LOG_NAME)
            ->causedBy($causer ?? auth()->user())
            ->performedOn($tenant)
            ->event(self::EVENT_ASSIGNED)
            ->withProperties([
                'tenant_id' => $tenant->getKey(),
                'assigned_user_id' => $assignee->getKey(),
                'assigned_user_email' => $assignee->email,
                'assignee_is_global_admin' => (bool) $assignee->is_admin,
            ])
            ->log('Tenant Admin role assigned for tenant access.');
    }

    /**
     * Log the first agent-panel access in this session by an assigned global admin.
     */
    public function logAccessIfNeeded(Tenant $tenant, User $user): void
    {
        if (! $user->is_admin || $user->tenant_id !== null) {
            return;
        }

        if (! $user->isTenantAdminFor($tenant)) {
            return;
        }

        $sessionKey = 'tenant_access_logged_'.$tenant->getKey();

        if (session()->get($sessionKey) === true) {
            return;
        }

        session()->put($sessionKey, true);

        activity(self::LOG_NAME)
            ->causedBy($user)
            ->performedOn($tenant)
            ->event(self::EVENT_ACCESSED)
            ->withProperties([
                'tenant_id' => $tenant->getKey(),
                'tenant_slug' => $tenant->slug,
                'user_id' => $user->getKey(),
                'user_email' => $user->email,
            ])
            ->log('Global admin accessed tenant agent panel.');
    }
}
