<?php

namespace App\Services\IguideLab;

use App\Jobs\AssembleIguideLabImport;
use App\Models\IguideLabImport;
use App\Models\IguideLabImportUpload;
use App\Models\IguideLabProject;
use App\Support\LockedWrite;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Bounded raw chunks and streaming assembly, independent of shoot upload permissions. */
class LabImportUploads
{
    public const CHUNK_BYTES = 8 * 1024 * 1024;
    public const MAX_BYTES = 1024 * 1024 * 1024;

    public function start(IguideLabProject $project, array $input): IguideLabImportUpload
    {
        $this->prune($project->id);
        $hash = hash('sha256', json_encode([$input['importRequestId'], $input['filename'], $input['sizeBytes'], $input['sha256'], $input['metadataSha256'] ?? null], JSON_THROW_ON_ERROR));
        return LockedWrite::run(fn () => DB::transaction(function () use ($project, $input, $hash) {
            IguideLabProject::whereKey($project->id)->update(['updated_at' => now()]); abort_unless($project->fresh()->active(), 401);
            if ($prior = IguideLabImportUpload::where('project_id', $project->id)->where('request_id', $input['requestId'])->first()) {
                abort_unless(hash_equals($prior->request_hash, $hash), 409, 'This upload request ID belongs to different source bytes.'); return $prior;
            }
            abort_if(IguideLabImportUpload::where('project_id', $project->id)->where('import_request_id', $input['importRequestId'])->exists()
                || IguideLabImport::where('project_id', $project->id)->where('request_id', $input['importRequestId'])->exists(), 409, 'This import request ID is already in use.');
            abort_if(IguideLabImportUpload::where('project_id', $project->id)->whereIn('status', ['uploading', 'assembling'])->count() >= 1, 429, 'A scan ZIP upload is already in progress.');
            abort_if(IguideLabImportUpload::where('project_id', $project->id)->where('created_at', '>=', now()->subDay())->count() >= 8, 429, 'The daily scan upload limit has been reached.');
            return IguideLabImportUpload::create(['project_id' => $project->id, 'request_id' => $input['requestId'], 'import_request_id' => $input['importRequestId'],
                'request_hash' => $hash, 'filename' => $input['filename'], 'size_bytes' => $input['sizeBytes'], 'sha256' => $input['sha256'],
                'metadata_sha256' => $input['metadataSha256'] ?? null, 'chunks' => [], 'status' => 'uploading', 'expires_at' => now()->addDay()]);
        }), 'iguide-lab.import-upload.start');
    }

    /** @param resource $input */
    public function chunk(IguideLabImportUpload $upload, int $index, string $range, string $sha256, $input): IguideLabImportUpload
    {
        $this->active($upload); abort_unless(is_resource($input) && preg_match('/^[a-f0-9]{64}$/D', $sha256), 422, 'A chunk stream and lowercase SHA-256 are required.');
        $start = $index * self::CHUNK_BYTES; $length = min(self::CHUNK_BYTES, $upload->size_bytes - $start);
        abort_unless($index >= 0 && $length > 0 && $range === 'bytes '.$start.'-'.($start + $length - 1).'/'.$upload->size_bytes, 416, 'The chunk range is outside the declared source.');
        app(LabStorage::class)->prepareImportUpload($upload->project_id, $upload->id); $disk = Storage::disk(LabService::DISK);
        $path = $this->prefix($upload).'/'.$index.'-'.$sha256.'.part'; $temporary = $this->prefix($upload).'/'.Str::uuid().'.incoming';
        $out = fopen($disk->path($temporary), 'xb'); abort_unless(is_resource($out), 503, 'Private chunk staging is unavailable.');
        $hash = hash_init('sha256'); $bytes = 0;
        try {
            while (! feof($input)) {
                $buffer = fread($input, min(65536, $length - $bytes + 1)); abort_unless($buffer !== false, 422, 'The chunk stream could not be read.');
                if ($buffer === '') break; $bytes += strlen($buffer); abort_if($bytes > $length, 422, 'The chunk is larger than declared.');
                hash_update($hash, $buffer); $this->write($out, $buffer);
            }
            abort_unless($bytes === $length && hash_equals($sha256, hash_final($hash)), 422, 'The chunk length or SHA-256 is incorrect.');
            fclose($out); $out = null; $disk->setVisibility($temporary, 'private');
            // Canonical content-addressed chunk names are immutable; a rival index/hash
            // cannot replace a previously committed chunk's bytes.
            if (! $disk->exists($path)) abort_unless($disk->move($temporary, $path), 503, 'The chunk could not be stored.');
            abort_unless(hash_equals($sha256, hash_file('sha256', $disk->path($path))), 422, 'A stored chunk failed its integrity check.');
            LockedWrite::run(fn () => DB::transaction(function () use ($upload, $index, $sha256, $bytes) {
                IguideLabProject::whereKey($upload->project_id)->update(['updated_at' => now()]); $upload->refresh(); $this->active($upload);
                $chunks = $upload->chunks;
                if (isset($chunks[$index])) { abort_unless($chunks[$index]['sha256'] === $sha256 && $chunks[$index]['sizeBytes'] === $bytes, 409, 'This chunk index already has different bytes.'); return; }
                $chunks[$index] = ['sha256' => $sha256, 'sizeBytes' => $bytes]; ksort($chunks); $upload->update(['chunks' => $chunks]);
            }), 'iguide-lab.import-upload.chunk');
        } finally {
            if (is_resource($out)) fclose($out); $disk->delete($temporary);
            if (($upload->fresh()->chunks[$index]['sha256'] ?? null) !== $sha256) $disk->delete($path);
        }
        return $upload->fresh();
    }

