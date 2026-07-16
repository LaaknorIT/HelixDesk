<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class TenantAdminAssignmentService
{
    public function eligibleCandidates(Tenant $tenant): Collection
    {
        $tenantAgents = User::query()
            ->where('type', 'agent')
            ->where('tenant_id', $tenant->getKey())
            ->get();

        $globalAdmins = User::query()
            ->where('is_admin', true)
            ->get();

        return $tenantAgents
            ->concat($globalAdmins)
            ->unique(fn (User $user): int => (int) $user->getKey())
            ->reject(fn (User $user): bool => $this->isTenantAdmin($user, $tenant))
            ->values();
    }

    public function isTenantAdmin(User $user, Tenant $tenant): bool
    {
        $roleName = config('helixdesk_permissions.tenant_roles.tenant_admin.name');

        setPermissionsTeamId($tenant->getKey());

        try {
            return $user->hasRole($roleName);
        } finally {
            setPermissionsTeamId(null);
        }
    }

    public function assign(Tenant $tenant, User $user): void
    {
        if ($this->isTenantAdmin($user, $tenant)) {
            throw ValidationException::withMessages([
                'user_id' => __('This user is already a tenant administrator for this tenant.'),
            ]);
        }

        if (! $this->isEligibleCandidate($user, $tenant)) {
            throw ValidationException::withMessages([
                'user_id' => __('This user cannot be assigned as tenant administrator.'),
            ]);
        }

        $roleName = config('helixdesk_permissions.tenant_roles.tenant_admin.name');

        setPermissionsTeamId($tenant->getKey());

        try {
            $user->assignRole($roleName);
        } finally {
            setPermissionsTeamId(null);
        }

        app(TenantAccessAudit::class)->logAssignment($tenant, $user);
    }

    public function isEligibleCandidate(User $user, Tenant $tenant): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return $user->type === 'agent' && (int) $user->tenant_id === (int) $tenant->getKey();
    }
}
