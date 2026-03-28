<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTenantRequest;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $tenants = Tenant::query()
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'status', 'billing_mode', 'region', 'created_at']);

        return response()->json([
            'data' => $tenants,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTenantRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $tenant = Tenant::query()->create([
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? Str::slug($validated['name']),
            'status' => $validated['status'] ?? 'active',
            'billing_mode' => $validated['billing_mode'] ?? 'Stripe',
            'region' => $validated['region'] ?? 'eu',
        ]);

        return response()->json([
            'data' => $tenant->only(['id', 'name', 'slug', 'status', 'billing_mode', 'region', 'created_at']),
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($id);

        return response()->json([
            'data' => $tenant->only(['id', 'name', 'slug', 'status', 'billing_mode', 'region', 'created_at']),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(string $id): JsonResponse
    {
        return response()->json([
            'message' => 'Not implemented yet.',
        ], 501);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        return response()->json([
            'message' => 'Not implemented yet.',
        ], 501);
    }
}