    public function finalize(IguideLabImportUpload $upload, ?UploadedFile $metadata): IguideLabImportUpload
    {
        $upload->refresh(); abort_if($upload->expires_at->isPast(), 410, 'This upload has expired.');
        abort_unless(($metadata ? hash_file('sha256', $metadata->getRealPath()) : null) === $upload->metadata_sha256, 422, 'The optional metadata must match the declared SHA-256.');
        if (in_array($upload->status, ['completed', 'assembling'], true)) return $upload;
        $this->active($upload);
        abort_unless(count($upload->chunks) === (int) ceil($upload->size_bytes / self::CHUNK_BYTES), 422, 'Upload every chunk before finalizing.');
        app(LabStorage::class)->prepareImportUpload($upload->project_id, $upload->id); $disk = Storage::disk(LabService::DISK);
        if ($metadata) { $stream = fopen($metadata->getRealPath(), 'rb'); try { $disk->put($this->prefix($upload).'/metadata.txt', $stream, ['visibility' => 'private']); } finally { fclose($stream); } }
        $claimed = LockedWrite::run(fn () => IguideLabImportUpload::whereKey($upload->id)->where('status', 'uploading')->update(['status' => 'assembling', 'updated_at' => now()]), 'iguide-lab.import-upload.finalize');
        if ($claimed) try { AssembleIguideLabImport::dispatch($upload->id); } catch (Throwable) { $this->fail($upload, 'Upload assembly could not be queued.'); }
        return $upload->fresh();
    }

