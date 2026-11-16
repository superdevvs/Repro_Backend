<?php

namespace Tests\Unit;

use App\Services\IguideLab\LabTourSelections;
use PHPUnit\Framework\TestCase;

/**
 * Canonical selection text and sha256 (spec 7.4), identical to selectionCanonical() and effectiveSelection() of
 * tourContract.ts:381-424. The vectors are the contract fixtures, copied under tests/Fixtures/iguide-lab/tour-contract/.
 */
class IguideLabTourSelectionCanonicalTest extends TestCase
{
    private const PRESETS = ['golden-hour', 'twilight', 'grass', 'declutter'];
    private const RECORD_FIXTURES = ['fixture-lighting.json', 'fixture-declutter.json', 'fixture-grass.json'];

    /** The six vectors listed in spec 7.4. */
    private const SPEC_VECTORS = [
        'empty golden hour' => '3961292b2eff9c4bc35671b6b86c7028f5cf186876da97baf2673e8032928a70',
        'grass defaults' => 'c8f8669e3d8019513fd0ee8f965206be59e728851f095b3c915acb1b14075731',
        'declutter revision 2' => '48292999e7244e6bc416fcc4e8129f8131cb236a434ae9443a196fd476e19707',
        'twilight' => '7c59393c3c424575b0947812a730de4124973e46f3ae38d5a6ebfc3900ca6c09',
        'lighting golden hour revision 1' => 'cdcbc8989a2a851648e51d6aca88b5fe93f607b572c004d85e9bb8d703bd46e2',
        'declutter fixture golden hour' => '5a5dc23dae9ad41848213c638cdabbe298ce4a81240e24c6cc273db8e45d19e0',
    ];

    public function test_fixture_shas_recompute(): void
    {
        $recomputed = [];
        $checked = 0;
        $records = [];
        foreach (self::RECORD_FIXTURES as $file) $records[$file] = $this->fixture($file);
        // The create sample is a queued record: every selection is the empty one.
        $records['fixture-endpoints.json createResponse'] = $this->fixture('fixture-endpoints.json')['createResponse'];
        foreach ($records as $file => $record) {
            foreach (self::PRESETS as $preset) {
                $selection = $record['presets'][$preset]['selection'];
                $effective = LabTourSelections::effectiveOf($record['captures'], $preset, $selection['changes']);
                $sha = LabTourSelections::sha256($preset, $effective);
                $this->assertSame($selection['sha256'], $sha, "$file $preset");
                $this->assertSame(hash('sha256', LabTourSelections::canonical($preset, $effective)), $sha);
                $recomputed[$file.' '.$preset] = $sha;
                $checked++;

                // The estimate counts (selectionCounts, tourContract.ts:425) come from the same effective selection.
                $estimate = $record['presets'][$preset]['estimate'];
                if ($estimate !== null) {
                    $this->assertSame([$estimate['roomsSelected'], $estimate['itemsSelected']],
                        [count($effective), array_sum(array_map('count', $effective))], "$file $preset counts");
                }
            }
            // A run frozen at the current selection revision carries the current sha.
            foreach ($record['runs'] as $run) {
                if ($run['selectionRevision'] === $record['presets'][$run['preset']]['selection']['revision']) {
                    $this->assertSame($recomputed[$file.' '.$run['preset']], $run['selectionSha256'], "$file run {$run['id']}");
                    $checked++;
                }
            }
        }
        $this->assertSame(16 + 2, $checked, 'every preset hash of the four record samples and both lighting runs');

        foreach (self::SPEC_VECTORS as $name => $sha) $this->assertContains($sha, $recomputed, $name);
        $this->assertSame(self::SPEC_VECTORS['empty golden hour'], LabTourSelections::sha256('golden-hour', []));

        // The endpoint samples quote the same shas.
        $endpoints = $this->fixture('fixture-endpoints.json');
        foreach ([$endpoints['selectionResponse']['selection']['sha256'], $endpoints['runRequest']['selectionSha256'],
            $endpoints['runResponse']['selectionSha256']] as $sha) {
            $this->assertContains($sha, $recomputed);
        }
    }

