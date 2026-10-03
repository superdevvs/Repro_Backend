<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\PrepareEditingDispatch;
use App\Jobs\ProcessStudioWorkspace;
use App\Models\Shoot;
use App\Models\ShootEditingDispatch;
use App\Models\ShootEditingDispatchItem;
use App\Models\ShootFile;
use App\Models\ShootFileVersion;
use App\Models\StudioWorkspace;
use App\Services\Media\MediaStorage;
use App\Services\Shoots\MediaVersionPublisher;
use App\Services\Shoots\ScopedEditingDispatch;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Request assignments grant access only to their explicit source files and returned edits. */
class EditingTaskController extends Controller
{
    private function staff(Request $request): bool
    {
        return in_array($request->user()->role, ['admin', 'superadmin', 'editing_manager'], true);
    }

    private function authorizeItem(Request $request, ShootEditingDispatchItem $item): void
    {
        abort_unless($this->staff($request) || ($request->user()->role === 'editor'
            && (int) $item->editor_id === (int) $request->user()->id && $request->user()->canEditLane($item->lane)), 403);
    }

    public function index(Request $request)
    {
        abort_unless($this->staff($request) || $request->user()->role === 'editor', 403);
        $query = ShootEditingDispatch::query()->with(['items' => function ($query) use ($request) {
            if (!$this->staff($request)) $query->where('editor_id', $request->user()->id);
        }])->when(!$this->staff($request), fn ($query) => $query->whereHas('items', fn ($items) => $items->where('editor_id', $request->user()->id)));
        if ($request->filled('shoot_id')) $query->where('shoot_id', $request->integer('shoot_id'));
        $page = $query->latest()->paginate(30);
        return response()->json(['data' => $page->getCollection()->map(function ($dispatch) {
            // Do not expose another editor's files through the stored preview plan.
            return $dispatch->only(['id', 'shoot_id', 'scope', 'workflow', 'instructions', 'status', 'error', 'created_at'])
                + ['address' => Shoot::find($dispatch->shoot_id)?->address,
                    'items' => $dispatch->items->map(function ($item) {
                        $version = $item->primary_version_id ? ShootFileVersion::find($item->primary_version_id) : null;
                        return $item->present() + ['returnedVersion' => $version?->present()];
                    })];
        }), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]);
    }

    public function download(Request $request, ShootEditingDispatchItem $item, ShootFile $file, MediaStorage $storage)
    {
        $this->authorizeItem($request, $item);
        $source = collect($item->sources)->firstWhere('id', $file->id);
        abort_unless($source && (int) $file->shoot_id === (int) $item->dispatch->shoot_id && !$file->is_hidden && $file->isClearedForProcessing(), 404);
        abort_unless((int) $source['version'] === (int) $file->content_version, 409, 'The source changed. Ask the editing manager to create a new request.');
        return $storage->downloadResponse($file->path ?: $file->storage_path, $file->filename, ['Cache-Control' => 'private, no-store']);
    }

    public function upload(Request $request, ShootEditingDispatchItem $item, MediaVersionPublisher $publisher)
    {
        $this->authorizeItem($request, $item);
        abort_unless($item->destination === 'human' && $item->lane === 'photo', 422);
        $data = $request->validate(['file' => 'required|file|max:262144', 'request_id' => 'required|uuid']);
        $key = 'task:'.$item->id.':'.$data['request_id'];
        $existing = ShootFileVersion::where('request_key', $key)->first();
        abort_unless(!$item->primary_version_id || $existing || ShootFileVersion::find($item->primary_version_id)?->status === 'failed', 409, 'This task already has a saved return. Resolve or retry that upload before returning another edit.');
        $source = ShootFile::where('shoot_id', $item->dispatch->shoot_id)->findOrFail($item->sources[0]['id']);
        $upload = $request->file('file');
        $version = $publisher->stage($source, $upload->getRealPath(), $upload->getClientOriginalName(), $item->sources[0]['version'], $request->user(), $key,
            ['origin' => 'human', 'dispatch_item_id' => $item->id, 'source_versions' => collect($item->sources)->pluck('version', 'id')->all()]);
        return response()->json(['data' => $version->present(), 'message' => 'Upload saved. Submit this task after processing finishes.'], 202);
    }

