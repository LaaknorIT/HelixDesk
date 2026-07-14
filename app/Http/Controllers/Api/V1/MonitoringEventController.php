<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMonitoringEventRequest;
use App\Jobs\ProcessMonitoringEventJob;
use App\Models\MonitoringEvent;
use App\Services\MonitoringEventIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class MonitoringEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null && $user->tenant_id !== null, SymfonyResponse::HTTP_FORBIDDEN);

        setPermissionsTeamId($user->tenant_id);

        try {
            abort_unless($user->hasPermissionTo('monitoring_event.read'), SymfonyResponse::HTTP_FORBIDDEN);
        } finally {
            setPermissionsTeamId(null);
        }

        $events = MonitoringEvent::query()
            ->with('source')
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $events,
        ]);
    }

    public function store(StoreMonitoringEventRequest $request, MonitoringEventIngestionService $ingestion): JsonResponse
    {
        $user = $request->user();

        $event = $ingestion->ingest($request->validated(), $user);

        ProcessMonitoringEventJob::dispatch($event->getKey());

        return response()->json([
            'data' => $event->load('source'),
        ], 201);
    }

    public function show(Request $request, MonitoringEvent $monitoring_event): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null && $user->tenant_id !== null, SymfonyResponse::HTTP_FORBIDDEN);
        abort_unless((int) $monitoring_event->tenant_id === (int) $user->tenant_id, SymfonyResponse::HTTP_FORBIDDEN);

        setPermissionsTeamId($user->tenant_id);

        try {
            abort_unless($user->hasPermissionTo('monitoring_event.read'), SymfonyResponse::HTTP_FORBIDDEN);
        } finally {
            setPermissionsTeamId(null);
        }

        return response()->json([
            'data' => $monitoring_event->load(['source', 'workItems']),
        ]);
    }
}
