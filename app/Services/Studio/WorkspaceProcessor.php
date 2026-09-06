<?php

namespace App\Services\Studio;

use App\Exceptions\FalTerminalException;
use App\Jobs\GenerateReel;
use App\Models\AiReelJob;
use App\Models\StudioWorkspace;
use App\Services\FalService;
use App\Services\ReproAi\LlmClient;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use RuntimeException;

class WorkspaceProcessor
{
    private const OUTPAINT_MAX_EDGE = 2560;

    private const EDIT_MIN_EDGE = 256;

    private const EDIT_MAX_EDGE = 2048;

    private ImageManager $images;

    public function __construct(private WorkspaceMediaService $media, private FalService $fal)
    {
        $this->images = ImageManager::gd();
    }

    public function process(StudioWorkspace $workspace, string $operationId): void
    {
        // Queue time can outlive assignments/payment state. Recheck the persisted references before spending.
        $this->media->authorize($workspace->media, \App\Models\User::findOrFail($workspace->created_by), $workspace->team_id);
        $type = $workspace->operation['type'];
        $items = $workspace->media;
        if ($type === 'revision') {
            $items = array_values(array_filter($items, fn ($m) => $m['id'] === $workspace->operation['payload']['mediaId']));
        } elseif (count($workspace->config['frames'] ?? [])) {
            $selected = array_column($workspace->config['frames'], 'mediaId');
            $items = array_values(array_filter($items, fn ($m) => in_array($m['id'], $selected, true)));
        }
        if ($type === 'generate' && $workspace->isVideo()) {
            $this->reel($workspace, $operationId);

            return;
        }
        foreach ($items as $index => $item) {
            if (! $this->active($workspace, $operationId)) {
                return;
            }
            if (in_array($item['id'], $workspace->operation['completed'] ?? [], true)) {
                continue;
            }
            $bytes = $this->sourceBytes($workspace, $item, $type === 'revision');
            $frame = collect($workspace->config['frames'] ?? [])->firstWhere('mediaId', $item['id']) ?? ['mediaId' => $item['id'], 'method' => 'fit'];
            if ($type === 'prepare') {
                $result = $this->prepareImage($workspace, $operationId, $item, $bytes, $frame);
            } else {
                $result = $this->editImage($workspace, $operationId, $item, $bytes, $type);
            }
            if (! $this->active($workspace, $operationId)) {
                return;
            }
            $stored = $this->media->store($workspace, $result, $operationId.'-'.substr(hash('sha256', $item['id']), 0, 16));
            $this->mutate($workspace, $operationId, function (StudioWorkspace $w) use ($type, $item, $stored, $frame, $index, $items, $operationId): void {
                $field = $type === 'prepare' ? 'prepared_frames' : 'outputs';
                $records = $w->{$field} ?? [];
                $version = (int) collect($records)->where('mediaId', $item['id'])->max('version') + 1;
                if ($type === 'prepare') {
                    $records = array_values(array_filter($records, fn ($r) => $r['mediaId'] !== $item['id']));
                }
                $records[] = ['id' => $operationId.'-'.$item['id'], 'mediaId' => $item['id'], 'url' => $stored['url'], 'thumbnailUrl' => $stored['url'], 'path' => $stored['path'], 'kind' => 'image', 'method' => $frame['method'], 'ratio' => $w->config['ratio'], 'status' => 'completed', 'version' => $version];
                // A frame revision is also the current prepared frame for the next reel render.
                if ($type === 'revision' && $w->isVideo()) {
                    $prepared = array_values(array_filter($w->prepared_frames ?? [], fn ($r) => $r['mediaId'] !== $item['id']));
                    $prepared[] = end($records);
                    $w->prepared_frames = $prepared;
                }
                $w->{$field} = $records;
                $operation = $w->operation;
                $operation['completed'][] = $item['id'];
                $w->operation = $operation;
                $w->progress = (int) round(95 * ($index + 1) / count($items));
            });
        }
        $this->finish($workspace, $operationId, $type === 'prepare' ? 'ready' : ($workspace->isVideo() && $type === 'revision' ? 'ready' : 'completed'));
    }

