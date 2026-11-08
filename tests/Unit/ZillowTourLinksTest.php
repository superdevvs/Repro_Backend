<?php

namespace Tests\Unit;

use App\Services\Shoots\ZillowTourLinks;
use PHPUnit\Framework\TestCase;

class ZillowTourLinksTest extends TestCase
{
    public function test_unbranded_interactive_link_preserves_published_options_in_both_views(): void
    {
        $base = 'https://www.zillow.com/view-imx/f2b5a984-31f1-4eb5-8db4-32c85a5f994d';
        $viewer = (new ZillowTourLinks)->viewer(['zillow_3d' => $base.'?setAttribution=mls&wl=true&utm_source=dashboard'], false);
        $this->assertSame($base.'?setAttribution=mls&wl=true&utm_source=dashboard', $viewer['inline_url']);
        $this->assertSame($base.'?setAttribution=mls&wl=true&utm_source=dashboard&initialViewType=floorplan', $viewer['floorplan_url']);
    }

    public function test_branded_walkthrough_keeps_its_existing_viewing_options(): void
    {
        $url = 'https://www.zillow.com/view-imx/abcdef?wl=true&initialViewType=pano';
        $viewer = (new ZillowTourLinks)->viewer(['zillow_3d' => $url], true);
        $this->assertSame($url, $viewer['inline_url']);
        $this->assertSame(str_replace('pano', 'floorplan', $url), $viewer['floorplan_url']);
    }

    public function test_branded_links_are_not_treated_as_unbranded_and_3d_only_links_have_no_plan(): void
    {
        $links = ['zillow_3d' => 'https://www.zillow.com/view-3d-home/abcdef'];
        $service = new ZillowTourLinks;
        $this->assertNull($service->viewer($links, false));
        $this->assertNotNull($service->viewer($links, true));
        $this->assertNull($service->viewer($links, true)['floorplan_url']);
    }

    public function test_unbranded_links_reject_duplicate_or_array_presentation_flags(): void
    {
        $service = new ZillowTourLinks;
        foreach ([
            'setAttribution=mls&setAttribution=branded&wl=true',
            'setAttribution=branded&setAttribution=mls&wl=true',
            'setAttribution[]=mls&wl=true',
            'setAttribution[]=branded&setAttribution=mls&wl=true',
            'setAttribution=mls&wl=true&wl=false',
            'setAttribution=mls&wl[]=true',
            'wl[]=false&setAttribution=mls&wl=true',
            'setAttribution=mls&wl=true&set%41ttribution=mls',
        ] as $query) {
            $this->assertNull($service->normalize('https://www.zillow.com/view-imx/abcdef?'.$query, true));
        }
    }

    public function test_unsafe_and_non_viewer_links_are_rejected(): void
    {
        $service = new ZillowTourLinks;
        foreach (['javascript:alert(1)', 'https://www.zillow.com.evil.test/view-imx/abcdef', 'https://user@www.zillow.com/view-imx/abcdef', 'http://www.zillow.com/view-imx/abcdef', 'https://www.zillow.com/homedetails/abcdef'] as $url) {
            $this->assertNull($service->normalize($url));
        }
    }
}
