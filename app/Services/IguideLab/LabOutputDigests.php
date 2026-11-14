<?php

namespace App\Services\IguideLab;

use App\Models\IguideLabProject;
use App\Models\IguideLabRun;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Stored-byte identity only; request options and imported receipt assertions are never digest authorities. */
class LabOutputDigests
{
    public function path(IguideLabRun $run): string
    {
        return 'iguide-lab/'.$run->project_id.'/runs/'.$run->id.'.png';
    }

    /** Index reads never open or hash assets. Legacy results safely remain null until operator verification. */
    public function dto(IguideLabRun $run): ?string
    {
        return $run->status === 'completed' && $run->output_path === $this->path($run)
            && is_string($run->output_sha256) && preg_match('/\A[a-f0-9]{64}\z/D', $run->output_sha256)
            ? $run->output_sha256 : null;
    }

    /** Bounded, operator-only verification. Each output uses a short project write transaction. */
    public function backfill(string $projectId): array
    {
        $project = IguideLabProject::findOrFail($projectId);
        abort_unless($project->active(), 422, 'The lab project is inactive.');
        $ids = IguideLabRun::where('project_id', $projectId)->where('status', 'completed')->orderBy('id')->limit(121)->pluck('id');
        abort_if($ids->count() > 120, 422, 'This bounded operation supports at most 120 completed results.');
        $saved = 0; $verified = 0;
        foreach ($ids as $id) {
            $initial = IguideLabRun::where('project_id', $projectId)->where('status', 'completed')->findOrFail($id);
            $binding = $initial->only(['output_path', 'output_width', 'output_height', 'output_sha256']);
            $file = $this->ownedFile($initial);
            // File decoding and the first byte digest happen before acquiring SQLite's shared write lock.
            $bytes = file_get_contents($file['path']); $size = @getimagesizefromstring($bytes);
            abort_unless($size && ($size['mime'] ?? null) === 'image/png' && $size[0] === $initial->output_width
                && $size[1] === $initial->output_height && $size[0] * $size[1] <= 4_624_220,
                422, 'A completed output has invalid stored image dimensions.');
            $decoded = @imagecreatefromstring($bytes);
            abort_unless($decoded !== false, 422, 'A completed output cannot be decoded.');
            imagedestroy($decoded); $hash = hash('sha256', $bytes); unset($bytes);
            $created = LockedWrite::run(fn () => DB::transaction(function () use ($projectId, $id, $binding, $file, $hash) {
                IguideLabProject::whereKey($projectId)->update(['updated_at' => now()]);
                abort_unless(IguideLabProject::findOrFail($projectId)->active(), 422, 'The lab project is inactive.');
                $run = IguideLabRun::where('project_id', $projectId)->where('status', 'completed')->findOrFail($id);
                abort_unless($run->only(array_keys($binding)) === $binding && $this->ownedFile($run) === $file
                    && hash_equals($hash, hash_file('sha256', $file['path'])), 422, 'A completed output changed during verification.');
                if ($run->output_sha256 !== null) {
                    abort_unless(is_string($run->output_sha256) && hash_equals($run->output_sha256, $hash), 422, 'A completed output no longer matches its stored digest.');
                    return false;
                }
                $run->update(['output_sha256' => $hash]);
                return true;
            }), 'iguide-lab.output-digest');
            $created ? $saved++ : $verified++;
        }
        return ['projectId' => $projectId, 'verifiedOutputs' => $ids->count(), 'storedDigests' => $saved,
            'previouslyVerifiedDigests' => $verified, 'providerCalls' => 0, 'imageBytesChanged' => false];
    }

    private function ownedFile(IguideLabRun $run): array
    {
        $disk = Storage::disk(LabService::DISK); $expected = $disk->path($this->path($run));
        clearstatcache(true);
        $labRoot = realpath($disk->path('iguide-lab'));
        $root = realpath(dirname($expected)); $resolved = realpath($expected);
        abort_unless($run->output_path === $this->path($run) && $labRoot !== false && $root !== false && $resolved !== false
            && $root === $labRoot.DIRECTORY_SEPARATOR.$run->project_id.DIRECTORY_SEPARATOR.'runs'
            && ! is_link($expected) && str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) && is_file($resolved),
            422, 'A completed output is missing or outside its owned storage.');
        $stat = stat($resolved);
        abort_unless($stat && $stat['size'] > 0 && $stat['size'] <= 32 * 1024 * 1024, 422, 'A completed output exceeds bounded verification size.');
        return ['path' => $resolved, 'device' => $stat['dev'], 'inode' => $stat['ino'], 'size' => $stat['size'], 'mtime' => $stat['mtime'], 'ctime' => $stat['ctime']];
    }
}
