<?php

namespace Tests\Unit;

use App\Services\Studio\WorkspaceClipReuse;
use Tests\TestCase;

class WorkspaceClipReuseTest extends TestCase
{
    public function test_fingerprints_ignore_finishing_but_track_exact_camera_inputs(): void
    {
        config(['services.fal.walkthrough_model' => 'kling-pinned', 'services.fal.model' => 'wan-pinned', 'services.fal.test_mode' => false]);
        $refs = ['scene1.jpg', 'scene2.jpg', 'scene3.jpg', 'scene4.jpg', 'scene5.jpg', 'scene6.jpg'];
        $config = ['presetId' => 'walkthrough', 'prompt' => 'Calm motion', 'frames' => array_fill(0, 6, ['duration' => 5, 'prompt' => ''])];
        $first = WorkspaceClipReuse::fingerprints($refs, $config);
        $styled = array_merge($config, ['duration' => 45, 'transition' => 'fade', 'text' => ['title' => 'New title', 'style' => 'graphic']]);
        $this->assertSame($first, WorkspaceClipReuse::fingerprints($refs, $styled));

        $revised = $refs;
        $revised[3] = 'scene4-version2.jpg';
        $this->assertSame([2, 3], array_keys(array_diff_assoc($first, WorkspaceClipReuse::fingerprints($revised, $config))));
        $framePrompt = $config;
        $framePrompt['frames'][3]['prompt'] = 'Move toward the window';
        $this->assertSame([3], array_keys(array_diff_assoc($first, WorkspaceClipReuse::fingerprints($refs, $framePrompt))));
        $config['prompt'] = 'Different camera move';
        $this->assertCount(6, array_diff_assoc($first, WorkspaceClipReuse::fingerprints($refs, $config)));
        $config['prompt'] = 'Calm motion';
        config(['services.fal.walkthrough_model' => 'different-model']);
        $this->assertCount(6, array_diff_assoc($first, WorkspaceClipReuse::fingerprints($refs, $config)));

        $config['presetId'] = 'property-reel';
        $independent = WorkspaceClipReuse::fingerprints($refs, $config);
        $this->assertSame([3], array_keys(array_diff_assoc($independent, WorkspaceClipReuse::fingerprints($revised, $config))));
    }
}
