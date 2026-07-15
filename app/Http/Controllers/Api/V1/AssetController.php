<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssetRequest;
use App\Models\Asset;
use App\Support\AssetApiIncludes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AssetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null && $user->tenant_id !== null, SymfonyResponse::HTTP_FORBIDDEN);

        setPermissionsTeamId($user->tenant_id);

        try {
            abort_unless($user->hasPermissionTo('asset.read'), SymfonyResponse::HTTP_FORBIDDEN);
        } finally {
            setPermissionsTeamId(null);
        }

        $includes = AssetApiIncludes::parse($request->query('include'));

        $assets = Asset::query()
            ->with(['assetType', 'organization'])
            ->orderBy('name')
            ->get();

        AssetApiIncludes::apply($assets, $includes);

        return response()->json([
            'data' => $assets,
        ]);
    }

    public function store(StoreAssetRequest $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validated();
        $data['tenant_id'] = $user->tenant_id;
        $data['lifecycle_status'] = $data['lifecycle_status'] ?? 'in_use';

        $asset = Asset::query()->create($data);

        return response()->json([
            'data' => $asset->load(['assetType', 'organization']),
        ], 201);
    }

    public function show(Request $request, Asset $asset): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null && $user->tenant_id !== null, SymfonyResponse::HTTP_FORBIDDEN);
        abort_unless((int) $asset->tenant_id === (int) $user->tenant_id, SymfonyResponse::HTTP_FORBIDDEN);

        setPermissionsTeamId($user->tenant_id);

        try {
            abort_unless($user->hasPermissionTo('asset.read'), SymfonyResponse::HTTP_FORBIDDEN);
        } finally {
            setPermissionsTeamId(null);
        }

        $includes = AssetApiIncludes::parse($request->query('include'));

        $asset->load(['assetType', 'organization', 'owner']);
        AssetApiIncludes::apply($asset, $includes);

        return response()->json([
            'data' => $asset,
        ]);
    }
}