    public function test_canonical_text_is_exact(): void
    {
        $a = '8E6469CE-3440-4FCC-8CDA-E30DA915D677';
        $b = '5f6bb9ca-1cbc-4ba4-be5b-a2b75e9b3ba5';
        $text = LabTourSelections::canonical('declutter', [$a => ['d6b', 'd6a'], $b => ['d15a'], 'c0000000-0000-4000-8000-000000000000' => []]);
        $this->assertSame("tour-selection/1\npreset=declutter\n5f6bb9ca-1cbc-4ba4-be5b-a2b75e9b3ba5=d15a\n8e6469ce-3440-4fcc-8cda-e30da915d677=d6a,d6b", $text);
        $this->assertSame("tour-selection/1\npreset=golden-hour", LabTourSelections::canonical('golden-hour', []));
    }

    public function test_digit_only_ids_sort_as_strings(): void
    {
        $case = '0e8d1a70-6d4c-4f0e-9d7a-3b9b8f0f2a11';
        // SORT_REGULAR would order these numerically (9 before 10); JS [].sort() compares code units.
        $this->assertSame("tour-selection/1\npreset=declutter\n$case=10,9", LabTourSelections::canonical('declutter', [$case => ['9', '10']]));
        $this->assertSame("tour-selection/1\npreset=declutter\n$case=1,10,9,a", LabTourSelections::canonical('declutter', [$case => ['9', 'a', '10', '1']]));
    }

    public function test_case_ids_are_lowercased(): void
    {
        $upper = ['B2C3D4E5-0000-4000-8000-000000000001' => ['g1'], 'A1B2C3D4-0000-4000-8000-000000000000' => ['g0']];
        $lower = array_change_key_case($upper, CASE_LOWER);
        $text = LabTourSelections::canonical('golden-hour', $upper);
        $this->assertSame("tour-selection/1\npreset=golden-hour\na1b2c3d4-0000-4000-8000-000000000000=g0\nb2c3d4e5-0000-4000-8000-000000000001=g1", $text);
        $this->assertSame(LabTourSelections::sha256('golden-hour', $lower), LabTourSelections::sha256('golden-hour', $upper));

        // effectiveOf keys by lowercase caseId whatever the case of the capture or the stored change.
        $captures = [['caseId' => 'A1B2C3D4-0000-4000-8000-000000000000', 'status' => 'surveyed', 'candidates' => [
            ['id' => 'g0', 'preset' => 'golden-hour', 'executable' => true, 'defaultSelected' => false],
        ]]];
        $effective = LabTourSelections::effectiveOf($captures, 'golden-hour', [['caseId' => 'a1b2c3d4-0000-4000-8000-000000000000', 'add' => ['g0'], 'remove' => []]]);
        $this->assertSame(['a1b2c3d4-0000-4000-8000-000000000000' => ['g0']], $effective);
    }

    public function test_effective_selection_follows_the_contract_port(): void
    {
        $candidate = fn (string $id, bool $executable, bool $default, string $preset = 'declutter') => ['id' => $id, 'preset' => $preset,
            'executable' => $executable, 'defaultSelected' => $default];
        $captures = [
            ['caseId' => 'aaaaaaaa-0000-4000-8000-000000000000', 'status' => 'surveyed', 'candidates' => [
                $candidate('d0a', true, true), $candidate('d0b', true, false), $candidate('d0c', false, false), $candidate('g0', true, true, 'golden-hour')]],
            ['caseId' => 'bbbbbbbb-0000-4000-8000-000000000000', 'status' => 'surveyed', 'candidates' => [$candidate('d1a', true, true)]],
            // Not surveyed: skipped even with stored candidates.
            ['caseId' => 'cccccccc-0000-4000-8000-000000000000', 'status' => 'failed', 'candidates' => [$candidate('d2a', true, true)]],
        ];
        $this->assertSame(['aaaaaaaa-0000-4000-8000-000000000000' => ['d0a'], 'bbbbbbbb-0000-4000-8000-000000000000' => ['d1a']],
            LabTourSelections::effectiveOf($captures, 'declutter', []));
        // remove, then add; a capture left with no tick is omitted.
        $this->assertSame(['aaaaaaaa-0000-4000-8000-000000000000' => ['d0b']], LabTourSelections::effectiveOf($captures, 'declutter', [
            ['caseId' => 'aaaaaaaa-0000-4000-8000-000000000000', 'add' => ['d0b'], 'remove' => ['d0a']],
            ['caseId' => 'bbbbbbbb-0000-4000-8000-000000000000', 'add' => [], 'remove' => ['d1a']],
        ]));
        $this->assertSame(['aaaaaaaa-0000-4000-8000-000000000000' => ['g0']], LabTourSelections::effectiveOf($captures, 'golden-hour', []));
    }

    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/iguide-lab/tour-contract/'.$name), true, 512, JSON_THROW_ON_ERROR);
    }
}
