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

        abort_unless($user !== null && $user->tenant_id !== null, SymfonyResponse::HTTP_FORBIDDEN);

        setPermissionsTeamId($user->tenant_id);

        try {
            abort_unless($user->hasPermissionTo('service_offering.read'), SymfonyResponse::HTTP_FORBIDDEN);
        } finally {
            setPermissionsTeamId(null);
        }

        $offerings = ServiceOffering::query()
            ->with(['organization'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $offerings,
        ]);
    }

    public function store(StoreServiceOfferingRequest $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validated();
        $data['tenant_id'] = $user->tenant_id;

        $offering = ServiceOffering::query()->create($data);

        return response()->json([
            'data' => $offering->load(['organization']),
        ], 201);
    }

    public function show(Request $request, ServiceOffering $serviceOffering): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null && $user->tenant_id !== null, SymfonyResponse::HTTP_FORBIDDEN);
        abort_unless((int) $serviceOffering->tenant_id === (int) $user->tenant_id, SymfonyResponse::HTTP_FORBIDDEN);

        setPermissionsTeamId($user->tenant_id);

        try {
            abort_unless($user->hasPermissionTo('service_offering.read'), SymfonyResponse::HTTP_FORBIDDEN);
        } finally {
            setPermissionsTeamId(null);
        }

        return response()->json([
            'data' => $serviceOffering->load([
                'organization',
                'suppliers',
                'realizationAssets.assetType',
            ]),
        ]);
    }
}