    private function prepareImage(StudioWorkspace $w, string $operationId, array $item, string $bytes, array $frame): string
    {
        [$rw, $rh] = array_map('intval', explode(':', $w->config['ratio']));
        $targetRatio = $rw / $rh;
        $image = $this->images->read($bytes)->orient()->scaleDown(width: 1600, height: 1600);
        $width = $targetRatio >= 1 ? 1600 : (int) round(1600 * $targetRatio);
        $height = $targetRatio >= 1 ? (int) round(1600 / $targetRatio) : 1600;
        if ($frame['method'] === 'crop') {
            return (string) $image->cover($width, $height)->toJpeg(92);
        }
        if ($frame['method'] === 'fit') {
            return (string) $image->contain($width, $height, '101828')->toJpeg(92);
        }
        // Expand only missing edges; preserve the complete source within the new canvas.
        // Bound the expanded canvas, not just the source: landscape -> portrait
        // can otherwise turn a 1600px source into a rejected 2845px output.
        $canvasWidth = $targetRatio >= 1 ? self::OUTPAINT_MAX_EDGE : (int) floor(self::OUTPAINT_MAX_EDGE * $targetRatio);
        $canvasHeight = $targetRatio >= 1 ? (int) floor(self::OUTPAINT_MAX_EDGE / $targetRatio) : self::OUTPAINT_MAX_EDGE;
        $image->scaleDown(width: $canvasWidth, height: $canvasHeight);
        $sourceWidth = $image->width();
        $sourceHeight = $image->height();
        $expandedWidth = max($sourceWidth, (int) ceil($sourceHeight * $targetRatio));
        $expandedHeight = max($sourceHeight, (int) ceil($sourceWidth / $targetRatio));
        $dx = $expandedWidth - $sourceWidth;
        $dy = $expandedHeight - $sourceHeight;
        if ($dx <= 1 && $dy <= 1) {
            return (string) $image->toJpeg(92);
        }
        $model = config('services.fal.outpaint_model', 'fal-ai/flux-2-pro/outpaint');
        $id = $this->requestId($w, $operationId, $item['id'], function () use ($image, $model, $dx, $dy): string {
            return $this->fal->submitModel($model, ['image_url' => 'data:image/jpeg;base64,'.base64_encode((string) $image->toJpeg(92)), 'expand_left' => (int) floor($dx / 2), 'expand_right' => (int) ceil($dx / 2), 'expand_top' => (int) floor($dy / 2), 'expand_bottom' => (int) ceil($dy / 2), 'auto_crop' => false, 'output_format' => 'jpeg']);
        });
        $this->poll($w, $operationId, $item['id'], fn () => $this->fal->modelStatus($model, $id));

        $url = $this->providerCall($w, $operationId, $item['id'], fn () => $this->fal->modelImageResult($model, $id));

        return (string) $this->images->read($this->download($url))->cover($width, $height)->toJpeg(92);
    }

    private function editImage(StudioWorkspace $w, string $operationId, array $item, string $bytes, string $type): string
    {
        $image = $this->images->read($bytes)->orient();
        $source = clone $image;
        $payload = $w->operation['payload'] ?? [];
        $region = $payload['region'] ?? $this->drawingBounds($payload['drawing'] ?? []);
        $bounds = null;
        if ($region && $type === 'revision') {
            $x = (int) floor($region['x'] * $image->width());
            $y = (int) floor($region['y'] * $image->height());
            $width = max(1, min($image->width() - $x, (int) ceil($region['width'] * $image->width())));
            $height = max(1, min($image->height() - $y, (int) ceil($region['height'] * $image->height())));
            $bounds = [$x, $y, $width, $height];
            $source->crop($width, $height, $x, $y);
        }
        $prompt = $type === 'revision' ? $payload['prompt'] : $this->presetPrompt($w->preset_id).' '.($w->config['prompt'] ?? '');
        if ($type !== 'revision' && ! empty($w->config['adjustments'])) {
            $prompt .= ' Requested visual adjustments: '.json_encode($w->config['adjustments']).'.';
        }
        $prompt .= ' Preserve the actual property structure, perspective, materials, and photorealism. Only make the requested changes.';
        [$source, $padding] = $this->normalizeEditSource($source);
        $id = $this->requestId($w, $operationId, $item['id'], function () use ($source, $prompt): string {
            $submission = $this->fal->submitImageEditFromBuffer((string) $source->toJpeg(94), 'property.jpg', 'image/jpeg', 'enhance', ['prompt' => $prompt]);

            return $submission['request_id'];
        });
        $this->poll($w, $operationId, $item['id'], fn () => strtoupper($this->fal->imageEditStatus($id)['status'] ?? 'PROCESSING'));
        $result = $this->providerCall($w, $operationId, $item['id'], fn () => $this->fal->imageEditResult($id));
        $edited = $this->images->read($this->download($result['edited_image_url']));
        if ($padding) {
            // Provider output resolution can differ from its input. Remove only
            // the temporary padding before returning to the original edit scope.
            $x = (int) floor($padding['x'] * $edited->width());
            $y = (int) floor($padding['y'] * $edited->height());
            $width = min($edited->width() - $x, max(1, (int) round($padding['width'] * $edited->width())));
            $height = min($edited->height() - $y, max(1, (int) round($padding['height'] * $edited->height())));
            $edited->crop($width, $height, $x, $y);
        }
        if ($bounds) {
            [$x, $y, $width, $height] = $bounds;
            $image->place($edited->cover($width, $height), 'top-left', $x, $y);

            return (string) $image->toJpeg(96);
        }

        return (string) $edited->toJpeg(94);
    }

