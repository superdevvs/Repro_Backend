<?php

namespace App\Services\Legal;

use App\Models\LegalAcceptance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class LegalDocumentService
{
    /** @return array<int, array<string, mixed>> */
    public function documentsForRole(string $role, bool $includeInactive = false): array
    {
        return collect(config('legal_documents.roles.'.strtolower($role), []))
            ->filter(fn (array $document) => $includeInactive || (bool) ($document['active'] ?? false))
            ->map(fn (array $document) => $this->presentDocument($document))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function statusFor(User $user): array
    {
        $activeDocuments = $this->documentsForRole((string) $user->role);
        $draftDocuments = $this->documentsForRole((string) $user->role, true);
        $acceptances = LegalAcceptance::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy(fn (LegalAcceptance $acceptance) => $acceptance->document_key.'@'.$acceptance->document_version);

        $required = collect($activeDocuments)->map(fn (array $document) => [
            'document_key' => $document['document_key'],
            'version' => $document['version'],
        ])->values()->all();
        $accepted = $acceptances->map(fn (LegalAcceptance $acceptance) => [
            'document_key' => $acceptance->document_key,
            'version' => $acceptance->document_version,
            'accepted_at' => $acceptance->accepted_at?->toISOString(),
        ])->values()->all();
        $pending = collect($required)->reject(
            fn (array $document) => $acceptances->has($document['document_key'].'@'.$document['version'])
        )->values()->all();
        $hasInactiveDraft = $activeDocuments === [] && $draftDocuments !== [];

        return [
            'state' => $pending !== [] || $hasInactiveDraft ? 'legal_pending' : 'current',
            'enforcement_active' => $activeDocuments !== [],
            'activation_pending' => $hasInactiveDraft,
            'required' => $required,
            'accepted' => $accepted,
            'pending' => $pending,
            'legacy_terms_accepted' => ! empty(Arr::get((array) $user->metadata, 'terms_accepted_at')),
        ];
    }

    public function accept(User $user, string $documentKey, string $version, Request $request): LegalAcceptance
    {
        $document = collect($this->documentsForRole((string) $user->role))
            ->first(fn (array $candidate) => $candidate['document_key'] === $documentKey && $candidate['version'] === $version);

        if (! $document) {
            throw ValidationException::withMessages([
                'document_key' => ['This document and version is not currently advertised for your account role.'],
            ]);
        }

        return LegalAcceptance::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'document_key' => $documentKey,
                'document_version' => $version,
            ],
            [
                'role_at_acceptance' => (string) $user->role,
                'content_hash' => $document['content_hash'],
                'effective_date' => $document['effective_date'],
                'accepted_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000),
                'audit_metadata' => [
                    'acceptance_source' => 'authenticated_api',
                    'request_id' => $request->headers->get('X-Request-ID'),
                ],
            ]
        );
    }

    /** @return array<string, mixed> */
    private function presentDocument(array $document): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", trim((string) ($document['content'] ?? '')));

        return [
            'document_key' => (string) $document['key'],
            'title' => (string) $document['title'],
            'version' => (string) $document['version'],
            'effective_date' => $document['effective_date'] ?? null,
            'active' => (bool) ($document['active'] ?? false),
            'content' => $content,
            'content_hash' => hash('sha256', $content),
        ];
    }
}
