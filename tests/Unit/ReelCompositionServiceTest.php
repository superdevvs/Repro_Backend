<?php

namespace Tests\Unit;

use App\Services\Studio\ReelCompositionService;
use Tests\TestCase;

class ReelCompositionServiceTest extends TestCase
{
    public function test_scene_durations_preserve_total_with_weighted_storyboard(): void
    {
        $renderer = new ReelCompositionService;
        $durations = $renderer->durations(6, 30, array_fill(0, 6, ['duration' => 5]));
        $this->assertSame([5.0, 5.0, 5.0, 5.0, 5.0, 5.0], $durations);
        $this->assertSame(30.0, array_sum($renderer->durations(2, 30, [['duration' => 5], ['duration' => 10]])));
    }

    public function test_none_text_adds_no_rendering_filters(): void
    {
        $this->assertSame([], (new ReelCompositionService)->textFilters(['style' => 'none', 'title' => 'Hidden'], 1080, 1920, [5, 5], sys_get_temp_dir()));
    }
}
