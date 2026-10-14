<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\{Shoot, ShootFile, User};
use App\Services\Media\MediaStorage;
use App\Services\Shoots\{ShootAuthorizationSupport, ShootMediaArchiveService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\HeaderUtils;

/** Reference URLs are viewer-bound and reauthorize at transfer time. */
class VideoEditorReferenceMediaController extends Controller
{
    public static function fileUrl(ShootFile $file, User $viewer, bool $original = false): string
    {
        return URL::temporarySignedRoute('api.video-editor.reference-file', now()->addMinutes(15), [
            'shoot' => $file->shoot_id, 'file' => $file->id, 'viewer' => $viewer->id,
            'original' => $original ? 1 : 0,
        ]);
    }

    public function file(Request $request, Shoot $shoot, ShootFile $file, User $viewer)
    {
        abort_unless(app(ShootAuthorizationSupport::class)->canVideoEditorReadFinalImage($shoot, $file, $viewer), 403);
        $media = app(MediaStorage::class);
        $paths = $request->boolean('original')
            ? [$file->storage_path, $file->path]
            : [$file->web_path, $file->thumbnail_path, $file->storage_path, $file->path];
        foreach (array_filter($paths) as $path) {
            $key = $media->normalizeKey($path);
            if ($key && $media->exists($key)) {
                return $media->streamResponse($key, null, ['Cache-Control' => 'private, no-store']);
            }
        }
        abort(404, 'File not available');
    }

    public function archive(Request $request, Shoot $shoot, User $viewer)
    {
        $access = app(ShootAuthorizationSupport::class);
        abort_unless($access->canEditVideoTourLinks($shoot, $viewer), 403);
        $archives = app(ShootMediaArchiveService::class);
        $type = (string) $request->query('type');
        $size = (string) $request->query('size');
        $serviceId = $request->integer('service') ?: null;
        $unitId = $request->integer('unit') ?: null;
        $files = $archives->getFilesForType($shoot, $type, $serviceId, $unitId);
        abort_if($files->isEmpty(), 404);
        foreach ($files as $file) {
            abort_unless($access->canDownloadShootMediaFile($shoot, $file, $viewer) && ! $file->isBlockedFromDelivery(), 403);
        }
        // A changed file set must be rebuilt through the authenticated polling endpoint.
        abort_unless($archives->hasFreshArchive($shoot, $type, $size, $serviceId, $unitId), 409);
        $path = $archives->getArchivePath($shoot, $type, $size, $serviceId, $unitId);
        return app(MediaStorage::class)->streamResponse($path, 'application/zip', [
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', basename($path), 'photos.zip'),
        ]);
    }
}
