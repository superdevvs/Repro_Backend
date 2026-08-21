<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Legal\LegalDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegalDocumentController extends Controller
{
    public function current(Request $request, LegalDocumentService $documents): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'documents' => $documents->documentsForRole((string) $user->role),
            'status' => $documents->statusFor($user),
        ]);
    }

    public function accept(Request $request, LegalDocumentService $documents): JsonResponse
    {
        $validated = $request->validate([
            'document_key' => ['required', 'string', 'max:100'],
            'version' => ['required', 'string', 'max:100'],
        ]);

        $acceptance = $documents->accept(
            $request->user(),
            $validated['document_key'],
            $validated['version'],
            $request
        );

        return response()->json([
            'acceptance' => [
                'document_key' => $acceptance->document_key,
                'version' => $acceptance->document_version,
                'accepted_at' => $acceptance->accepted_at?->toISOString(),
            ],
            'status' => $documents->statusFor($request->user()),
        ], $acceptance->wasRecentlyCreated ? 201 : 200);
    }
}
