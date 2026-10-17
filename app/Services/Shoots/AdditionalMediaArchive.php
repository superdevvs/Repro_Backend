<?php

namespace App\Services\Shoots;

use App\Services\UploadValidationService;
use App\Support\UploadLimit;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/** Bounded extraction into generated paths; archive names never become filesystem paths. */
class AdditionalMediaArchive
{
    public const MAX_ENTRIES = 1000;
    public const MAX_EXPANDED_BYTES = 1024 * 1024 * 1024;

    public function __construct(private UploadValidationService $validation, private UploadIntakeResolver $intake) {}

    public function isArchive(UploadedFile $file): bool
    {
        return strtolower($file->getClientOriginalExtension()) === 'zip';
    }

    public function validateMedia(UploadedFile $file, ?string $role): void
    {
        $this->validation->validate($file, 'files', $role);
        if ($this->isArchive($file)) $this->reject('Nested ZIP files are not supported. Unpack them before uploading.');
        $mime = strtolower((string) $this->intake->detectedMimeType($file));
        $camera = ['3fr', 'arw', 'cr2', 'cr3', 'crw', 'dcr', 'dng', 'erf', 'fff', 'iiq', 'kdc', 'mef', 'mos', 'mrw', 'nef', 'nrw', 'orf', 'pef', 'raf', 'raw', 'rw2', 'rwl', 'sr2', 'srf', 'srw', 'x3f'];
        if (! str_starts_with($mime, 'image/') && ! str_starts_with($mime, 'video/')
            && $mime !== 'application/mp4'
            && ! ($mime === 'application/octet-stream' && in_array(strtolower($file->getClientOriginalExtension()), $camera, true))) {
            $this->reject('Only images, camera RAW files and videos can be imported. Unsupported file: '.$file->getClientOriginalName());
        }
    }

    /** @return array<array{file:UploadedFile,name:string}> */
    public function extract(UploadedFile $file, string $directory, ?string $role): array
    {
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath(), ZipArchive::RDONLY) !== true) $this->reject('This ZIP could not be opened. Export a new ZIP and retry.');
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) $this->reject('ZIP files may contain at most '.self::MAX_ENTRIES.' entries.');
            $entries = []; $names = []; $expanded = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat)) $this->reject('A ZIP entry could not be read.');
                $name = str_replace('\\', '/', $stat['name']);
                if (strlen($name) > 512 || str_contains($name, "\0") || preg_match('~^(?:/|[a-z]:)|(?:^|/)\.\.(?:/|$)~i', $name)) $this->reject('The ZIP contains an unsafe file path.');
                $zip->getExternalAttributesIndex($index, $system, $attributes);
                if (($attributes >> 16 & 0170000) === 0120000) $this->reject('ZIP files cannot contain symbolic links.');
                if (($stat['encryption_method'] ?? 0) !== 0) $this->reject('Password-protected ZIP files are not supported.');
                if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') continue;
                $folded = mb_strtolower($name);
                if (isset($names[$folded])) $this->reject('The ZIP contains duplicate file paths.');
                $names[$folded] = true;
                $size = (int) $stat['size']; $expanded += $size;
                if ($size < 1 || $size > UploadLimit::maxBytes() || $expanded > self::MAX_EXPANDED_BYTES) $this->reject('The ZIP exceeds the 1 GB expanded-file limit. Split it into smaller ZIPs.');
                if ($size > 1024 * 1024 && $size / max(1, (int) $stat['comp_size']) > 200) $this->reject('The ZIP compression ratio exceeds the supported limit.');
                $entries[] = ['index' => $index, 'name' => $name, 'raw_name' => $stat['name'], 'size' => $size];
            }
            if ($entries === []) $this->reject('The ZIP does not contain any media files.');
            $result = [];
            foreach ($entries as $entry) {
                $input = $zip->getStream($entry['raw_name']);
                if (! is_resource($input)) $this->reject('A ZIP entry could not be extracted.');
                $path = $directory.'/entry-'.$entry['index'];
                $output = fopen($path, 'xb');
                if (! is_resource($output)) { fclose($input); throw new \RuntimeException('Could not create temporary media storage.'); }
                try { $written = stream_copy_to_stream($input, $output, $entry['size'] + 1); }
                finally { fclose($input); fclose($output); }
                if ($written !== $entry['size']) $this->reject('A ZIP entry did not match its declared size.');
                $upload = new UploadedFile($path, basename($entry['name']), mime_content_type($path) ?: 'application/octet-stream', null, true);
                $this->validateMedia($upload, $role);
                $result[] = ['file' => $upload, 'name' => $entry['name']];
            }
            return $result;
        } finally { $zip->close(); }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['files' => $message]);
    }
}
