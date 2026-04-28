<?php

namespace App\Services\Shoots\Actions;

use App\Models\ShootFile;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadShootMediaAction
{
    public function __construct(protected ShootFileAccessService $fileAccess)
    {
    }

    public function execute(ShootFile $file): ?string
    {
        return $this->fileAccess->resolveFileUrl($file);
    }

    public function downloadResponse(ShootFile $file): BinaryFileResponse|JsonResponse|RedirectResponse
    {
        $filename = $file->filename
            ?? $file->stored_filename
            ?? basename((string) ($file->path ?: $file->storage_path ?: $file->dropbox_path ?: 'download'));

        foreach ([
            $file->storage_path,
            $file->path,
            $file->web_path,
            $file->thumbnail_path,
        ] as $candidate) {
            $localPath = $this->fileAccess->resolveLocalPath($candidate);
            if ($localPath && file_exists($localPath)) {
                return response()->download($localPath, $filename);
            }
        }

        $downloaded = $this->fileAccess->downloadFromDropbox($file);
        if ($downloaded && file_exists($downloaded)) {
            return response()->download($downloaded, $filename)->deleteFileAfterSend(true);
        }

        $url = $this->fileAccess->resolveFileUrl($file);
        if ($url) {
            return redirect()->away($url);
        }

        return response()->json(['message' => 'File not available'], 404);
    }
}
