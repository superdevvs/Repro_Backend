<?php

namespace App\Services\IguideLab;

use App\Exceptions\IguideLabSegmentationException;
use App\Services\FalService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LabSegmentation
{
    public const MODEL = 'fal-ai/sam-3-1/image';

    public function __construct(private FalService $fal) {}

    public function submit(string $png): string
    {
        // FalService never retries POST. The pipeline persists its claim before calling this.
        $id = $this->fal->submitModel(self::MODEL, ['image_url' => 'data:image/png;base64,'.base64_encode($png),
            'prompt' => 'grass lawn', 'apply_mask' => false, 'output_format' => 'png',
            'return_multiple_masks' => true, 'max_masks' => 8, 'include_scores' => true, 'include_boxes' => true]);
        if (! preg_match('/^[a-zA-Z0-9_-]{1,200}$/', $id)) throw new RuntimeException('Invalid segmentation receipt.');
        return $id;
    }

    public function poll(string $id): ?array
    {
        $status = $this->fal->modelStatus(self::MODEL, $id);
        if (in_array($status, ['IN_QUEUE', 'IN_PROGRESS'], true)) return null;
        if (in_array($status, ['FAILED', 'ERROR', 'CANCELLED', 'CANCELED', 'REJECTED'], true)) throw new IguideLabSegmentationException('Segmentation ended without a result.');
        if ($status !== 'COMPLETED') throw new RuntimeException('Unknown segmentation status.');
        $result = $this->fal->modelResult(self::MODEL, $id);
        $masks = $result['masks'] ?? [];
        if (! is_array($masks) || ! array_is_list($masks) || count($masks) < 1 || count($masks) > 8) throw new IguideLabSegmentationException('Segmentation returned no bounded mask set.');
        $bytes = [];
        foreach ($masks as $mask) {
            $parts = parse_url($mask['url'] ?? ''); $host = strtolower($parts['host'] ?? '');
            if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
                || ! ($host === 'fal.media' || str_ends_with($host, '.fal.media'))) throw new IguideLabSegmentationException('Unexpected segmentation asset host.');
            $response = Http::connectTimeout(10)->timeout(60)->withOptions(['allow_redirects' => false])->get($mask['url']);
            $body = $response->body(); $size = @getimagesizefromstring($body);
            if (! $response->successful()) throw new RuntimeException('Segmentation mask download is unavailable.');
            if (strlen($body) > 30_000_000 || ! $size || $size['mime'] !== 'image/png' || $size[0] !== 2048 || $size[1] !== 1024) throw new IguideLabSegmentationException('Segmentation mask dimensions are invalid.');
            $bytes[] = $body;
        }
        return ['masks' => $bytes, 'metadata' => $result];
    }
}
