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

        $folder = trim($request->input('folder', 'uploads'), '/');
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





