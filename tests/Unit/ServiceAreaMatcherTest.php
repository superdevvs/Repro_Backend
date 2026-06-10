<?php

namespace Tests\Unit;

use App\Models\ServiceArea;
use App\Models\User;
use App\Services\ServiceAreaMatcher;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceAreaMatcherTest extends TestCase
{
    private ServiceAreaMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new ServiceAreaMatcher();
    }

    /**
     * Build an in-memory photographer with its serviceAreas relation pre-set
     * (no DB), keeping the matcher's pureness under test.
     *
     * @param  list<array{kind: string, value: string}>  $areas
     */
    private function photographer(int $id, array $areas): User
    {
        $user = new User();
        $user->id = $id;

        $serviceAreas = collect($areas)->map(function (array $area) {
            $serviceArea = new ServiceArea();
            $serviceArea->kind = $area['kind'];
            $serviceArea->value = $area['value'];

            return $serviceArea;
        });

        $user->setRelation('serviceAreas', $serviceAreas);

        return $user;
    }

    #[Test]
    public function it_returns_only_photographers_matching_kind_and_value(): void
    {
        $md = $this->photographer(1, [['kind' => 'state', 'value' => 'MD']]);
        $va = $this->photographer(2, [['kind' => 'state', 'value' => 'VA']]);
        $both = $this->photographer(3, [
            ['kind' => 'state', 'value' => 'VA'],
            ['kind' => 'state', 'value' => 'MD'],
        ]);

        $result = $this->matcher->match(collect([$md, $va, $both]), ['kind' => 'state', 'value' => 'MD']);

        $this->assertEquals([1, 3], $result->pluck('id')->all());
    }

    #[Test]
    public function it_matches_value_case_insensitively(): void
    {
        $photographer = $this->photographer(1, [['kind' => 'state', 'value' => 'MD']]);

        $result = $this->matcher->match(collect([$photographer]), ['kind' => 'state', 'value' => 'md']);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result->first()->id);
    }

    #[Test]
    public function it_does_not_match_when_kind_differs(): void
    {
        // Same value but different kind must not match.
        $photographer = $this->photographer(1, [['kind' => 'area', 'value' => 'MD']]);

        $result = $this->matcher->match(collect([$photographer]), ['kind' => 'state', 'value' => 'MD']);

        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function it_returns_empty_collection_when_no_photographers_match(): void
    {
        $photographer = $this->photographer(1, [['kind' => 'region', 'value' => 'Northeast']]);

        $result = $this->matcher->match(collect([$photographer]), ['kind' => 'region', 'value' => 'Southwest']);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function it_reindexes_results_sequentially(): void
    {
        $skip = $this->photographer(1, [['kind' => 'state', 'value' => 'VA']]);
        $keep = $this->photographer(2, [['kind' => 'state', 'value' => 'MD']]);

        $result = $this->matcher->match(collect([$skip, $keep]), ['kind' => 'state', 'value' => 'MD']);

        $this->assertSame([0], $result->keys()->all());
    }

    #[Test]
    public function it_rejects_an_unknown_kind(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->matcher->match(collect(), ['kind' => 'country', 'value' => 'US']);
    }

    #[Test]
    public function it_rejects_a_missing_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->matcher->match(collect(), ['kind' => 'state', 'value' => '']);
    }
}
