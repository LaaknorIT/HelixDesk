<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkItemMessageRequest;
use App\Http\Requests\StoreWorkItemRequest;
use App\Http\Requests\UpdateWorkItemStatusRequest;
use App\Models\WorkItem;
use App\Services\WorkItemWorkflowService;
use App\Support\HelixdeskWorkItemAuthorization;
use App\Support\WorkItemQueueScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class WorkItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null && $user->canOperateInTenantContext(), SymfonyResponse::HTTP_FORBIDDEN);

        $query = WorkItem::query()->orderByDesc('opened_at');

        if ($user->type !== 'end_user') {
            abort_unless(HelixdeskWorkItemAuthorization::canAccessWorkItemWorkspace($user), SymfonyResponse::HTTP_FORBIDDEN);
        }

        WorkItemQueueScope::applyToWorkItemQuery($query, $user);

        $workItems = $query->get();

        return response()->json([
            'data' => $workItems,
        ]);
    }

    public function store(StoreWorkItemRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null && $user->canOperateInTenantContext(), SymfonyResponse::HTTP_FORBIDDEN);

        $workItem = app(WorkItemWorkflowService::class)->createWorkItem($request->validated(), $user);

        return response()->json([
            'data' => $workItem,
        ], 201);
    }

    public function show(WorkItem $workItem): JsonResponse
    {
        $user = request()->user();

        abort_unless(
            $user !== null && $workItem->tenant_id !== null && $user->canOperateInTenant((int) $workItem->tenant_id),
            SymfonyResponse::HTTP_FORBIDDEN,
        );

        if ($user->type === 'end_user') {
            abort_unless((int) $workItem->reported_by_user_id === (int) $user->id, SymfonyResponse::HTTP_FORBIDDEN);
        } else {
            abort_unless(WorkItemQueueScope::canAccess($user, $workItem), SymfonyResponse::HTTP_FORBIDDEN);
        }

        return response()->json([
            'data' => $workItem,
        ]);
    }

    public function updateStatus(UpdateWorkItemStatusRequest $request, WorkItem $workItem): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user !== null && $workItem->tenant_id !== null && $user->canOperateInTenant((int) $workItem->tenant_id),
            SymfonyResponse::HTTP_FORBIDDEN,
        );

        app(WorkItemWorkflowService::class)->transition(
            $workItem,
            $request->validated()['to_status'],
            $user
        );

        return response()->json([
            'data' => $workItem,
        ]);
    }

    public function storeMessage(StoreWorkItemMessageRequest $request, WorkItem $workItem): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user !== null && $workItem->tenant_id !== null && $user->canOperateInTenant((int) $workItem->tenant_id),
            SymfonyResponse::HTTP_FORBIDDEN,
        );

        $message = app(WorkItemWorkflowService::class)->addMessage(
            $workItem,
            $request->validated()['body'],
            $user,
            $request->validated()['parent_message_id'] ?? null
        );

        return response()->json([
            'data' => $message,
        ], 201);
    }
}
