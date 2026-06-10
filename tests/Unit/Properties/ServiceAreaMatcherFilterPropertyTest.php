<?php

namespace Tests\Unit\Properties;

use App\Models\ServiceArea;
use App\Models\User;
use App\Services\ServiceAreaMatcher;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Property test for ServiceAreaMatcher::match (Req 10.2 — Property 4).
 *
 * Property 4 — Filtering returns EXACTLY the matching photographers:
 * for any randomly generated set of photographers each carrying a random
 * subset of (kind, value) service areas, ServiceAreaMatcher::match must
 * return exactly the photographers that satisfy the filter — no false
 * positives and no false negatives — regardless of input order, area-list
 * size, or value casing.
 *
 * Validates: Requirements 10.2
 */
class ServiceAreaMatcherFilterPropertyTest extends TestCase
{
    private ServiceAreaMatcher $matcher;

    /**
     * Pool of candidate values per kind, mixing casings so the case-insensitive
     * value match is genuinely exercised by the random generator.
     *
     * @var array<string, list<string>>
     */
    private array $valuePool = [
        ServiceArea::KIND_REGION => ['Northeast', 'southeast', 'MIDWEST', 'West', 'Pacific'],
        ServiceArea::KIND_STATE  => ['MD', 'va', 'Ny', 'TX', 'ca'],
        ServiceArea::KIND_AREA   => ['DC Metro', 'baltimore', 'NORTHERN VA', 'Bay Area', 'austin'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new ServiceAreaMatcher();
    }

    #[Test]
    public function match_returns_exactly_the_photographers_satisfying_the_filter(): void
    {
        $iterations = 20;

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            // Deterministic seed lets a failing iteration be reproduced from the
            // assertion message alone.
            mt_srand($iteration * 7919 + 13);

            $photographers = $this->randomPhotographers(mt_rand(5, 30));
            $filter        = $this->randomFilter();

            $expectedIds = $this->expectedMatchIds($photographers, $filter);
            $actualIds   = $this->matchedIds($this->matcher->match($photographers, $filter));

            // No false positives and no false negatives, regardless of input
            // order, area-list size, or value casing.
            $this->assertSame(
                $expectedIds,
                $actualIds,
                "Iteration {$iteration}: matcher result diverged from the reference filter — "
                    .'filter='.json_encode($filter)
                    .' photographers='.json_encode($this->summarize($photographers))
            );

            // Order-invariance: shuffling the same input must yield the same set.
            $shuffledIds = $this->matchedIds($this->matcher->match($photographers->shuffle(), $filter));
            $this->assertSame(
                $expectedIds,
                $shuffledIds,
                "Iteration {$iteration}: matcher is order-sensitive — filter=".json_encode($filter)
            );
        }
    }

    /**
     * Build a randomly generated photographer collection sized $count, each
     * carrying a random subset of (kind, value) areas drawn from the value pool.
     */
    private function randomPhotographers(int $count): Collection
    {
        $photographers = collect();
        for ($id = 1; $id <= $count; $id++) {
            $photographers->push($this->photographer($id, $this->randomAreaSubset()));
        }

        return $photographers;
    }

    /**
     * Random subset of areas, sized 0..6 — exercises empty-area, single-area,
     * and many-area photographers. Casing is randomly perturbed so the
     * matcher's case-insensitive comparison is meaningfully tested.
     *
     * @return list<array{kind: string, value: string}>
     */
    private function randomAreaSubset(): array
    {
        $size  = mt_rand(0, 6);
        $areas = [];
        for ($i = 0; $i < $size; $i++) {
            $kind   = ServiceArea::KINDS[mt_rand(0, count(ServiceArea::KINDS) - 1)];
            $values = $this->valuePool[$kind];
            $value  = $this->perturbCase($values[mt_rand(0, count($values) - 1)]);
            $areas[] = ['kind' => $kind, 'value' => $value];
        }

        return $areas;
    }

    /**
     * Random (kind, value) filter drawn from the same pool — possibly with
     * shifted casing so that filter casing does not have to align with stored
     * casing.
     *
     * @return array{kind: string, value: string}
     */
    private function randomFilter(): array
    {
        $kind   = ServiceArea::KINDS[mt_rand(0, count(ServiceArea::KINDS) - 1)];
        $values = $this->valuePool[$kind];
        $value  = $this->perturbCase($values[mt_rand(0, count($values) - 1)]);

        return ['kind' => $kind, 'value' => $value];
    }

    private function perturbCase(string $value): string
    {
        return match (mt_rand(0, 2)) {
            0       => strtolower($value),
            1       => strtoupper($value),
            default => $value,
        };
    }

    /**
     * Reference oracle: iterate the input directly and collect the IDs of
     * every photographer whose loaded service areas contain a match on
     * (kind, case-insensitive value). This is the property's specification —
     * the matcher's output must equal this set.
     *
     * @return list<int>
     */
    private function expectedMatchIds(Collection $photographers, array $filter): array
    {
        $expected = [];
        foreach ($photographers as $photographer) {
            foreach ($photographer->serviceAreas as $area) {
                if ($area->kind === $filter['kind']
                    && strcasecmp((string) $area->value, $filter['value']) === 0
                ) {
                    $expected[] = (int) $photographer->id;
                    break;
                }
            }
        }
        sort($expected);

        return $expected;
    }

    /**
     * @return list<int>
     */
    private function matchedIds(Collection $matched): array
    {
        return $matched->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Build an in-memory photographer with `serviceAreas` pre-set, mirroring
     * the in-memory pattern used by ServiceAreaMatcherTest so the matcher is
     * exercised without DB I/O.
     *
     * @param  list<array{kind: string, value: string}>  $areas
     */
    private function photographer(int $id, array $areas): User
    {
        $user = new User();
        $user->id = $id;

        $serviceAreas = collect($areas)->map(function (array $area) {
            $serviceArea = new ServiceArea();
            $serviceArea->kind  = $area['kind'];
            $serviceArea->value = $area['value'];

            return $serviceArea;
        });

        $user->setRelation('serviceAreas', $serviceAreas);

        return $user;
    }

    /**
     * @return list<array{id: int, areas: list<array{kind: string, value: string}>}>
     */
    private function summarize(Collection $photographers): array
    {
        return $photographers->map(fn (User $p) => [
            'id'    => (int) $p->id,
            'areas' => $p->serviceAreas->map(fn (ServiceArea $a) => [
                'kind'  => $a->kind,
                'value' => $a->value,
            ])->all(),
        ])->all();
    }
}