    public function video(Request $request, ShootEditingDispatchItem $item)
    {
        $this->authorizeItem($request, $item);
        abort_unless($item->destination === 'human' && $item->lane === 'video', 422);
        $data = $request->validate(['url' => 'required|url:https|max:2048']);
        LockedWrite::run(fn () => DB::transaction(function () use ($item, $data) {
            $locked = ShootEditingDispatchItem::findOrFail($item->id);
            abort_unless($locked->status !== 'completed' || $locked->return_url === $data['url'], 409, 'This task has already been submitted.');
            if ($locked->status !== 'completed') $locked->update(['return_url' => $data['url'], 'status' => 'returned']);
        }), 'editing-task.video');
        return response()->json(['data' => $item->fresh()->present()]);
    }

    public function submit(Request $request, ShootEditingDispatchItem $item, ScopedEditingDispatch $dispatches)
    {
        $this->authorizeItem($request, $item);
        abort_unless($item->destination === 'human', 422);
        LockedWrite::run(fn () => DB::transaction(function () use ($item) {
            $locked = ShootEditingDispatchItem::findOrFail($item->id);
            if ($locked->status === 'completed') return;
            abort_unless($locked->status === 'returned', 409, 'Save a finished return and wait for processing before submitting this task.');
            if ($locked->lane === 'photo') {
                $version = ShootFileVersion::find($locked->primary_version_id);
                $file = $version?->published_file_id ? ShootFile::find($version->published_file_id) : null;
                abort_unless($file && $version->status === 'published' && $file->isClearedForProcessing() && !$file->is_hidden, 409, 'The returned image is no longer current. Ask the editing manager to review this task.');
            } else {
                abort_unless($locked->return_url, 409, 'Add the finished video link.');
                $shoot = Shoot::findOrFail($locked->dispatch->shoot_id);
                $unitId = DB::table('shoot_service')->where('shoot_id', $shoot->id)->where('id', $locked->shoot_service_id)->value('shoot_unit_id');
                $owner = $unitId ? $shoot->units()->findOrFail($unitId) : $shoot;
                // Publish to the matching unit only; keep branded/MLS and unrelated links intact.
                $owner->update(['tour_links' => array_merge($owner->tour_links ?? [], ['video_link' => $locked->return_url])]);
            }
            $locked->update(['status' => 'completed', 'error' => null]);
        }), 'editing-task.submit');
        // Reconcile on every repeat too: recover a response loss between item commit and reconciliation.
        $dispatches->reconcile($item->dispatch_id);
        return response()->json(['data' => $item->fresh()->present(), 'message' => 'Editing task submitted.']);
    }

    public function retry(Request $request, ShootEditingDispatch $dispatch, ScopedEditingDispatch $dispatches)
    {
        abort_unless($this->staff($request), 403);
        LockedWrite::run(fn () => DB::transaction(function () use ($dispatch, $dispatches) {
            $workspaces = StudioWorkspace::where('editing_dispatch_id', $dispatch->id)->get();
            if ($workspaces->isEmpty()) {
                PrepareEditingDispatch::dispatch($dispatch->id)->beforeCommit();
                $dispatch->update(['status' => 'queued', 'error' => null]);
                return;
            }
            foreach ($workspaces as $workspace) {
                if ($workspace->status !== 'failed') continue;
                $dispatches->assertWorkspaceSources($workspace);
                $workspace->update(['status' => 'generating', 'error' => null]);
                $dispatch->items()->where('workspace_id', $workspace->id)->whereNull('primary_version_id')->update(['status' => 'processing', 'error' => null]);
                // Retain the operation ID and provider checkpoints, including uncertain paid requests.
                ProcessStudioWorkspace::dispatch($workspace->id, $workspace->operation['id'])->beforeCommit();
            }
        }), 'editing-task.retry');
        return response()->json(['data' => $dispatches->response($dispatch)], 202);
    }
}