    public function assemble(IguideLabImportUpload $upload): void
    {
        $upload->refresh(); if ($upload->status !== 'assembling') return;
        $disk = Storage::disk(LabService::DISK); $prefix = $this->prefix($upload); $assembly = $prefix.'/assembled.zip';
        try {
            abort_unless($upload->expires_at->isFuture() && IguideLabProject::find($upload->project_id)?->active(), 410, 'The upload or lab has expired.');
            // A crash after the import reservation is reconciled, never duplicated.
            $record = IguideLabImport::where('project_id', $upload->project_id)->where('request_id', $upload->import_request_id)->first();
            if (! $record) {
                $disk->delete($assembly); $output = fopen($disk->path($assembly), 'xb'); abort_unless(is_resource($output), 503);
                $whole = hash_init('sha256'); $total = 0;
                try {
                    for ($index = 0, $n = (int) ceil($upload->size_bytes / self::CHUNK_BYTES); $index < $n; $index++) {
                        $chunk = $upload->chunks[$index] ?? null; abort_unless($chunk, 422, 'An upload chunk is missing.');
                        $path = $prefix.'/'.$index.'-'.$chunk['sha256'].'.part'; $input = $disk->readStream($path); abort_unless(is_resource($input), 422, 'An upload chunk is missing.');
                        $hash = hash_init('sha256'); $size = 0;
                        try { while (! feof($input)) { $buffer = fread($input, 65536); abort_unless($buffer !== false, 422); if ($buffer === '') break;
                            $size += strlen($buffer); $total += strlen($buffer); abort_if($size > $chunk['sizeBytes'] || $total > $upload->size_bytes, 422, 'A stored chunk changed.');
                            hash_update($hash, $buffer); hash_update($whole, $buffer); $this->write($output, $buffer); }
                        } finally { fclose($input); }
                        abort_unless($size === $chunk['sizeBytes'] && hash_equals($chunk['sha256'], hash_final($hash)), 422, 'A stored chunk failed verification.');
                    }
                } finally { fclose($output); }
                abort_unless($total === $upload->size_bytes && hash_equals($upload->sha256, hash_final($whole)), 422, 'The assembled ZIP does not match the declared source SHA-256.');
                $disk->setVisibility($assembly, 'private');
                $metadataPath = $prefix.'/metadata.txt'; $metadata = $upload->metadata_sha256 ? new UploadedFile($disk->path($metadataPath), 'metadata.txt', null, null, true) : null;
                abort_unless(! $metadata || hash_equals($upload->metadata_sha256, hash_file('sha256', $metadata->getRealPath())), 422, 'The uploaded metadata changed.');
                $record = app(LabImports::class)->start(IguideLabProject::findOrFail($upload->project_id), ['requestId' => $upload->import_request_id], new UploadedFile($disk->path($assembly), 'source.zip', 'application/zip', null, true), $metadata);
            }
            abort_unless($record->source_kind === 'zip' && ($record->private_input['archiveSha256'] ?? null) === $upload->sha256
                && ($record->private_input['metadataSha256'] ?? null) === $upload->metadata_sha256, 409, 'The linked import does not match this upload.');
            $upload->update(['status' => 'completed', 'import_id' => $record->id]); $this->cleanup($upload);
        } catch (Throwable $error) { $this->fail($upload, $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getMessage() : 'The ZIP assembly failed integrity or storage verification.'); }
    }

    public function prune(?string $projectId = null): int
    {
        $query = IguideLabImportUpload::where('expires_at', '<=', now())->whereIn('status', ['uploading', 'assembling']); if ($projectId) $query->where('project_id', $projectId);
        $count = 0; foreach ($query->limit(100)->get() as $upload) {
            $changed = LockedWrite::run(fn () => IguideLabImportUpload::whereKey($upload->id)->whereIn('status', ['uploading', 'assembling'])->where('expires_at', '<=', now())->update(['status' => 'expired', 'error' => 'The upload expired.', 'updated_at' => now()]), 'iguide-lab.import-upload.expire');
            if ($changed) { $this->cleanup($upload); $count++; }
        }
        return $count;
    }
    public function fail(IguideLabImportUpload $upload, string $message): void
    {
        IguideLabImportUpload::whereKey($upload->id)->where('status', '!=', 'completed')->update(['status' => 'needs_attention', 'error' => $message ?: 'Upload assembly failed.', 'updated_at' => now()]);
        $this->cleanup($upload);
    }
    private function cleanup(IguideLabImportUpload $upload): void { $disk = Storage::disk(LabService::DISK); foreach ($disk->files($this->prefix($upload)) as $path) $disk->delete($path); }
    private function active(IguideLabImportUpload $upload): void
    {
        abort_unless(IguideLabProject::find($upload->project_id)?->active(), 401); abort_if($upload->expires_at->isPast(), 410, 'The upload expired.');
        abort_unless($upload->status === 'uploading', 409, 'The upload no longer accepts chunks.');
    }
    private function prefix(IguideLabImportUpload $upload): string { return 'iguide-lab/'.$upload->project_id.'/import-uploads/'.$upload->id; }
    private function write($stream, string $bytes): void { while ($bytes !== '') { $n = fwrite($stream, $bytes); abort_unless($n > 0, 503, 'Private upload storage is unavailable.'); $bytes = substr($bytes, $n); } }
    public function dto(IguideLabImportUpload $upload): array
    {
        return ['id' => $upload->id, 'requestId' => $upload->request_id, 'importRequestId' => $upload->import_request_id, 'status' => $upload->status,
            'sizeBytes' => $upload->size_bytes, 'sha256' => $upload->sha256, 'chunkSizeBytes' => self::CHUNK_BYTES,
            'totalChunks' => (int) ceil($upload->size_bytes / self::CHUNK_BYTES), 'receivedChunks' => array_map('intval', array_keys($upload->chunks)),
            'receivedBytes' => array_sum(array_column($upload->chunks, 'sizeBytes')), 'importId' => $upload->import_id,
            'error' => $upload->error, 'expiresAt' => $upload->expires_at->toIso8601String()];
    }
}
