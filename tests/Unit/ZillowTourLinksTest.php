<?php

namespace Tests\Unit;

use App\Services\Shoots\ZillowTourLinks;
use PHPUnit\Framework\TestCase;

class ZillowTourLinksTest extends TestCase
{
    public function test_unbranded_interactive_link_provides_both_views_without_tracking(): void
    {
        $base = 'https://www.zillow.com/view-imx/f2b5a984-31f1-4eb5-8db4-32c85a5f994d';
        $viewer = (new ZillowTourLinks)->viewer(['zillow_3d' => $base.'?setAttribution=mls&wl=true&utm_source=dashboard'], false);
        $this->assertSame($base.'?setAttribution=mls&wl=true&initialViewType=pano', $viewer['inline_url']);
        $this->assertSame($base.'?setAttribution=mls&wl=true&initialViewType=floorplan', $viewer['floorplan_url']);
    }

    public function test_branded_links_are_not_treated_as_unbranded_and_3d_only_links_have_no_plan(): void
    {
        $links = ['zillow_3d' => 'https://www.zillow.com/view-3d-home/abcdef'];
        $service = new ZillowTourLinks;
        $this->assertNull($service->viewer($links, false));
        $this->assertNotNull($service->viewer($links, true));
        $this->assertNull($service->viewer($links, true)['floorplan_url']);
    }

    public function test_unsafe_and_non_viewer_links_are_rejected(): void
    {
        $service = new ZillowTourLinks;
        foreach (['javascript:alert(1)', 'https://www.zillow.com.evil.test/view-imx/abcdef', 'https://user@www.zillow.com/view-imx/abcdef', 'http://www.zillow.com/view-imx/abcdef', 'https://www.zillow.com/homedetails/abcdef'] as $url) {
            $this->assertNull($service->normalize($url));
        }
    }
}
