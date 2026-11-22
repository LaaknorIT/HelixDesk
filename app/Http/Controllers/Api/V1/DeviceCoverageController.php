<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\DeviceCoverageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class DeviceCoverageController extends Controller
{
    /**
     * GET /api/v1/device-coverage?organization_id=…&only_findings=1
     */
    public function index(Request $request, DeviceCoverageService $service): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user?->operatingTenantId();

        abort_unless($user !== null && $tenantId !== null && $user->canOperateInTenant($tenantId), SymfonyResponse::HTTP_FORBIDDEN);

        setPermissionsTeamId($tenantId);

        try {
            abort_unless($user->hasPermissionTo('asset.read'), SymfonyResponse::HTTP_FORBIDDEN);
        } finally {
            setPermissionsTeamId(null);
        }

        $validated = $request->validate([
            'organization_id' => ['nullable', 'integer'],
            'only_findings' => ['nullable', 'boolean'],
        ]);

        $organizationId = isset($validated['organization_id']) ? (int) $validated['organization_id'] : null;
        if ($organizationId !== null) {
            abort_unless(
                Organization::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($organizationId)->exists(),
                SymfonyResponse::HTTP_NOT_FOUND,
            );
        }

        $report = $service->build($tenantId, $organizationId);
        $rows = ($validated['only_findings'] ?? false) ? $report->rowsWithFindings() : $report->rows;

        return response()->json([
            'data' => [
                'sources' => collect($report->sources)
                    ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])
                    ->values(),
                'rules' => array_map(fn (array $summary): array => [
                    'key' => $summary['rule']->key,
                    'name' => $summary['rule']->name,
                    'likely_cause' => $summary['rule']->likelyCause,
                    'in_scope' => $summary['in_scope'],
                    'missing' => $summary['missing'],
                ], $report->ruleSummaries),
                'devices' => $rows,
            ],
        ]);
    }
}