    /** @return array{ImageInterface, ?array{x: float, y: float, width: float, height: float}} */
    private function normalizeEditSource(ImageInterface $source): array
    {
        $scale = min(
            max(1, self::EDIT_MIN_EDGE / $source->width(), self::EDIT_MIN_EDGE / $source->height()),
            self::EDIT_MAX_EDGE / $source->width(),
            self::EDIT_MAX_EDGE / $source->height()
        );
        $source->resize(max(1, (int) round($source->width() * $scale)), max(1, (int) round($source->height() * $scale)));
        $width = max(self::EDIT_MIN_EDGE, $source->width());
        $height = max(self::EDIT_MIN_EDGE, $source->height());
        if ($width === $source->width() && $height === $source->height()) {
            return [$source, null];
        }
        // Very thin selections cannot meet both model limits by scaling alone.
        $x = (int) floor(($width - $source->width()) / 2);
        $y = (int) floor(($height - $source->height()) / 2);
        $canvas = $this->images->create($width, $height)->fill('101828')->place($source, 'top-left', $x, $y);

        return [$canvas, ['x' => $x / $width, 'y' => $y / $height, 'width' => $source->width() / $width, 'height' => $source->height() / $height]];
    }

    private function reel(StudioWorkspace $w, string $operationId): void
    {
        if (! $this->active($w, $operationId)) {
            return;
        }
        $jobId = $w->operation['reelJobId'] ?? null;
        if (! $jobId) {
            $frames = collect($w->prepared_frames)->keyBy('mediaId');
            $ordered = collect($w->config['frames'] ?? []);
            $ids = $ordered->isNotEmpty() ? $ordered->pluck('mediaId') : collect($w->media)->pluck('id');
            $refs = $ids->map(fn ($id) => $frames->get($id)['path'])->all();
            $config = array_merge($w->config, ['sourceDisk' => 'public', 'studioWorkspace' => true, 'studioWorkspaceId' => $w->id, 'presetId' => $w->preset_id]);
            $config['_studioRuntime'] = app(WorkspaceClipReuse::class)->seed($w, $refs, $config);
            $job = AiReelJob::create(['shoot_id' => null, 'user_id' => $w->created_by, 'provider' => 'fal', 'selected_file_ids' => [], 'source_media_refs' => $refs, 'workflow_config' => $config, 'status' => AiReelJob::STATUS_QUEUED]);
            $jobId = $job->id;
            $this->mutate($w, $operationId, function ($w) use ($jobId): void {
                $op = $w->operation;
                $op['reelJobId'] = $jobId;
                $w->operation = $op;
            });
        }
        $job = AiReelJob::findOrFail($jobId);
        if ($job->status !== AiReelJob::STATUS_COMPLETED) {
            (new GenerateReel($jobId))->handle($this->fal, app(ShootFileAccessService::class));
        }
        $job->refresh();
        if ($job->status !== AiReelJob::STATUS_COMPLETED) {
            throw new RuntimeException('The reel has not finished rendering.');
        }
        $this->mutate($w, $operationId, function ($w) use ($job, $operationId): void {
            $outputs = $w->outputs ?? [];
            $version = (int) collect($outputs)->where('kind', 'video')->max('version') + 1;
            foreach ($job->outputs as $format => $output) {
                $outputs[] = ['id' => $operationId.'-'.$format, 'mediaId' => 'reel', 'url' => $output['url'], 'thumbnailUrl' => $w->prepared_frames[0]['url'] ?? null, 'kind' => 'video', 'version' => $version, 'status' => 'completed', 'reelJobId' => $job->id];
            }
            $w->outputs = $outputs;
        });
        $this->finish($w, $operationId, 'completed');
    }

