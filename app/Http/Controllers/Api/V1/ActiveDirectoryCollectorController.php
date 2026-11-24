<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CollectorRun;
use App\Models\OrganizationIntegration;
use App\Services\ActiveDirectory\ActiveDirectoryInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints the on-premises Active Directory collector calls. Authenticated by AuthenticateCollector.
 */
class ActiveDirectoryCollectorController extends Controller
{
    public const MAX_OBJECTS_PER_BATCH = 200;

    public function __construct(private readonly ActiveDirectoryInventoryService $inventory) {}

    public function config(Request $request): JsonResponse
    {
        $integration = $this->integration($request);
        $config = $integration->mergedConfig();

        return response()->json([
            'data' => [
                'domain_fqdn' => $config['domain_fqdn'] ?? null,
                'search_bases' => array_values((array) ($config['search_bases'] ?? [])),
                'excluded_ous' => array_values((array) ($config['excluded_ous'] ?? [])),
                'sync_interval_hours' => (int) ($config['sync_interval_hours'] ?? 4),
                'max_objects_per_batch' => self::MAX_OBJECTS_PER_BATCH,
            ],
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $integration = $this->integration($request);
        $validated = $request->validate([
            'collector_version' => ['nullable', 'string', 'max:64'],
            'host' => ['nullable', 'string', 'max:255'],
            'last_error' => ['nullable', 'string', 'max:2000'],
        ]);

        $integration->mergeHealthMetadata(['collector' => [
            'version' => $validated['collector_version'] ?? null,
            'host' => $validated['host'] ?? null,
            'last_error' => $validated['last_error'] ?? null,
            'last_heartbeat_at' => now()->toIso8601String(),
        ]]);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function startRun(Request $request): JsonResponse
    {
        $integration = $this->integration($request);
        $validated = $request->validate([
            'collector_version' => ['nullable', 'string', 'max:64'],
            'host' => ['nullable', 'string', 'max:255'],
        ]);

        $run = $this->inventory->startRun($integration, $validated['collector_version'] ?? null, $validated['host'] ?? null);

        return response()->json(['data' => ['run_id' => $run->getKey()]], Response::HTTP_CREATED);
    }

    public function storeObjects(Request $request, string $integration, string $run): JsonResponse
    {
        $model = $this->integration($request);
        $collectorRun = $this->runningRun($model, $run);

        $validated = $request->validate([
            'sequence' => ['required', 'integer', 'min:0'],
            'objects' => ['present', 'array', 'max:'.self::MAX_OBJECTS_PER_BATCH],
            'objects.*.object_guid' => ['required', 'string', 'max:64'],
            'objects.*.object_sid' => ['nullable', 'string', 'max:255'],
            'objects.*.name' => ['nullable', 'string', 'max:255'],
            'objects.*.dns_host_name' => ['nullable', 'string', 'max:255'],
            'objects.*.operating_system' => ['nullable', 'string', 'max:255'],
            'objects.*.operating_system_version' => ['nullable', 'string', 'max:255'],
            'objects.*.enabled' => ['nullable', 'boolean'],
            'objects.*.is_domain_controller' => ['nullable', 'boolean'],
            'objects.*.last_logon_timestamp' => ['nullable', 'string', 'max:64'],
            'objects.*.pwd_last_set' => ['nullable', 'string', 'max:64'],
            'objects.*.when_created' => ['nullable', 'string', 'max:64'],
            'objects.*.distinguished_name' => ['nullable', 'string', 'max:1000'],
            'objects.*.description' => ['nullable', 'string', 'max:1000'],
        ]);

        $stored = $this->inventory->ingestBatch($model, $collectorRun, (int) $validated['sequence'], $validated['objects']);
        $collectorRun->refresh();

        return response()->json(['data' => [
            'stored' => $stored,
            'received_total' => $collectorRun->received_count,
            'skipped_total' => $collectorRun->skipped_count,
        ]]);
    }

    public function completeRun(Request $request, string $integration, string $run): JsonResponse
    {
        $model = $this->integration($request);
        $collectorRun = $this->runningRun($model, $run);

        $validated = $request->validate([
            'object_count' => ['required', 'integer', 'min:0'],
        ]);

        $collectorRun = $this->inventory->completeRun($model, $collectorRun, (int) $validated['object_count']);

        return response()->json(['data' => [
            'status' => $collectorRun->status,
            'received_total' => $collectorRun->received_count,
            'skipped_total' => $collectorRun->skipped_count,
        ]]);
    }

    private function integration(Request $request): OrganizationIntegration
    {
        return $request->attributes->get('collector_integration');
    }

    private function runningRun(OrganizationIntegration $integration, string $runId): CollectorRun
    {
        $run = CollectorRun::query()
            ->withoutGlobalScopes()
            ->where('organization_integration_id', $integration->getKey())
            ->whereKey($runId)
            ->first();

        abort_if($run === null, Response::HTTP_NOT_FOUND, 'Unknown run.');
        abort_if($run->status !== CollectorRun::STATUS_RUNNING, Response::HTTP_CONFLICT, 'This run is no longer running. Start a new run.');

        return $run;
    }
}
