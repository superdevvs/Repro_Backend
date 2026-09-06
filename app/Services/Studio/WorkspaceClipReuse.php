<?php

namespace App\Services\Studio;

use App\Models\AiReelJob;
use App\Models\StudioWorkspace;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Reuses original motion clips only; text, transitions and duration are rendered afresh. */
class WorkspaceClipReuse
{
    private const MOTION_PROMPT = 'Smooth, slow drone-like camera movement through the property. Preserve architecture, straight lines and natural lighting. Photorealistic continuous motion within this shot, no cuts or added effects. ';

    public static function prompt(array $config, int $index): string
    {
        return trim(self::MOTION_PROMPT.(string) ($config['prompt'] ?? '').' '.(string) data_get($config, 'frames.'.$index.'.prompt', ''));
    }

    public static function fingerprints(array $refs, array $config): array
    {
        $walkthrough = ($config['presetId'] ?? null) === 'walkthrough';
        $model = (string) config($walkthrough ? 'services.fal.walkthrough_model' : 'services.fal.model');
        $refs = array_values($refs);
        $fingerprints = [];
        foreach ($refs as $index => $ref) {
            $fingerprints[$index] = hash('sha256', json_encode([
                'schema' => 1, 'model' => $model, 'testMode' => (bool) config('services.fal.test_mode'),
                'sourceDisk' => 'public', 'start' => $ref, 'end' => $walkthrough ? ($refs[$index + 1] ?? null) : null,
                'prompt' => self::prompt($config, $index), 'nativeDuration' => 5,
            ], JSON_THROW_ON_ERROR));
        }

        return $fingerprints;
    }

    public function seed(StudioWorkspace $workspace, array $refs, array $config): array
    {
        $runtime = ['fingerprints' => self::fingerprints($refs, $config), 'localClips' => [], 'requests' => [], 'clips' => []];
        $ids = collect(array_merge($workspace->outputs ?? [], $workspace->history ?? []))->pluck('reelJobId')->filter()->unique()->values()->all();
        $available = [];
        foreach (AiReelJob::whereIn('id', $ids)->where('user_id', $workspace->created_by)->where('status', AiReelJob::STATUS_COMPLETED)->get() as $job) {
            if (($job->workflow_config['studioWorkspaceId'] ?? null) !== $workspace->id) {
                continue;
            }
            foreach (data_get($job->workflow_config, '_studioRuntime.fingerprints', []) as $index => $fingerprint) {
                if ($this->localPath($job, (int) $index)) {
                    $available[$fingerprint] = data_get($job->workflow_config, '_studioRuntime.localClips.'.$index);
                }
            }
        }
        foreach ($runtime['fingerprints'] as $index => $fingerprint) {
            if (isset($available[$fingerprint])) {
                $runtime['localClips'][$index] = $available[$fingerprint];
            }
        }

        return $runtime;
    }

    public function localPath(AiReelJob $job, int $index): ?string
    {
        $path = $this->cachePath($job, $index);
        if (! $path || data_get($job->workflow_config, '_studioRuntime.localClips.'.$index) !== $path) {
            return null;
        }
        $disk = Storage::disk('local');

        return $disk->exists($path) ? $disk->path($path) : null;
    }

    public function remember(AiReelJob $job, int $index, string $sourcePath): void
    {
        $path = $this->cachePath($job, $index);
        if (! $path) {
            return;
        }
        $stream = fopen($sourcePath, 'rb');
        try {
            if (! $stream || ! Storage::disk('local')->put($path, $stream)) {
                throw new RuntimeException('The generated scene could not be saved for reuse.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $config = $job->workflow_config;
        $config['_studioRuntime']['localClips'][$index] = $path;
        $job->update(['workflow_config' => $config]);
    }

    private function cachePath(AiReelJob $job, int $index): ?string
    {
        $workspaceId = $job->workflow_config['studioWorkspaceId'] ?? '';
        $fingerprint = data_get($job->workflow_config, '_studioRuntime.fingerprints.'.$index, '');
        if (! preg_match('/^[0-9a-f-]{36}$/i', $workspaceId) || ! preg_match('/^[0-9a-f]{64}$/', $fingerprint)) {
            return null;
        }
        // Legacy reel submissions can carry arbitrary workflow config. A cache read/write
        // requires a real server-persisted workspace/job link, not just copied metadata.
        $workspace = StudioWorkspace::whereKey($workspaceId)->where('created_by', $job->user_id)->first(['operation', 'outputs', 'history']);
        $linked = $workspace && ((int) data_get($workspace->operation, 'reelJobId') === $job->id
            || collect(array_merge($workspace->outputs ?? [], $workspace->history ?? []))->contains(fn ($item) => (int) ($item['reelJobId'] ?? 0) === $job->id));
        if (! $linked) {
            return null;
        }

        return "studio/workspaces/{$workspaceId}/clips/{$fingerprint}.mp4";
    }
}
