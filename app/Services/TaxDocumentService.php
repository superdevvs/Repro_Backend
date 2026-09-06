<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserTaxDocument;
use App\Support\TaxDocumentMetadata;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class TaxDocumentService
{
    public const DISK = 'tax_documents';
    public const MIME_EXTENSIONS = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg'];

    public function current(User $user): ?UserTaxDocument
    {
        return UserTaxDocument::where('user_id', $user->id)->first();
    }

    public function summary(User $user): ?array
    {
        $document = $this->current($user);
        if (!$document) { return null; }
        return [
            'id' => $document->id, 'original_name' => $document->original_name,
            'mime_type' => $document->mime_type, 'size' => $document->size,
            'submitted_at' => $document->submitted_at->toIso8601String(),
            'can_download' => $this->validPrivatePath($document) && Storage::disk(self::DISK)->exists($document->path),
        ];
    }

    public function store(User $user, UploadedFile $file, ?string $notes): UserTaxDocument
    {
        return $this->locked($user->id, function () use ($user, $file, $notes) {
            if (!$user->fresh()?->isAccountEligibleForAuthentication() || request()->attributes->get('is_impersonating', false)) {
                throw new \App\Exceptions\PublicApiException('Tax documents are unavailable for this session.', 'tax_document_session_unavailable', 403);
            }
            $existing = $this->current($user);
            $oldPath = $existing?->path;
            $metadata = $user->fresh()->metadata ?? [];
            if ($existing?->legacy_public_path || (!$existing && !empty($metadata['tax_document_path']))) {
                throw new \App\Exceptions\PublicApiException('Your previous tax document must be secured before it can be replaced. Contact an administrator.', 'tax_document_migration_required', 409);
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
            if (!isset(self::MIME_EXTENSIONS[$mime])) {
                throw new \App\Exceptions\PublicApiException('Choose a PDF, PNG or JPG document.', 'tax_document_format_invalid', 422);
            }
            $extension = self::MIME_EXTENSIONS[$mime];
            $path = $user->id.'/'.Str::uuid().'.'.$extension;
            $disk = Storage::disk(self::DISK);
            $committed = false;
            try {
                $this->prepareOwnerDirectory($user->id);
                $stored = $disk->putFileAs((string) $user->id, $file, basename($path));
                if ($stored !== $path) { throw new RuntimeException('Private tax document write failed.'); }
                $hash = hash_file('sha256', $file->getRealPath());
                if ($hash !== $this->checksum(self::DISK, $path)) { throw new RuntimeException('Private tax document verification failed.'); }
                $document = DB::transaction(function () use ($user, $existing, $path, $file, $extension, $mime, $hash, $notes) {
                    $owner = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                    if (!$owner->isAccountEligibleForAuthentication()) {
                        throw new \App\Exceptions\PublicApiException('Tax documents are unavailable for this session.', 'tax_document_session_unavailable', 403);
                    }
                    $document = $existing ?: new UserTaxDocument(['user_id' => $owner->id]);
                    $document->fill([
                        'path' => $path, 'original_name' => $this->safeName($file->getClientOriginalName(), $extension),
                        'mime_type' => $mime, 'size' => $file->getSize(), 'sha256' => $hash,
                        'notes' => $notes, 'submitted_at' => now(), 'legacy_public_path' => null,
                    ])->save();
                    $owner->metadata = TaxDocumentMetadata::strip($owner->metadata ?? []);
                    $owner->save();
                    return $document;
                });
                $committed = true;
                if ($oldPath && $oldPath !== $path) {
                    $this->deleteReplacedFile($oldPath, $user->id);
                }
                Log::info('Tax document submitted.', ['user_id' => $user->id, 'document_id' => $document->id]);
                return $document;
            } finally {
                if (!$committed && $disk->exists($path)) { $disk->delete($path); }
            }
        });
    }

    public function validPrivatePath(UserTaxDocument $document): bool
    {
        return preg_match('/^'.preg_quote((string) $document->user_id, '/').'\/[a-f0-9-]{36}\.(pdf|png|jpg|jpeg|doc|docx)$/D', $document->path) === 1;
    }

    /** Call under the owner lock before writing; never change another Unix owner's directory mode. */
    public function prepareOwnerDirectory(int $userId): void
    {
        $disk = Storage::disk(self::DISK);
        $root = realpath($disk->path(''));
        if (!$root || !is_dir($root)) { throw new RuntimeException('Private tax document storage is not ready.'); }
        clearstatcache(true, $root);
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($root) & 07777) !== 02770) {
            throw new RuntimeException('Private tax document storage permissions need operator review.');
        }
        $directory = (string) $userId;
        $fullPath = $disk->path($directory);
        if (!$disk->directoryExists($directory)) {
            $disk->makeDirectory($directory);
            // mkdir obeys umask. chmod after creation restores group write and
            // setgid without changing the process-wide umask used by other disks.
            $disk->setVisibility($directory, 'private');
        }
        clearstatcache(true, $fullPath);
        $resolved = realpath($fullPath);
        if (!$resolved || is_link($fullPath) || dirname($resolved) !== $root
            || !is_readable($fullPath) || !is_writable($fullPath)) {
            throw new RuntimeException('Private tax document owner directory is unavailable.');
        }
        if (PHP_OS_FAMILY !== 'Windows' && ((fileperms($fullPath) & 07777) !== 02770
            || filegroup($fullPath) !== filegroup($root))) {
            throw new RuntimeException('Private tax document owner permissions need operator review.');
        }
    }

    public function checksum(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);
        if (!is_resource($stream)) { throw new RuntimeException('Tax document could not be read.'); }
        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            return hash_final($context);
        } finally { fclose($stream); }
    }

    public function safeName(string $name, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $stem = preg_replace('/[\x00-\x1f\x7f]/', '', pathinfo($name, PATHINFO_FILENAME));
        return (Str::limit($stem ?: 'tax-document', 160, '')).'.'.$extension;
    }

    public function locked(int $userId, callable $callback): mixed
    {
        return Cache::lock('tax-document:user:'.$userId, 120)->block(10, $callback);
    }

    private function deleteReplacedFile(string $path, int $userId): void
    {
        try {
            $old = new UserTaxDocument(['user_id' => $userId, 'path' => $path]);
            if ($this->validPrivatePath($old)) { Storage::disk(self::DISK)->delete($path); }
        } catch (\Throwable $exception) {
            Log::warning('Replaced private tax document cleanup needs attention.', ['user_id' => $userId, 'exception' => $exception::class]);
        }
    }
}
