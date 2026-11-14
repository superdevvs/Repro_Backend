<?php

namespace App\Services\IguideLab;

use App\Models\IguideLabImportUpload;
use Illuminate\Support\Facades\Storage;

/** The lock inode outlives staging cleanup, so publication and deletion cannot race. */
class LabImportUploadLock
{
    public function run(IguideLabImportUpload $upload, callable $callback, bool $wait = true): mixed
    {
        app(LabStorage::class)->prepareImportUploadLocks($upload->project_id);
        $disk = Storage::disk(LabService::DISK); $relative = 'iguide-lab/'.$upload->project_id.'/import-upload-locks/'.$upload->id.'.lock';
        $path = $disk->path($relative); abort_if(is_link($path), 503, 'Unsafe upload lock.');
        // FPM and Studio use different Unix owners in the same private group.
        // Create0660 atomically; never chmod another owner's existing lock inode.
        $mask = umask(0007);
        try { $lock = @fopen($path, 'x+b'); }
        finally { umask($mask); }
        if (! is_resource($lock)) $lock = @fopen($path, 'r+b');
        abort_unless(is_resource($lock), 503, 'Upload synchronization is unavailable.');
        try {
            if (! flock($lock, LOCK_EX | ($wait ? 0 : LOCK_NB))) return null;
            try { return $callback(); }
            finally { flock($lock, LOCK_UN); }
        } finally { fclose($lock); }
    }
}
