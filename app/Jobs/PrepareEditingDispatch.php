<?php

namespace App\Jobs;

use App\Models\ShootEditingDispatch;
use App\Models\ShootFile;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\Studio\WorkspaceHdrService;
use App\Services\Studio\WorkspaceMediaService;
use App\Support\LockedWrite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PrepareEditingDispatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public int $timeout = 900;
    public array $backoff = [15, 60];

    public function __construct(public string $dispatchId) { $this->onConnection('studio')->onQueue('studio'); }

    public function handle(WorkspaceMediaService $mediaService, WorkspaceHdrService $hdr): void
    {
        $lock = Cache::lock('editing-dispatch:prepare:'.$this->dispatchId, 960);
        if (!$lock->get()) { $this->release(15); return; }
        try {
            $dispatch = ShootEditingDispatch::with('items')->find($this->dispatchId);
            if (!$dispatch || StudioWorkspace::where('editing_dispatch_id', $dispatch->id)->exists()) return;
            $user = User::findOrFail($dispatch->created_by);
            abort_unless($user->isAccountEligibleForAuthentication(), 403, 'The requesting account is no longer active.');
            $teamId = (int) ($user->team_id ?? $user->metadata['team_id'] ?? $user->id);
            $projects = [];
            foreach ($dispatch->items->where('destination', 'ai')->groupBy('workflow') as $workflow => $items) {
              $media = [];
              foreach ($items as $item) {
                foreach ($item->sources as $source) {
                    $file = ShootFile::findOrFail($source['id']);
                    abort_unless((int) $file->shoot_id === (int) $dispatch->shoot_id && (int) $file->content_version === (int) $source['version'], 409, 'A source changed before editing started. Review the request.');
                }
                $ids = array_column($item->sources, 'id');
                if (count($ids) > 1 && $workflow !== 'full-shoot') {
                    $merged = $hdr->describe($ids, $user, $teamId);
                    $hdr->process($merged, $user->id, $teamId);
                    $media[] = $merged;
                } else {
                    foreach ($ids as $id) $media[] = ['id' => 'file:'.$id, 'fileId' => $id, 'shootId' => $dispatch->shoot_id];
                }
              }
              $projects[$workflow] = ['items' => $items, 'media' => $mediaService->authorize($media, $user, $teamId)];
            }
            LockedWrite::run(fn () => DB::transaction(function () use ($dispatch, $projects, $teamId, $user) {
                if (StudioWorkspace::where('editing_dispatch_id', $dispatch->id)->exists()) return;
              foreach ($projects as $workflow => $project) {
                $media = $project['media'];
                $operation = (string) Str::uuid();
                $workspace = StudioWorkspace::create(['editing_dispatch_id' => $dispatch->id, 'team_id' => $teamId, 'created_by' => $user->id,
                    'shoot_id' => $dispatch->shoot_id, 'shoot_dispatch_key' => $dispatch->id, 'shoot_dispatch_hash' => $dispatch->input_hash,
                    'shoot_service_ids' => [], 'shoot_service_item_ids' => [], 'name' => 'Shoot #'.$dispatch->shoot_id.' · '.$workflow,
                    'preset_id' => $workflow, 'media' => $media, 'status' => 'generating', 'progress' => 0,
                    'config' => ['prompt' => $dispatch->instructions ?? '', 'ratio' => '16:9', 'frames' => array_map(fn ($item) => ['mediaId' => $item['id'], 'method' => 'fit', 'duration' => 5], $media),
                        'adjustments' => $workflow === 'virtual-staging' ? ($dispatch->plan['staging'] ?? []) : []],
                    'operation' => ['id' => $operation, 'key' => $dispatch->id, 'type' => 'generate', 'payload' => [], 'completed' => [], 'requests' => [], 'routing' => [$workflow => $dispatch->plan['routes'][$workflow]]],
                ]);
                $dispatch->items()->whereIn('id', $project['items']->modelKeys())->update(['workspace_id' => $workspace->id, 'status' => 'processing']);
                $dispatch->update(['status' => 'in_progress', 'error' => null]);
                ProcessStudioWorkspace::dispatch($workspace->id, $operation)->beforeCommit();
              }
            }), 'editing-dispatch.workspace');
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $exception): void
    {
        ShootEditingDispatch::whereKey($this->dispatchId)->update(['status' => 'needs_attention', 'error' => 'Editing could not start. Review source availability and retry this request.']);
        Log::error('Editing dispatch preparation failed', ['dispatch_id' => $this->dispatchId, 'exception' => $exception]);
    }
}
