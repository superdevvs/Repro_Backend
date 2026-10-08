<?php

namespace Tests\Unit;

use App\Models\StudioWorkspace;
use Tests\TestCase;

class StudioGenerationScopeTest extends TestCase
{
    public function test_photo_scope_survives_reopening_without_exposing_provider_state(): void
    {
        $workspace = new StudioWorkspace([
            'preset_id' => 'full-shoot', 'status' => 'generating', 'media' => [], 'outputs' => [],
            'operation' => ['type' => 'generate', 'completed' => ['photo-a'],
                'providerState' => ['private-checkpoint' => 'hidden']],
        ]);
        $data = $workspace->present();
        $this->assertSame(['mediaIds' => null, 'completedMediaIds' => ['photo-a']], $data['generationScope']);
        $this->assertArrayNotHasKey('operation', $data);
        foreach (['revision', 'upscale'] as $type) {
            $workspace->operation = ['type' => $type, 'payload' => ['mediaId' => 'photo-b'], 'completed' => []];
            $this->assertSame(['mediaIds' => ['photo-b'], 'completedMediaIds' => []], $workspace->present()['generationScope']);
        }
        $workspace->operation = ['type' => 'adjust', 'payload' => ['targets' => [['mediaId' => 'photo-b', 'outputId' => 'v1']]]];
        $this->assertSame(['photo-b'], $workspace->present()['generationScope']['mediaIds']);
        $workspace->status = 'completed';
        $this->assertNull($workspace->present()['generationScope']);
    }
}