    public function segments(StudioWorkspace $w, string $mediaId): array
    {
        $item = collect($w->media)->firstWhere('id', $mediaId);
        $image = $this->images->read($this->sourceBytes($w, $item, true))->scaleDown(width: 1024, height: 1024);
        $response = app(LlmClient::class)->chatCompletion([['role' => 'system', 'content' => 'Identify up to 12 visible, editable room objects or surfaces in the image. Return only a JSON array of {"label":"concise name","region":{"x":0.1,"y":0.1,"width":0.2,"height":0.2}}. Coordinates are normalized 0..1 and boxes must fit the image. These are approximate bounding boxes, not pixel segmentation masks. Only include things actually visible; return [] if uncertain.'], ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Locate visible objects and surfaces.'], ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,'.base64_encode((string) $image->toJpeg(85))]]]]], [], false, ['temperature' => 0, 'max_tokens' => 1800]);
        $text = trim((string) data_get($response, 'choices.0.message.content', ''));
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text);
        $items = json_decode($text, true);
        if (! is_array($items) || ! array_is_list($items)) {
            throw new RuntimeException('Object detection returned an unreadable response. Please retry.');
        }
        $result = [];
        foreach (array_slice($items, 0, 12) as $index => $box) {
            $r = $box['region'] ?? [];
            if (! isset($box['label'], $r['x'], $r['y'], $r['width'], $r['height']) || ! is_string($box['label'])) {
                continue;
            }
            if (count(array_filter($r, 'is_numeric')) !== 4 || $r['x'] < 0 || $r['y'] < 0 || $r['width'] <= 0 || $r['height'] <= 0 || $r['x'] + $r['width'] > 1 || $r['y'] + $r['height'] > 1) {
                continue;
            }
            $result[] = ['id' => 'object-'.$index, 'label' => mb_substr($box['label'], 0, 80), 'region' => array_map('floatval', $r)];
        }

        return $result;
    }

    private function sourceBytes(StudioWorkspace $w, array $item, bool $latest): string
    {
        if ($latest) {
            $output = collect($w->outputs ?? [])->where('mediaId', $item['id'])->where('kind', 'image')->sortByDesc('version')->first();
            $output ??= collect($w->prepared_frames ?? [])->firstWhere('mediaId', $item['id']);
            if ($output && ! empty($output['path'])) {
                return Storage::disk('public')->get($output['path']);
            }
        }

        return $this->media->bytes($item);
    }

    private function requestId(StudioWorkspace $w, string $operationId, string $mediaId, callable $submit): string
    {
        $id = $w->operation['requests'][$mediaId] ?? null;
        if ($id) {
            return $id;
        }
        if (! $this->active($w, $operationId)) {
            throw new RuntimeException('Workspace operation cancelled.');
        }
        $id = $submit();
        $this->mutate($w, $operationId, function ($w) use ($mediaId, $id): void {
            $op = $w->operation;
            $op['requests'][$mediaId] = $id;
            $w->operation = $op;
        });

        return $id;
    }

    private function poll(StudioWorkspace $w, string $operationId, string $mediaId, callable $status): void
    {
        $deadline = microtime(true) + (int) config('services.fal.video_poll_timeout', 900);
        do {
            if (! $this->active($w, $operationId)) {
                throw new RuntimeException('Workspace operation cancelled.');
            }
            $state = strtoupper($this->providerCall($w, $operationId, $mediaId, $status));
            if ($state === 'COMPLETED') {
                return;
            }
            if (in_array($state, ['FAILED', 'ERROR', 'CANCELLED'], true)) {
                // A terminal request cannot recover. Retain in-flight IDs on timeout/network failure.
                $this->mutate($w, $operationId, function ($w) use ($mediaId): void {
                    $op = $w->operation;
                    unset($op['requests'][$mediaId]);
                    $w->operation = $op;
                });
                throw new RuntimeException('The provider could not complete this image operation.');
            }
            sleep(max(1, (int) config('services.fal.video_poll_interval', 5)));
        } while (microtime(true) < $deadline);
        throw new RuntimeException('The provider is taking longer than expected. Retry to resume this request.');
    }

    private function providerCall(StudioWorkspace $w, string $operationId, string $mediaId, callable $call): mixed
    {
        try {
            return $call();
        } catch (FalTerminalException $exception) {
            if ($exception->canDiscardRequest()) {
                $this->mutate($w, $operationId, function ($w) use ($mediaId): void {
                    $operation = $w->operation;
                    unset($operation['requests'][$mediaId]);
                    $w->operation = $operation;
                });
            }
            throw $exception;
        }
    }

    private function active(StudioWorkspace $w, string $operationId): bool
    {
        $w->refresh();

        return $w->isBusy() && ($w->operation['id'] ?? null) === $operationId;
    }

    private function mutate(StudioWorkspace $w, string $operationId, callable $mutation): void
    {
        DB::transaction(function () use ($w, $operationId, $mutation): void {
            $record = StudioWorkspace::lockForUpdate()->findOrFail($w->id);
            if (! $record->isBusy() || ($record->operation['id'] ?? null) !== $operationId) {
                return;
            }
            $mutation($record);
            $record->version++;
            $record->save();
        });
        $w->refresh();
    }

    private function finish(StudioWorkspace $w, string $operationId, string $status): void
    {
        $this->mutate($w, $operationId, function ($w) use ($status): void {
            $history = $w->history ?? [];
            $history[] = ['id' => $w->operation['id'], 'type' => $w->operation['type'], 'payload' => $w->operation['payload'], 'completedAt' => now()->toIso8601String()];
            if (! empty($w->operation['reelJobId'])) {
                $history[array_key_last($history)]['reelJobId'] = $w->operation['reelJobId'];
            }
            $w->history = array_slice($history, -100);
            $w->status = $status;
            $w->progress = 100;
            $w->error = null;
        });
    }

    private function download(string $url): string
    {
        if (preg_match('#^data:image/[^;]+;base64,(.+)$#s', $url, $matches)) {
            $bytes = base64_decode($matches[1], true);
            if ($bytes !== false) {
                return $bytes;
            }
        }
        if (! str_starts_with($url, 'https://')) {
            throw new RuntimeException('The provider returned an invalid result location.');
        }
        $response = Http::timeout(120)->retry(2, 500)->get($url);
        $response->throw();

        return $response->body();
    }

    private function drawingBounds(array $strokes): ?array
    {
        $points = array_merge([], ...$strokes);
        if (count($points) < 2) {
            return null;
        }
        $xs = array_column($points, 'x');
        $ys = array_column($points, 'y');
        $x = max(0, min($xs) - 0.01);
        $y = max(0, min($ys) - 0.01);

        return ['x' => $x, 'y' => $y, 'width' => min(1, max($xs) + 0.01) - $x, 'height' => min(1, max($ys) + 0.01) - $y];
    }

    private function presetPrompt(string $preset): string
    {
        return match ($preset) {
            'twilight' => 'Create realistic dusk twilight lighting with a natural evening sky and warm existing interior lights.',
            'green-grass' => 'Restore a natural healthy green appearance to existing lawn areas only. Preserve landscaping boundaries.',
            'virtual-staging' => 'Virtually stage this room with tasteful, appropriately scaled contemporary furniture. Preserve architecture, windows, doors, walls and permanent fixtures.',
            'color-correction' => 'Correct white balance, color casts and exposure for a natural professional real estate photograph.',
            default => 'Professionally enhance this real estate photograph: balanced exposure, recovered window highlights, straight verticals, natural colors and crisp detail.',
        };
    }
}
