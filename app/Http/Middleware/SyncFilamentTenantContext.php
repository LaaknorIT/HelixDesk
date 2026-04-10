<?php

namespace App\Http\Middleware;

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
        }

        return $next($request);
    }
}
