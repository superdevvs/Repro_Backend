<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserTaxDocument;
use App\Support\TaxDocumentMetadata;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class TaxDocumentLegacyMigration
{
    public function __construct(private TaxDocumentService $documents) {}

    /** Aggregate-only output. The command never prints file names, paths, notes or document contents. */
    public function run(bool $apply = false): array
    {
        $report = array_fill_keys(['users_scanned', 'eligible', 'migrated', 'already_private', 'missing', 'invalid_paths', 'conflicts', 'orphan_files', 'failures', 'public_copies_removed'], 0);
        $referenced = [];
        User::withTrashed()->orderBy('id')->chunkById(100, function ($users) use ($apply, &$report, &$referenced) {
            foreach ($users as $user) {
                $report['users_scanned']++;
                $legacy = $user->metadata['tax_document_path'] ?? null;
                $current = $this->documents->current($user);
                $legacy = $current?->legacy_public_path ?: $legacy;
                if (!$legacy) {
                    if ($current) { $report['already_private']++; }
                    continue;
                }
                if (!is_string($legacy) || !$this->validLegacyPath($legacy, $user->id)) {
                    $report['invalid_paths']++;
                    continue;
                }
                $referenced[$legacy] = true;
                try {
                    $outcome = $this->documents->locked($user->id, fn () => $this->migrateOne($user, $legacy, $apply));
                    foreach ($outcome as $key => $value) { $report[$key] += $value; }
                } catch (\Throwable $exception) {
                    $report['failures']++;
                    Log::warning('Legacy tax document migration needs attention.', ['user_id' => $user->id, 'exception' => $exception::class]);
                }
            }
        });
        foreach (Storage::disk('public')->allFiles('tax-documents') as $path) {
            if (!isset($referenced[$path])) { $report['orphan_files']++; }
        }
        return $report;
    }

    private function migrateOne(User $user, string $source, bool $apply): array
    {
        $user = $user->fresh();
        $existing = $this->documents->current($user);
        if ($existing && $existing->legacy_public_path !== $source) { return ['conflicts' => 1]; }
        if ($existing) {
            if (!$this->documents->validPrivatePath($existing)
                || !Storage::disk(TaxDocumentService::DISK)->exists($existing->path)
                || !hash_equals($existing->sha256, $this->documents->checksum(TaxDocumentService::DISK, $existing->path))) {
                return ['conflicts' => 1];
            }
            if (!$apply) { return ['already_private' => 1]; }
            $removed = $this->finish($user, $existing, $source);
            return ['already_private' => 1, 'public_copies_removed' => $removed];
        }
        if (($user->metadata['tax_document_path'] ?? null) !== $source) { return ['conflicts' => 1]; }
        $public = Storage::disk('public');
        if (!$public->exists($source)) { return ['missing' => 1]; }
        if (!$this->safeLocalSource($source) || $public->size($source) > 10 * 1024 * 1024) { return ['conflicts' => 1]; }
        $mime = $public->mimeType($source);
        $allowed = TaxDocumentService::MIME_EXTENSIONS + [
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ];
        if (!isset($allowed[$mime])) { return ['conflicts' => 1]; }
        if (!$apply) { return ['eligible' => 1]; }

        $extension = $allowed[$mime];
        $destination = $user->id.'/'.Str::uuid().'.'.$extension;
        $private = Storage::disk(TaxDocumentService::DISK);
        $committed = false;
        try {
            $sourceHash = $this->documents->checksum('public', $source);
            $size = $public->size($source);
            $stream = $public->readStream($source);
            if (!is_resource($stream)) { throw new RuntimeException('Legacy tax document could not be read.'); }
            try { $written = $private->put($destination, $stream); }
            finally { fclose($stream); }
            if (!$written || $private->size($destination) !== $size
                || !hash_equals($sourceHash, $this->documents->checksum(TaxDocumentService::DISK, $destination))) {
                throw new RuntimeException('Private tax document copy verification failed.');
            }
            $document = DB::transaction(function () use ($user, $source, $destination, $extension, $mime, $size, $sourceHash) {
                $owner = User::withTrashed()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (($owner->metadata['tax_document_path'] ?? null) !== $source || $this->documents->current($owner)) {
                    throw new RuntimeException('Tax document changed during migration.');
                }
                return UserTaxDocument::create([
                    'user_id' => $owner->id, 'path' => $destination, 'legacy_public_path' => $source,
                    'original_name' => $this->documents->safeName((string) ($owner->metadata['tax_document_name'] ?? basename($source)), $extension),
                    'mime_type' => $mime, 'size' => $size, 'sha256' => $sourceHash,
                    'notes' => is_string($owner->metadata['tax_document_notes'] ?? null) ? $owner->metadata['tax_document_notes'] : null,
                    'submitted_at' => $this->submittedAt($owner),
                ]);
            });
            $committed = true;
            $removed = $this->finish($user, $document, $source);
            return ['eligible' => 1, 'migrated' => 1, 'public_copies_removed' => $removed];
        } finally {
            if (!$committed && $private->exists($destination)) { $private->delete($destination); }
        }
    }

    private function finish(User $user, UserTaxDocument $document, string $source): int
    {
        $public = Storage::disk('public');
        $removed = 0;
        if ($public->exists($source)) {
            if (!$this->safeLocalSource($source)) { throw new RuntimeException('Legacy tax document path is not a regular owned file.'); }
            if (!hash_equals($document->sha256, $this->documents->checksum('public', $source))) {
                throw new RuntimeException('Legacy tax document changed before cleanup.');
            }
            if (!$public->delete($source)) { throw new RuntimeException('Public tax document cleanup failed.'); }
            $removed = 1;
        }
        DB::transaction(function () use ($user, $document) {
            $owner = User::withTrashed()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $owner->metadata = TaxDocumentMetadata::strip($owner->metadata ?? []);
            $owner->save();
            $document->legacy_public_path = null;
            $document->save();
        });
        return $removed;
    }

    private function validLegacyPath(string $path, int $userId): bool
    {
        return preg_match('/^tax-documents\/user-'.preg_quote((string) $userId, '/').'-tax-document-[a-z0-9_-]+\.(pdf|png|jpe?g|docx?)$/iD', $path) === 1;
    }

    private function safeLocalSource(string $source): bool
    {
        $disk = Storage::disk('public');
        $root = realpath($disk->path(''));
        $path = $disk->path($source);
        $resolved = realpath($path);
        if (!$root || !$resolved || is_link($path) || is_link($disk->path('tax-documents'))) { return false; }
        $prefix = rtrim(str_replace('\\', '/', $root), '/').'/tax-documents/';
        return str_starts_with(str_replace('\\', '/', $resolved), $prefix) && is_file($resolved);
    }

    private function submittedAt(User $user): \Illuminate\Support\Carbon
    {
        try { return \Illuminate\Support\Carbon::parse($user->metadata['tax_document_submitted_at'] ?? $user->updated_at); }
        catch (\Throwable) { return now(); }
    }
}
