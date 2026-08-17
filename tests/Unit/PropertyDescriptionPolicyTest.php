<?php

namespace Tests\Unit;

use App\Models\Shoot;
use App\Services\Shoots\PropertyDescriptionPolicy;
use PHPUnit\Framework\TestCase;

class PropertyDescriptionPolicyTest extends TestCase
{
    private PropertyDescriptionPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new PropertyDescriptionPolicy;
    }

    public function test_standard_tier_has_a_650_character_limit(): void
    {
        $shoot = new Shoot;

        $this->assertSame(PropertyDescriptionPolicy::TIER_STANDARD, $this->policy->tierFor($shoot));
        $this->assertSame(650, $this->policy->maxCharactersFor($shoot));
    }

    public function test_the_611_character_sample_remains_unchanged(): void
    {
        $sample = "Discover this elegant 4-bedroom, 3-bathroom home offering 2,400 square feet of spacious living. Designed for both comfort and style, the open floor plan features generous living areas perfect for gatherings and entertainment. The gourmet kitchen is equipped with modern appliances and ample cabinetry. Retreat to the serene master suite with an en-suite bath. Situated on a quarter-acre lot, the expansive outdoor space provides endless possibilities for gardening or recreation. Priced at $725,000, this home seamlessly blends sophistication with everyday functionality. Don't miss the chance to make it yours.";

        $this->assertSame(611, mb_strlen($sample));
        $this->assertSame($sample, $this->policy->enforceCharacterLimit($sample, 650));
    }

    public function test_an_oversized_description_ends_at_a_complete_sentence(): void
    {
        $sentence = 'Bright interiors and flexible living spaces create a welcoming home for everyday comfort.';
        $description = implode(' ', array_fill(0, 12, $sentence));

        $limited = $this->policy->enforceCharacterLimit($description, 650);

        $this->assertLessThanOrEqual(650, mb_strlen($limited));
        $this->assertStringEndsWith('.', $limited);
        $this->assertNotSame($description, $limited);
    }

    public function test_unicode_text_without_spaces_is_capped_safely(): void
    {
        $description = str_repeat('界', 700);

        $limited = $this->policy->enforceCharacterLimit($description, 650);

        $this->assertSame(650, mb_strlen($limited));
        $this->assertStringEndsWith('…', $limited);
        $this->assertSame(1, preg_match('//u', $limited));
    }
}
