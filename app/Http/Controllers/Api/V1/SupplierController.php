<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user?->operatingTenantId();

        abort_unless($user !== null && $tenantId !== null && $user->canOperateInTenant($tenantId), SymfonyResponse::HTTP_FORBIDDEN);

        setPermissionsTeamId($tenantId);

        try {
            abort_unless($user->hasPermissionTo('supplier.read'), SymfonyResponse::HTTP_FORBIDDEN);
        } finally {
            setPermissionsTeamId(null);
        }

        $suppliers = Supplier::query()
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $suppliers,
        ]);
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validated();
        $data['tenant_id'] = $user->operatingTenantId();

        $supplier = Supplier::query()->create($data);

        return response()->json([
            'data' => $supplier,
        ], 201);
    }

    public function show(Request $request, Supplier $supplier): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user !== null && $supplier->tenant_id !== null && $user->canOperateInTenant((int) $supplier->tenant_id),
            SymfonyResponse::HTTP_FORBIDDEN,
        );

        setPermissionsTeamId($supplier->tenant_id);

        try {
            abort_unless($user->hasPermissionTo('supplier.read'), SymfonyResponse::HTTP_FORBIDDEN);
        } finally {
            setPermissionsTeamId(null);
        }

        return response()->json([
            'data' => $supplier->load(['serviceOfferings']),
        ]);
    }
}
