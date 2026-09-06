<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StudioWorkspace extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'media' => 'array', 'config' => 'array', 'outputs' => 'array',
        'prepared_frames' => 'array', 'operation' => 'array', 'history' => 'array',
        'version' => 'integer', 'progress' => 'integer', 'team_id' => 'integer', 'created_by' => 'integer',
    ];

    public function isBusy(): bool
    {
        return in_array($this->status, ['preparing', 'generating'], true);
    }

    public function isVideo(): bool
    {
        return in_array($this->preset_id, ['walkthrough', 'property-reel', 'social-teaser'], true);
    }

    /** Laravel converts blank request strings to null; the workspace API exposes strings. */
    public static function normalizeConfigStrings(array $config): array
    {
        $config['prompt'] = (string) ($config['prompt'] ?? '');
        $config['text']['title'] = (string) ($config['text']['title'] ?? '');
        $config['text']['subtitle'] = (string) ($config['text']['subtitle'] ?? '');
        foreach ($config['frames'] ?? [] as $index => $frame) {
            if (array_key_exists('prompt', $frame)) {
                $config['frames'][$index]['prompt'] = (string) ($frame['prompt'] ?? '');
            }
        }

        return $config;
    }

    public function present(): array
    {
        $generation = $this->generationProgress();

        return [
            'id' => $this->id, 'name' => $this->name, 'presetId' => $this->preset_id,
            'media' => array_map([\App\Services\Studio\WorkspaceMediaService::class, 'withUploadPreview'], $this->media ?? []), 'config' => self::normalizeConfigStrings($this->config ?? []), 'status' => $this->status,
            'progress' => $generation['progress'] ?? $this->progress, 'generation' => $generation ? \Illuminate\Support\Arr::except($generation, ['progress']) : null,
            'error' => $this->error, 'version' => $this->version,
            'outputs' => array_map(fn ($output) => \Illuminate\Support\Arr::except($output, ['reelJobId']), $this->outputs ?? []), 'preparedFrames' => $this->prepared_frames ?? [],
            'history' => array_map(fn ($event) => \Illuminate\Support\Arr::except($event, ['reelJobId']), $this->history ?? []),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'capabilities' => ['framePreparation' => true, 'regionRevisions' => true,
                'objectDetection' => filled(config('services.openai.api_key')), 'pixelSegmentation' => false,
                'crossFrameContinuity' => false, 'transitions' => \App\Services\Studio\ReelCompositionService::TRANSITIONS,
                'startEndFrameConditioning' => filled(config('services.fal.walkthrough_model')),
                'textStyles' => ['none', 'minimal', 'editorial', 'lower-third', 'graphic']],
        ];
    }

    private function generationProgress(): ?array
    {
        if ($this->status !== 'generating' || ! $this->isVideo() || ($this->operation['type'] ?? null) !== 'generate') {
            return null;
        }
        $total = count($this->config['frames'] ?? []) ?: count($this->media ?? []);
        $job = AiReelJob::whereKey($this->operation['reelJobId'] ?? 0)->where('user_id', $this->created_by)->first();
        if (! $job || ($job->workflow_config['studioWorkspaceId'] ?? null) !== $this->id) {
            return ['phase' => 'submitting', 'total' => $total, 'submitted' => 0, 'completed' => 0, 'progress' => 0];
        }
        $runtime = $job->workflow_config['_studioRuntime'] ?? [];
        $completed = min($total, count(array_unique(array_merge(array_keys($runtime['clips'] ?? []), array_keys($runtime['localClips'] ?? [])))));
        $submitted = min($total, count(array_unique(array_merge(array_keys($runtime['requests'] ?? []), array_keys($runtime['clips'] ?? []), array_keys($runtime['localClips'] ?? [])))));
        $phase = in_array($job->status, [AiReelJob::STATUS_STITCHING, AiReelJob::STATUS_COMPLETED], true) ? 'rendering' : ($submitted < $total ? 'submitting' : 'generating');
        $progress = $phase === 'rendering' ? 95 : (int) round(20 * $submitted / max(1, $total) + 70 * $completed / max(1, $total));

        return compact('phase', 'total', 'submitted', 'completed', 'progress');
    }
}
