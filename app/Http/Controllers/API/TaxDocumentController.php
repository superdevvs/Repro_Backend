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
        abort_unless($user && $this->policy->upload($user, $user), 403, 'Tax documents are unavailable for this session.');
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
        abort_unless($this->policy->view($request->user(), $user), 403, 'You cannot access this tax document.');
        return response()->json(['tax_document' => $this->documents->summary($user)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function downloadForUser(Request $request, User $user)
    {
        abort_unless($this->policy->view($request->user(), $user), 403, 'You cannot access this tax document.');
        $document = $this->documents->current($user);
        abort_unless($document && $this->documents->validPrivatePath($document), 404, 'Tax document not found.');
        $disk = Storage::disk(TaxDocumentService::DISK);
        abort_unless($disk->exists($document->path), 404, 'Tax document not found.');
        return $disk->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
