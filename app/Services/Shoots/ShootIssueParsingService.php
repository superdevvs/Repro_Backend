<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ShootIssueParsingService
{
    protected const REQUEST_STATUS_VALUES = ['open', 'in-progress', 'resolved', 'dismissed'];

    public function __construct(
        protected DropboxWorkflowService $dropboxService,
        protected ShootPaymentStatusSupport $paymentStatusSupport
    ) {
    }

    public function parseShootRequests(Shoot $shoot, ?User $viewer = null): array
    {
        $paymentStatus = $shoot->payment_status;
        if (!$paymentStatus || $paymentStatus === 'pending') {
            $paymentStatus = $this->paymentStatusSupport->calculatePaymentStatus(
                (float) ($shoot->total_paid ?? 0),
                (float) ($shoot->total_quote ?? 0)
            );
        }

        $isClient = strtolower((string) ($viewer?->role ?? '')) === 'client';
        $needsWatermark = $isClient && !$shoot->bypass_paywall && $paymentStatus !== 'paid';
        $requests = [];

        if (!$shoot->admin_issue_notes) {
            return $requests;
        }

        $legacyAssignedRole = null;
        if (preg_match('/\[Assigned: (editor|photographer)\]/', $shoot->admin_issue_notes, $assignMatches)) {
            $legacyAssignedRole = $assignMatches[1];
        }

        $notes = $this->splitRequestEntries($shoot->admin_issue_notes);
        foreach ($notes as $index => $note) {
            $parsedRequest = $this->parseRequestEntry(
                $shoot,
                $note,
                $index,
                $legacyAssignedRole,
            );

            if ($parsedRequest === null) {
                continue;
            }

            if ($this->isAutoExpiredResolvedRequest($parsedRequest, $shoot)) {
                continue;
            }

            $raisedByName = $parsedRequest['raisedByName'];
            $mediaIds = $parsedRequest['mediaIds'];
            $noteText = $parsedRequest['note'];
            $assignedToRole = $parsedRequest['assignedToRole'];
            $assignedToUserId = $parsedRequest['assignedToUserId'];
            $requestStatus = $parsedRequest['status'];

            $mediaFiles = [];
            if (!empty($mediaIds)) {
                $files = ShootFile::whereIn('id', $mediaIds)->get();
                foreach ($files as $file) {
                    $fileUrl = $this->resolveIssuePreviewUrl($file, $needsWatermark, 'web');
                    $thumbnailUrl = $this->resolveIssuePreviewUrl($file, $needsWatermark, 'thumbnail') ?? $fileUrl;

                    $mediaFiles[] = [
                        'id' => (string) $file->id,
                        'filename' => $file->filename ?? $file->stored_filename ?? 'unknown',
                        'url' => $fileUrl,
                        'thumbnail' => $thumbnailUrl,
                    ];
                }
            }

            $raisedByUser = User::where('name', $raisedByName)->first();
            $assignedToUser = $assignedToUserId ? User::find($assignedToUserId) : null;

            $requests[] = [
                'id' => $parsedRequest['id'],
                'shootId' => (string) $shoot->id,
                'note' => $noteText,
                'mediaId' => !empty($mediaIds) ? (string) $mediaIds[0] : null,
                'mediaIds' => array_map('strval', $mediaIds),
                'mediaFiles' => $mediaFiles,
                'raisedBy' => [
                    'id' => $raisedByUser ? (string) $raisedByUser->id : 'unknown',
                    'name' => $raisedByName,
                    'role' => $raisedByUser?->role ?? 'client',
                ],
                'assignedToRole' => $assignedToRole,
                'assignedToUser' => $assignedToUser ? [
                    'id' => (string) $assignedToUser->id,
                    'name' => $assignedToUser->name,
                    'role' => $assignedToUser->role,
                ] : null,
                'status' => $requestStatus,
                'resolvedAt' => $parsedRequest['resolvedAt'],
                'dismissedAt' => $parsedRequest['dismissedAt'],
                'createdAt' => $shoot->updated_at->toISOString(),
                'updatedAt' => $shoot->updated_at->toISOString(),
            ];
        }

        return $requests;
    }

    public function parseClientRequests(iterable $shoots, ?User $viewer = null): array
    {
        $requests = [];
        $viewerRole = strtolower((string) ($viewer?->role ?? ''));

        foreach ($shoots as $shoot) {
            foreach ($this->parseShootRequests($shoot, $viewer) as $request) {
                if (($request['status'] ?? null) === 'dismissed') {
                    continue;
                }
                if (!$this->shouldIncludeDashboardRequest($request, $viewerRole)) {
                    continue;
                }

                $request['shoot'] = [
                    'id' => $shoot->id,
                    'address' => $shoot->address,
                    'client' => $shoot->client ? [
                        'id' => $shoot->client->id,
                        'name' => $shoot->client->name,
                    ] : null,
                ];
                $requests[] = $request;
            }
        }

        return $requests;
    }

    protected function shouldIncludeDashboardRequest(array $request, string $viewerRole): bool
    {
        $assignedToRole = $request['assignedToRole'] ?? null;

        if ($viewerRole === 'photographer') {
            return $assignedToRole === 'photographer';
        }

        if ($viewerRole === 'editor') {
            return $assignedToRole === null || $assignedToRole === 'editor';
        }

        return true;
    }

    public function generateRequestId(Shoot $shoot): string
    {
        return 'req_' . $shoot->id . '_' . Str::lower(Str::random(10));
    }

    public function buildRequestEntry(User $user, string $note, array $mediaIds = [], ?string $requestId = null, string $status = 'open', ?string $assignedToRole = null, ?int $assignedToUserId = null): string
    {
        $requestEntry = '[Request from ' . $user->name . ']: ' . trim($note);
        if ($requestId) {
            $requestEntry .= "\n[RequestId: {$requestId}]";
        }
        if (in_array($status, self::REQUEST_STATUS_VALUES, true)) {
            $requestEntry .= "\n[Status: {$status}]";
        }
        if ($assignedToRole && in_array($assignedToRole, ['editor', 'photographer'], true)) {
            $requestEntry .= "\n[Assigned: {$assignedToRole}]";
        }
        if ($assignedToUserId) {
            $requestEntry .= "\n[AssignedUserId: {$assignedToUserId}]";
        }
        if (!empty($mediaIds)) {
            $requestEntry .= "\n[MediaIds: " . implode(',', $mediaIds) . ']';
        }

        return $requestEntry;
    }

    public function appendIssueRequest(Shoot $shoot, string $requestEntry): void
    {
        if ($shoot->admin_issue_notes) {
            $shoot->admin_issue_notes .= "\n\n" . $requestEntry;
        } else {
            $shoot->admin_issue_notes = $requestEntry;
        }

        $shoot->is_flagged = true;
        $shoot->save();
    }

    public function updateIssueStatus(Shoot $shoot, string $issueId, string $status): ?array
    {
        if (!$shoot->admin_issue_notes || !in_array($status, self::REQUEST_STATUS_VALUES, true)) {
            return null;
        }

        $entries = $this->splitRequestEntries($shoot->admin_issue_notes);
        $updated = false;

        foreach ($entries as $index => $entry) {
            $parsed = $this->parseRequestEntry($shoot, $entry, $index, null);
            if ($parsed === null || $parsed['id'] !== $issueId) {
                continue;
            }

            $parsed['status'] = $status;
            if ($status === 'resolved') {
                $parsed['resolvedAt'] = $parsed['resolvedAt'] ?: now()->toISOString();
                $parsed['dismissedAt'] = null;
            } elseif ($status === 'dismissed') {
                $parsed['dismissedAt'] = now()->toISOString();
            } else {
                $parsed['resolvedAt'] = null;
                $parsed['dismissedAt'] = null;
            }
            $entries[$index] = $this->serializeRequestEntry($parsed);
            $updated = true;
            break;
        }

        if (!$updated) {
            return null;
        }

        $shoot->admin_issue_notes = implode("\n\n", array_filter($entries));
        $shoot->is_flagged = $this->hasOpenRequestsFromEntries($shoot, $entries);
        $shoot->save();

        return collect($this->parseShootRequests($shoot->fresh()))
            ->firstWhere('id', $issueId);
    }

    public function assignIssueRole(Shoot $shoot, string $issueId, string $assignedTo, ?int $assignedToUserId = null): ?array
    {
        if (!$shoot->admin_issue_notes || !in_array($assignedTo, ['editor', 'photographer'], true)) {
            return null;
        }

        $entries = $this->splitRequestEntries($shoot->admin_issue_notes);
        $updated = false;

        foreach ($entries as $index => $entry) {
            $parsed = $this->parseRequestEntry($shoot, $entry, $index, null);
            if ($parsed === null || $parsed['id'] !== $issueId) {
                continue;
            }

            $parsed['assignedToRole'] = $assignedTo;
            $parsed['assignedToUserId'] = $assignedToUserId;
            $entries[$index] = $this->serializeRequestEntry($parsed);
            $updated = true;
            break;
        }

        if (!$updated) {
            return null;
        }

        $shoot->admin_issue_notes = implode("\n\n", array_filter($entries));
        $shoot->is_flagged = $this->hasOpenRequestsFromEntries($shoot, $entries);
        $shoot->save();

        return collect($this->parseShootRequests($shoot->fresh()))
            ->firstWhere('id', $issueId);
    }

    protected function splitRequestEntries(?string $notes): array
    {
        if (!$notes) {
            return [];
        }

        return array_values(array_filter(
            preg_split("/\R{2,}/", trim($notes)) ?: [],
            fn ($entry) => trim((string) $entry) !== ''
        ));
    }

    protected function parseRequestEntry(
        Shoot $shoot,
        string $entry,
        int $index,
        ?string $legacyAssignedRole = null
    ): ?array {
        $lines = preg_split("/\R/", trim($entry)) ?: [];
        if (empty($lines)) {
            return null;
        }

        $firstLine = array_shift($lines);
        if (!preg_match('/^\[Request from ([^\]]+)\]:\s*(.*)$/s', (string) $firstLine, $matches)) {
            return null;
        }

        $raisedByName = trim($matches[1]);
        $noteParts = [];
        $initialNote = trim($matches[2]);
        if ($initialNote !== '') {
            $noteParts[] = $initialNote;
        }

        $mediaIds = [];
        $assignedToRole = null;
        $assignedToUserId = null;
        $requestId = null;
        $status = null;
        $resolvedAt = null;
        $dismissedAt = null;

        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if ($trimmedLine === '') {
                continue;
            }

            if (preg_match('/^\[RequestId:\s*([^\]]+)\]$/', $trimmedLine, $requestIdMatches)) {
                $requestId = trim($requestIdMatches[1]);
                continue;
            }

            if (preg_match('/^\[MediaIds:\s*([^\]]+)\]$/', $trimmedLine, $mediaMatches)) {
                $mediaIds = array_values(array_filter(array_map('trim', explode(',', $mediaMatches[1]))));
                continue;
            }

            if (preg_match('/^\[Assigned:\s*(editor|photographer)\]$/', $trimmedLine, $assignMatches)) {
                $assignedToRole = $assignMatches[1];
                continue;
            }

            if (preg_match('/^\[AssignedUserId:\s*(\d+)\]$/', $trimmedLine, $assignedUserMatches)) {
                $assignedToUserId = (int) $assignedUserMatches[1];
                continue;
            }

            if (preg_match('/^\[Status:\s*(open|in-progress|resolved|dismissed)\]$/', $trimmedLine, $statusMatches)) {
                $status = $statusMatches[1];
                continue;
            }

            if (preg_match('/^\[ResolvedAt:\s*([^\]]+)\]$/', $trimmedLine, $resolvedAtMatches)) {
                $resolvedAt = trim($resolvedAtMatches[1]);
                continue;
            }

            if (preg_match('/^\[DismissedAt:\s*([^\]]+)\]$/', $trimmedLine, $dismissedAtMatches)) {
                $dismissedAt = trim($dismissedAtMatches[1]);
                continue;
            }

            $noteParts[] = $trimmedLine;
        }

        if (!$assignedToRole && !$requestId && $legacyAssignedRole) {
            $assignedToRole = $legacyAssignedRole;
        }

        return [
            'id' => $requestId ?: 'req_' . $shoot->id . '_' . $index,
            'raisedByName' => $raisedByName,
            'note' => trim(implode("\n", $noteParts)),
            'mediaIds' => $mediaIds,
            'assignedToRole' => $assignedToRole,
            'assignedToUserId' => $assignedToUserId,
            'status' => $status ?: ($shoot->is_flagged ? 'open' : 'resolved'),
            'resolvedAt' => $resolvedAt,
            'dismissedAt' => $dismissedAt,
        ];
    }

    protected function serializeRequestEntry(array $request): string
    {
        $entry = '[Request from ' . $request['raisedByName'] . ']: ' . trim((string) $request['note']);

        if (!empty($request['id'])) {
            $entry .= "\n[RequestId: " . $request['id'] . ']';
        }

        if (!empty($request['status']) && in_array($request['status'], self::REQUEST_STATUS_VALUES, true)) {
            $entry .= "\n[Status: " . $request['status'] . ']';
        }

        if (!empty($request['resolvedAt'])) {
            $entry .= "\n[ResolvedAt: " . $request['resolvedAt'] . ']';
        }

        if (!empty($request['dismissedAt'])) {
            $entry .= "\n[DismissedAt: " . $request['dismissedAt'] . ']';
        }

        if (!empty($request['assignedToRole']) && in_array($request['assignedToRole'], ['editor', 'photographer'], true)) {
            $entry .= "\n[Assigned: " . $request['assignedToRole'] . ']';
        }

        if (!empty($request['assignedToUserId'])) {
            $entry .= "\n[AssignedUserId: " . (int) $request['assignedToUserId'] . ']';
        }

        if (!empty($request['mediaIds'])) {
            $entry .= "\n[MediaIds: " . implode(',', array_map('strval', $request['mediaIds'])) . ']';
        }

        return $entry;
    }

    protected function isAutoExpiredResolvedRequest(array $request, Shoot $shoot): bool
    {
        if (($request['status'] ?? null) !== 'resolved') {
            return false;
        }

        $resolvedAt = $request['resolvedAt'] ?: $shoot->updated_at?->toISOString();
        if (!$resolvedAt) {
            return false;
        }

        try {
            return Carbon::parse($resolvedAt)->lte(now()->subHours(24));
        } catch (\Throwable) {
            return false;
        }
    }

    protected function hasOpenRequestsFromEntries(Shoot $shoot, array $entries): bool
    {
        foreach ($entries as $index => $entry) {
            $parsed = $this->parseRequestEntry($shoot, $entry, $index, null);
            if ($parsed !== null && !in_array($parsed['status'], ['resolved', 'dismissed'], true)) {
                return true;
            }
        }

        return false;
    }

    protected function resolveIssuePreviewUrl(ShootFile $file, bool $needsWatermark, string $size): ?string
    {
        if ($needsWatermark) {
            $path = match ($size) {
                'thumbnail' => $file->watermarked_thumbnail_path ?? $file->watermarked_placeholder_path,
                default => $file->watermarked_web_path
                    ?? $file->watermarked_thumbnail_path
                    ?? $file->watermarked_placeholder_path,
            };

            if ($path) {
                return $this->resolvePreviewPath($path);
            }

            if ($file->shouldBeWatermarked()) {
                $this->queueWatermark($file);
            }

            return null;
        }

        $path = match ($size) {
            'thumbnail' => $file->thumbnail_path ?? $file->placeholder_path,
            default => $file->web_path
                ?? $file->thumbnail_path
                ?? $file->placeholder_path,
        };

        return $this->resolvePreviewPath($path);
    }

    protected function resolvePreviewPath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $clean = ltrim($path, '/');
        if (Str::startsWith($clean, 'storage/')) {
            $clean = substr($clean, 8);
        }

        if (Storage::disk('public')->exists($clean)) {
            $encoded = implode('/', array_map('rawurlencode', explode('/', $clean)));
            $url = Storage::disk('public')->url($encoded);
            if (!preg_match('/^https?:\/\//i', $url)) {
                $base = rtrim(config('app.url'), '/');
                $url = $base . '/' . ltrim($url, '/');
            }

            return $url;
        }

        try {
            return $this->dropboxService->getTemporaryLink($path);
        } catch (\Exception $e) {
            Log::warning('Failed to resolve issue preview path', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    protected function queueWatermark(ShootFile $file): void
    {
        try {
            \App\Jobs\GenerateWatermarkedImageJob::dispatch($file->fresh())->onQueue('watermarks');
        } catch (\Exception $e) {
            Log::warning('Failed to queue watermark job for issue preview', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
