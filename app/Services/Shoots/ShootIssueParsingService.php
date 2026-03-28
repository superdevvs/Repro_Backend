<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ShootIssueParsingService
{
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

        $notes = explode("\n\n", $shoot->admin_issue_notes);
        foreach ($notes as $index => $note) {
            if (!preg_match('/\[Request from ([^\]]+)\]:\s*(.+)/s', $note, $matches)) {
                continue;
            }

            $raisedByName = $matches[1];
            $fullNoteText = trim($matches[2]);
            $mediaIds = [];
            $noteText = $fullNoteText;

            if (preg_match('/\[MediaIds: ([^\]]+)\]/', $fullNoteText, $mediaMatches)) {
                $mediaIds = array_values(array_filter(array_map('trim', explode(',', $mediaMatches[1]))));
                $noteText = trim(str_replace($mediaMatches[0], '', $fullNoteText));
            }

            $assignedToRole = null;
            if (preg_match('/\[Assigned: (editor|photographer)\]/', $noteText, $assignMatches)) {
                $assignedToRole = $assignMatches[1];
                $noteText = trim(str_replace($assignMatches[0], '', $noteText));
            }

            if (!$assignedToRole && preg_match('/\[Assigned: (editor|photographer)\]/', $shoot->admin_issue_notes, $assignMatches)) {
                $assignedToRole = $assignMatches[1];
            }

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

            $requests[] = [
                'id' => 'req_' . $shoot->id . '_' . $index,
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
                'status' => $shoot->is_flagged ? 'open' : 'resolved',
                'createdAt' => $shoot->updated_at->toISOString(),
                'updatedAt' => $shoot->updated_at->toISOString(),
            ];
        }

        return $requests;
    }

    public function parseClientRequests(iterable $shoots): array
    {
        $requests = [];

        foreach ($shoots as $shoot) {
            foreach ($this->parseShootRequests($shoot) as $request) {
                if (($request['raisedBy']['role'] ?? null) !== 'client') {
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

    public function buildRequestEntry(User $user, string $note, array $mediaIds = []): string
    {
        $requestEntry = '[Request from ' . $user->name . ']: ' . $note;
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

    public function assignIssueRole(Shoot $shoot, string $assignedTo): void
    {
        if (!$shoot->admin_issue_notes) {
            return;
        }

        $notes = preg_replace('/\[Assigned: (editor|photographer)\]/', '', $shoot->admin_issue_notes);
        $shoot->admin_issue_notes = trim((string) $notes) . "\n[Assigned: {$assignedTo}]";
        $shoot->save();
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
