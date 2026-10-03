<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMediaVersion;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootFileVersion;
use App\Services\Media\MediaStorage;
use App\Services\Shoots\MediaVersionPublisher;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MediaVersionController extends Controller
{
    private function authorizeStaff(Request $request, Shoot $shoot, ?ShootFileVersion $version = null): void
    {
        abort_unless(in_array($request->user()->role, ['admin', 'superadmin', 'editing_manager'], true), 403);
        app(ShootAuthorizationSupport::class)->ensureShootAccess($shoot, $request->user());
        if ($version) abort_unless((int) $version->shoot_id === (int) $shoot->id, 404);
    }

    public function index(Request $request, Shoot $shoot, ShootFile $file)
    {
        $this->authorizeStaff($request, $shoot);
        abort_unless((int) $file->shoot_id === (int) $shoot->id, 404);
        return response()->json(['data' => ShootFileVersion::where('shoot_id', $shoot->id)
            ->where(fn ($query) => $query->where('published_file_id', $file->id)->orWhere('target_file_id', $file->id)->orWhere('source_file_id', $file->id)
                ->when($file->source_file_id, fn ($query) => $query->orWhere('source_file_id', $file->source_file_id)))
            ->latest()->get()->map->present(), 'current_version' => $file->content_version]);
    }

    public function store(Request $request, Shoot $shoot, ShootFile $file, MediaVersionPublisher $publisher)
    {
        $this->authorizeStaff($request, $shoot);
        abort_unless((int) $file->shoot_id === (int) $shoot->id && !$file->is_hidden, 404);
        $data = $request->validate(['file' => 'required|file|max:262144', 'request_id' => 'required|uuid', 'expected_version' => 'required|integer|min:1']);
        $upload = $request->file('file');
        $version = $publisher->stage($file, $upload->getRealPath(), $upload->getClientOriginalName(), $data['expected_version'], $request->user(), 'upload:'.$data['request_id'], ['origin' => 'manual']);
        return response()->json(['data' => $version->present(), 'message' => 'Upload saved. The current image stays available until processing completes.'], 202);
    }

    public function show(Request $request, Shoot $shoot, ShootFileVersion $version)
    {
        if ($request->user()->role === 'editor' && (int) $version->created_by === (int) $request->user()->id && ($version->metadata['origin'] ?? '') === 'intake') {
            abort_unless((int) $version->shoot_id === (int) $shoot->id, 404);
            app(ShootAuthorizationSupport::class)->ensureShootAccess($shoot, $request->user());
        } else $this->authorizeStaff($request, $shoot, $version);
        return response()->json(['data' => $version->present(), 'current_version' => ShootFile::find($version->target_file_id)?->content_version]);
    }

    public function resolve(Request $request, Shoot $shoot, ShootFileVersion $version, MediaVersionPublisher $publisher)
    {
        $this->authorizeStaff($request, $shoot, $version);
        $data = $request->validate(['choice' => ['required', Rule::in(['replace_latest', 'save_copy'])], 'expected_latest_version' => 'required|integer|min:1']);
        return response()->json(['data' => $publisher->resolve($version, $data['choice'], $data['expected_latest_version'])->present()]);
    }

    public function restore(Request $request, Shoot $shoot, ShootFileVersion $version, MediaStorage $storage, MediaVersionPublisher $publisher)
    {
        $this->authorizeStaff($request, $shoot, $version);
        abort_unless(in_array($version->status, ['archived', 'published'], true), 409, 'Only a previously published version can be restored.');
        $data = $request->validate(['request_id' => 'required|uuid', 'expected_version' => 'required|integer|min:1']);
        $target = ShootFile::findOrFail($version->published_file_id);
        abort_if($target->workflow_stage === ShootFile::STAGE_TODO, 422, 'Raw originals cannot be replaced.');
        $path = $storage->absolutePath($version->snapshot['path'] ?? $version->snapshot['storage_path']);
        $temporary = !$path;
        if (!$path) $path = $storage->downloadToTemp($version->snapshot['path'] ?? $version->snapshot['storage_path']);
        abort_unless($path && is_file($path), 409, 'The previous bytes could not be read. The current image is unchanged.');
        try {
            $restored = $publisher->stage($target, $path, $version->snapshot['filename'], $data['expected_version'], $request->user(), 'restore:'.$data['request_id'], ['origin' => 'restore', 'restored_version_id' => $version->id]);
            return response()->json(['data' => $restored->present()], 202);
        } finally {
            if ($temporary && is_file($path)) unlink($path);
        }
    }

    public function retry(Request $request, Shoot $shoot, ShootFileVersion $version)
    {
        $this->authorizeStaff($request, $shoot, $version);
        LockedWrite::run(fn () => DB::transaction(function () use ($version) {
            $locked = ShootFileVersion::lockForUpdate()->findOrFail($version->id);
            abort_unless($locked->status === 'failed' && $locked->error_code === 'processing_failed', 409, 'This edit is not eligible for processing retry.');
            $locked->update(['status' => 'queued', 'error' => null, 'error_code' => null]);
            ProcessMediaVersion::dispatch($locked->id)->beforeCommit();
        }), 'media-version.retry');
        return response()->json(['data' => $version->fresh()->present()], 202);
    }

    public function preview(Request $request, Shoot $shoot, ShootFileVersion $version, MediaStorage $storage)
    {
        $this->authorizeStaff($request, $shoot, $version);
        abort_unless(in_array($version->status, ['published', 'archived', 'conflict', 'alternative'], true), 404);
        $path = $version->snapshot['web_path'] ?? $version->snapshot['thumbnail_path'] ?? null;
        abort_unless($path && $storage->exists($path), 404);
        return response($storage->get($path), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function dismiss(Request $request, Shoot $shoot, ShootFileVersion $version)
    {
        $this->authorizeStaff($request, $shoot, $version);
        LockedWrite::run(fn () => DB::transaction(function () use ($version) {
            $saved = ShootFileVersion::findOrFail($version->id);
            abort_unless(in_array($saved->status, ['failed', 'conflict', 'alternative'], true) && !$saved->dispatch_item_id, 409, 'Resolve assigned task returns through the task workflow.');
            // Keep saved bytes for recovery. This only withdraws an unpublished upload.
            $saved->update(['status' => 'dismissed']);
        }), 'media-version.dismiss');
        return response()->json(['data' => $version->fresh()->present()]);
    }
}
