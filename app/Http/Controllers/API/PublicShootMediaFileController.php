<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Media\MediaStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicShootMediaFileController extends Controller
{
    public function show(Request $request, string $path, MediaStorage $media): StreamedResponse
    {
        $key = $media->normalizeKey($path);
        if ($key === null
            || str_contains($key, '..')
            || str_contains($key, '\\')
            || ! str_starts_with($key, 'shoots/')) {
            abort(404);
        }

        if (! $media->exists($key)) {
            abort(404);
        }

        return $media->streamResponse($key);
    }
}
