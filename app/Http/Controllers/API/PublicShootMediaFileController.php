<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Media\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class PublicShootMediaFileController extends Controller
{
    public function show(Request $request, string $path, MediaStorage $media): Response
    {
        $key = $media->normalizeKey($path);
        if ($key === null
            || str_contains($key, '..')
            || str_contains($key, '\\')
            || ! (
                str_starts_with($key, 'shoots/')
                || str_starts_with($key, 'share-links/')
                || str_starts_with($key, 'editor-downloads/')
            )) {
            abort(404);
        }

        if (! $media->exists($key)) {
            abort(404);
        }

        $headers = [
            // Disable nginx response buffering so multi-hundred-MB archives
            // flush to the client instead of waiting for PHP to finish.
            'X-Accel-Buffering' => 'no',
        ];

        // The requested name is covered by the absolute URL signature.
        if ($request->query->has('download')) {
            $filename = basename(str_replace('\\', '/', (string) $request->query('download')));
            if ($filename === '' || preg_match('/[\x00-\x1f\x7f]/', $filename)) {
                abort(400);
            }

            return $media->downloadResponse($key, $filename, $headers);
        }

        if (str_ends_with(strtolower($key), '.zip')) {
            $filename = basename($key);
            $headers['Content-Type'] = 'application/zip';
            $headers['Content-Disposition'] = HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
                preg_replace('/[^\x20-\x7E]|%/', '_', Str::ascii($filename)) ?: 'download.zip'
            );
        }

        return $media->streamResponse($key, $headers['Content-Type'] ?? null, $headers);
    }
}
