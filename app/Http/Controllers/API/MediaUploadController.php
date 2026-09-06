<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaUploadController extends Controller
{
    public function uploadImage(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:jpeg,jpg,png,gif,bmp,webp,pdf|max:10240',
            'folder' => 'nullable|string|max:100',
        ]);

        try {
            $folder = (new \League\Flysystem\WhitespacePathNormalizer)->normalizePath(
                (string) $request->input('folder', 'uploads')
            );
        } catch (\League\Flysystem\PathTraversalDetected $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'folder' => ['Choose a valid upload folder.'],
            ]);
        }
        if (in_array('tax-documents', explode('/', strtolower($folder)), true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'folder' => ['Tax documents must use the private tax-document upload.'],
            ]);
        }
        $path = $request->file('file')->store($folder, 'public');

        $url = Storage::disk('public')->url($path);
        if (!Str::startsWith($url, ['http://', 'https://'])) {
            $url = rtrim(config('app.url'), '/') . $url;
        }

        return response()->json([
            'message' => 'File uploaded successfully.',
            'path' => $path,
            'url' => $url,
        ], 201);
    }
}





