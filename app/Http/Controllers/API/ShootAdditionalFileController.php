<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\{Shoot, ShootAdditionalFile};
use App\Services\Shoots\{AdditionalFileAccess, ShootAuthorizationSupport};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ShootAdditionalFileController extends Controller
{
    public function index(Request $request, Shoot $shoot, ShootAuthorizationSupport $authorization, AdditionalFileAccess $access)
    {
        abort_unless($authorization->canUploadShootMedia($shoot, $request->user(), 'additional'), 403);
        $files = ShootAdditionalFile::where('shoot_id', $shoot->id)->latest()->get()
            ->filter(fn ($file) => $access->canRead($shoot, $file, $request->user()))
            ->map(fn ($file) => $access->present($file))->values();
        return response()->json(['files' => $files])->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $request, Shoot $shoot, ShootAdditionalFile $additionalFile, AdditionalFileAccess $access)
    {
        $file = $additionalFile;
        abort_unless($access->canRead($shoot, $file, $request->user()), 403);
        abort_unless(data_get($file->metadata, 'scan_status') === 'clean', 403);
        return Storage::disk('local')->download($file->path, $file->filename, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
