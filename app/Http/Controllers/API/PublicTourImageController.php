<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Media\MediaStorage;
use App\Services\Shoots\PublicTourImage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicTourImageController extends Controller
{
    public function __invoke(Request $request, int $shootId, int $photoId, string $rendition, string $revision, PublicTourImage $images, MediaStorage $media): Response
    {
        $key = $images->resolvePublishedKey($shootId, $photoId, $rendition, $revision);
        abort_unless($key, 404);
        $disk = $media->diskFor($key);
        abort_unless($disk, 404);

        // A retained body is reusable only after the current publication gate
        // above passes. No fresh browser/CDN TTL can bypass hide/unpublish.
        $headers = ['Cache-Control' => 'private, no-cache, max-age=0, must-revalidate', 'X-Content-Type-Options' => 'nosniff'];
        $etag = hash('sha256', $revision.'|'.$disk->size($key).'|'.$disk->lastModified($key));
        $validation = response('', 200, $headers)->setEtag($etag);
        if ($validation->isNotModified($request)) return $validation;
        $response = $media->streamResponse($key, null, $headers);
        $response->setEtag($etag);
        return $response;
    }
}
