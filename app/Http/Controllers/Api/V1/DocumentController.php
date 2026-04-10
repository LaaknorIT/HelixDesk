<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentRequest;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null && $user->tenant_id !== null, SymfonyResponse::HTTP_FORBIDDEN);

        setPermissionsTeamId($user->tenant_id);

        try {
            abort_unless($user->hasPermissionTo('document.read'), SymfonyResponse::HTTP_FORBIDDEN);
        } finally {
            setPermissionsTeamId(null);
        }

        $query = Document::query()->with('owner')->orderByDesc('updated_at');

        if ($request->filled('doc_type')) {
            $query->where('doc_type', $request->query('doc_type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json([
            'data' => $query->limit(200)->get(),
        ]);
    }

    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $document = Document::query()->create($request->validatedForCreate());

        return response()->json([
            'data' => $document->load('owner'),
        ], 201);
    }

    public function show(Request $request, Document $document): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null && $user->tenant_id !== null, SymfonyResponse::HTTP_FORBIDDEN);
        abort_unless((int) $document->tenant_id === (int) $user->tenant_id, SymfonyResponse::HTTP_FORBIDDEN);

        setPermissionsTeamId($user->tenant_id);

        try {
            abort_unless($user->hasPermissionTo('document.read'), SymfonyResponse::HTTP_FORBIDDEN);
        } finally {
            setPermissionsTeamId(null);
        }

        return response()->json([
            'data' => $document->load([
                'owner',
                'incidents',
                'serviceOfferings',
                'suppliers',
                'assets',
            ]),
        ]);
    }
}
