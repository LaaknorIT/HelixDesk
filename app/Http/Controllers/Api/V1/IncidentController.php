<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIncidentMessageRequest;
use App\Http\Requests\StoreIncidentRequest;
use App\Http\Requests\UpdateIncidentStatusRequest;
use App\Models\Incident;
use App\Services\IncidentWorkflowService;
use App\Support\HelixdeskIncidentAuthorization;
use App\Support\IncidentQueueScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class IncidentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null && $user->tenant_id !== null, SymfonyResponse::HTTP_FORBIDDEN);

        $query = Incident::query()->orderByDesc('opened_at');

        if ($user->type !== 'end_user') {
            abort_unless(HelixdeskIncidentAuthorization::canAccessIncidentWorkspace($user), SymfonyResponse::HTTP_FORBIDDEN);
        }

        IncidentQueueScope::applyToIncidentQuery($query, $user);

        $incidents = $query->get();

        return response()->json([
            'data' => $incidents,
        ]);
    }

    public function store(StoreIncidentRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null && $user->tenant_id !== null, SymfonyResponse::HTTP_FORBIDDEN);

        $incident = app(IncidentWorkflowService::class)->createIncident($request->validated(), $user);

        return response()->json([
            'data' => $incident,
        ], 201);
    }

    public function show(Incident $incident): JsonResponse
    {
        $user = request()->user();

        abort_unless($user !== null && $user->tenant_id !== null, SymfonyResponse::HTTP_FORBIDDEN);

        if ($user->type === 'end_user') {
            abort_unless((int) $incident->reported_by_user_id === (int) $user->id, SymfonyResponse::HTTP_FORBIDDEN);
        } else {
            abort_unless(IncidentQueueScope::canAccess($user, $incident), SymfonyResponse::HTTP_FORBIDDEN);
        }

        return response()->json([
            'data' => $incident,
        ]);
    }

    public function updateStatus(UpdateIncidentStatusRequest $request, Incident $incident): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null && $user->tenant_id !== null, SymfonyResponse::HTTP_FORBIDDEN);

        app(IncidentWorkflowService::class)->transition(
            $incident,
            $request->validated()['to_status'],
            $user
        );

        return response()->json([
            'data' => $incident,
        ]);
    }

    public function storeMessage(StoreIncidentMessageRequest $request, Incident $incident): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null && $user->tenant_id !== null, SymfonyResponse::HTTP_FORBIDDEN);

        $message = app(IncidentWorkflowService::class)->addMessage(
            $incident,
            $request->validated()['body'],
            $user,
            $request->validated()['parent_message_id'] ?? null
        );

        return response()->json([
            'data' => $message,
        ], 201);
    }
}
