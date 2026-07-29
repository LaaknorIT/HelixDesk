<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServiceOfferingRequest;
use App\Models\ServiceOffering;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ServiceOfferingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user?->operatingTenantId();

        abort_unless($user !== null && $tenantId !== null && $user->canOperateInTenant($tenantId), SymfonyResponse::HTTP_FORBIDDEN);

        setPermissionsTeamId($tenantId);

        try {
            abort_unless($user->hasPermissionTo('service_offering.read'), SymfonyResponse::HTTP_FORBIDDEN);
        } finally {
            setPermissionsTeamId(null);
        }

        $offerings = ServiceOffering::query()
            ->with(['organizations', 'softwareDeployments'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $offerings,
        ]);
    }

    public function store(StoreServiceOfferingRequest $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->safe()->except(['organization_ids', 'organization_id', 'software_deployment_ids']);
        $data['tenant_id'] = $user->operatingTenantId();

        $offering = ServiceOffering::query()->create($data);
        $offering->syncOrganizations($request->validated('organization_ids'));

        if ($request->filled('software_deployment_ids')) {
            $offering->softwareDeployments()->sync($request->validated('software_deployment_ids'));
        }

        return response()->json([
            'data' => $offering->load(['organizations', 'softwareDeployments']),
        ], 201);
    }

    public function show(Request $request, ServiceOffering $serviceOffering): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user !== null && $serviceOffering->tenant_id !== null && $user->canOperateInTenant((int) $serviceOffering->tenant_id),
            SymfonyResponse::HTTP_FORBIDDEN,
        );

        setPermissionsTeamId($serviceOffering->tenant_id);

        try {
            abort_unless($user->hasPermissionTo('service_offering.read'), SymfonyResponse::HTTP_FORBIDDEN);
        } finally {
            setPermissionsTeamId(null);
        }

        return response()->json([
            'data' => $serviceOffering->load([
                'organizations',
                'suppliers',
                'realizationAssets.assetType',
                'softwareDeployments.softwareProduct',
            ]),
        ]);
    }
}
