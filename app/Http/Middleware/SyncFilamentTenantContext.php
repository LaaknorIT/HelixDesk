<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantAccessAudit;
use App\Services\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SyncFilamentTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant !== null) {
            app(TenantContext::class)->setTenantId($tenant->getKey());
            setPermissionsTeamId($tenant->getKey());

            $user = $request->user();

            if ($user !== null && $tenant instanceof Tenant) {
                app(TenantAccessAudit::class)->logAccessIfNeeded($tenant, $user);
            }
        }

        return $next($request);
    }
}
