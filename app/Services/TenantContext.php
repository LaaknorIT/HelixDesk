<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TenantContext
{
    /**
     * Optional override set by code (e.g. middleware or background jobs).
     */
    private int|string|null $tenantId = null;

    public function __construct(private Request $request) {}

    public function setTenantId(int|string|null $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    /**
     * Resolve the current tenant id from:
     * - the authenticated user (preferred)
     * - an explicit request header (`X-Tenant-Id`) as a fallback for API/testing
     */
    public function tenantId(): int|string|null
    {
        if ($this->tenantId !== null) {
            return $this->tenantId;
        }

        $user = Auth::user();

        if ($user && isset($user->tenant_id)) {
            return $user->tenant_id;
        }

        $headerTenantId = $this->request->header('X-Tenant-Id');

        if ($headerTenantId !== null && $headerTenantId !== '') {
            return (int) $headerTenantId;
        }

        return null;
    }
}
