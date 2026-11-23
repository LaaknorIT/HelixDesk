<?php

namespace App\Http\Middleware;

use App\Models\OrganizationIntegration;
use App\Services\ActiveDirectory\CollectorKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an on-premises collector with its integration's collector key (Bearer token). The key only gives
 * access to that one integration's collector endpoints; it cannot read anything else.
 */
class AuthenticateCollector
{
    public function __construct(private readonly CollectorKeyService $keys) {}

    public function handle(Request $request, Closure $next, string $integrationType): Response
    {
        $integrationId = (string) $request->route('integration');

        $integration = OrganizationIntegration::query()
            ->withoutGlobalScopes()
            ->whereKey($integrationId)
            ->where('type', $integrationType)
            ->first();

        if ($integration === null || ! $this->keys->matches($integration, $request->bearerToken())) {
            return response()->json(['message' => 'Invalid integration or collector key.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! $integration->enabled) {
            return response()->json(['message' => 'This integration is disabled in HelixDesk.'], Response::HTTP_FORBIDDEN);
        }

        $request->attributes->set('collector_integration', $integration);

        return $next($request);
    }
}
