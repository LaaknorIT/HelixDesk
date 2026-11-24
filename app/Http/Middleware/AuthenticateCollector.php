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

        // The messages say which part is wrong: integration IDs are random UUIDs, so naming a missing one gives
        // nothing away, and it tells an administrator whether the script or the key is the problem.
        if ($integration === null) {
            return response()->json(['message' => 'No integration with this ID. Download the collector again from the integration page.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($request->bearerToken() === null) {
            return response()->json(['message' => 'No collector key was received. A proxy or redirect may have removed the Authorization header.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! $this->keys->matches($integration, $request->bearerToken())) {
            return response()->json(['message' => 'The collector key does not match this integration. Use the key created on this integration\'s page, or create a new one.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! $integration->enabled) {
            return response()->json(['message' => 'This integration is disabled in HelixDesk.'], Response::HTTP_FORBIDDEN);
        }

        $request->attributes->set('collector_integration', $integration);

        return $next($request);
    }
}
