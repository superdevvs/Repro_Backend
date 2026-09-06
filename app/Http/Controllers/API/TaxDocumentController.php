<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Policies\TaxDocumentPolicy;
use App\Services\TaxDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TaxDocumentController extends Controller
{
    public function __construct(private TaxDocumentService $documents, private TaxDocumentPolicy $policy) {}

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user || !$this->policy->upload($user, $user)) {
            throw new \App\Exceptions\PublicApiException('Tax documents are unavailable for this session.', 'tax_document_session_unavailable', 403);
        }
        $validated = $request->validate([
            'document' => 'required|file|mimes:pdf,png,jpg,jpeg|max:10240',
            'notes' => 'nullable|string|max:1000',
        ]);
        $this->documents->store($user, $validated['document'], $validated['notes'] ?? null);
        return response()->json([
            'message' => 'Tax document submitted successfully', 'user' => $user->fresh(),
            'tax_document' => $this->documents->summary($user),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request) { return $this->showForUser($request, $request->user()); }
    public function download(Request $request) { return $this->downloadForUser($request, $request->user()); }

    public function showForUser(Request $request, User $user)
    {
        if (!$this->policy->view($request->user(), $user)) {
            throw new \App\Exceptions\PublicApiException('You cannot access this tax document.', 'tax_document_forbidden', 403);
        }
        return response()->json(['tax_document' => $this->documents->summary($user)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function downloadForUser(Request $request, User $user)
    {
        if (!$this->policy->view($request->user(), $user)) {
            throw new \App\Exceptions\PublicApiException('You cannot access this tax document.', 'tax_document_forbidden', 403);
        }
        $document = $this->documents->current($user);
        if (!$document || !$this->documents->validPrivatePath($document)) {
            throw new \App\Exceptions\PublicApiException('Tax document not found.', 'tax_document_not_found', 404);
        }
        $disk = Storage::disk(TaxDocumentService::DISK);
        if (!$disk->exists($document->path)) {
            throw new \App\Exceptions\PublicApiException('Tax document not found.', 'tax_document_not_found', 404);
        }
        return $disk->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
