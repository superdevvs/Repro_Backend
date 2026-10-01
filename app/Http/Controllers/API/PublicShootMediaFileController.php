<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Media\MediaStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicShootMediaFileController extends Controller
{
    public function show(Request $request, string $path, MediaStorage $media): StreamedResponse
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

        if (str_ends_with(strtolower($key), '.zip')) {
            $filename = basename($key);
            $headers['Content-Type'] = 'application/zip';
            $headers['Content-Disposition'] = HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename
            );
        }

        return $media->streamResponse($key, $headers['Content-Type'] ?? null, $headers);
    }
}
