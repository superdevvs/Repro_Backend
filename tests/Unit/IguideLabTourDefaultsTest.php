<?php

namespace Tests\Unit;

use App\Services\IguideLab\LabTourDefaults;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** tour-defaults/1 (spec 6): lighting rules, D1 grass/declutter, IDs, groups, applicability and assertCapture. */
class IguideLabTourDefaultsTest extends TestCase
{
    private const D1 = ['grassRunsEnabled' => false, 'declutterRunsEnabled' => false];
    private const ON = ['grassRunsEnabled' => true, 'declutterRunsEnabled' => true];
    private const FULL_SPHERE = ['yaw' => 0, 'pitch' => 0, 'hfov' => 360, 'vfov' => 180];

    private LabTourDefaults $defaults;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defaults = new LabTourDefaults;
    }

    /** @return array<string, array{0: array, 1: bool, 2: bool, 3: list<string>, 4: string, 5: string, 6: float}> */
    public static function goldenHourRules(): array
    {
        $model = 'Warm light through the window over the sink.';
        return [
            'G1 exterior with sky' => [['setting' => 'exterior', 'skyShare' => 0.3], true, true, ['exterior_with_sky'], 'Whole view', $model, 0.7],
            'G1 boundary 0.05' => [['setting' => 'exterior', 'skyShare' => 0.05], true, true, ['exterior_with_sky'], 'Whole view', $model, 0.7],
            'G2 exterior little sky' => [['setting' => 'exterior', 'skyShare' => 0.04, 'light' => ['windows' => false]], true, false, ['exterior_little_sky'], 'Whole view', $model, 0.7],
            'G3 no window no daylight' => [['light' => ['windows' => false, 'daylight' => false, 'windowKind' => 'none']], false, false, ['no_window'], 'Whole room',
                'No window, so there is no daylight to warm.', 0.95],
            'G4 indirect daylight' => [['light' => ['windows' => false, 'windowKind' => 'none', 'daylight' => true]], true, false, ['indirect_daylight'], 'Whole room', $model, 0.7],
            'G5 frosted' => [['light' => ['windowKind' => 'frosted', 'daylight' => false]], true, false, ['frosted_window'], 'Whole room', $model, 0.7],
            'G6 small' => [['light' => ['windowKind' => 'small', 'daylightStrength' => 'weak']], true, false, ['small_window'], 'Whole room', $model, 0.7],
            'G7 no daylight' => [['light' => ['daylight' => false]], true, false, ['weak_daylight'], 'Whole room', $model, 0.7],
            'G7 weak daylight' => [['light' => ['daylightStrength' => 'weak']], true, false, ['weak_daylight'], 'Whole room', $model, 0.7],
            'G8 daylight windows' => [['light' => ['windowKind' => 'large']], true, true, ['daylight_windows'], 'Whole room', $model, 0.7],
        ];
    }

    #[DataProvider('goldenHourRules')]
    public function test_golden_hour_rules(array $hints, bool $executable, bool $default, array $reasons, string $label, string $description, float $confidence): void
    {
        $built = $this->defaults->build($this->surveyResult($hints), 7, self::D1);
        $g = $this->candidate($built, 'g7');
        $this->assertSame([$executable, $default, $reasons, $label, $description, $confidence],
            [$g['executable'], $g['defaultSelected'], $g['reasons'], $g['label'], $g['description'], $g['confidence']]);
        $expected = $default ? 'recommended' : ($executable ? 'possible' : 'not_applicable');
        $this->assertSame(['status' => $expected, 'reasons' => $reasons], $built['applicability']['golden-hour']);
        $this->defaults->assertCapture($built);
    }

    /** @return array<string, array{0: array, 1: bool, 2: bool, 3: list<string>, 4: string, 5: float}> */
    public static function twilightRules(): array
    {
        $model = 'Blue hour in the window; pendants on.';
        return [
            'T1 exterior' => [['setting' => 'exterior', 'skyShare' => 0.4], false, false, ['outdoor_twilight_pending'], 'Outdoor twilight comes in a later release.', 0.95],
            'T2 no window, fixtures off' => [['light' => ['windows' => false, 'windowKind' => 'none', 'lampsOn' => false]], false, false, ['no_window', 'no_lamps_on'],
                'No window or lamp light to work with.', 0.95],
            'T2 no window, no fixtures' => [['light' => ['windows' => false, 'windowKind' => 'none', 'lampsOn' => false, 'lightFixtures' => false]], false, false,
                ['no_window', 'no_lamps'], 'No window or lamp light to work with.', 0.95],
            'T3 lamps only' => [['light' => ['windows' => false, 'windowKind' => 'none']], true, false, ['lamps_only'], $model, 0.6],
            'T4 frosted' => [['light' => ['windowKind' => 'frosted', 'lampsOn' => false]], true, false, ['frosted_window'], $model, 0.6],
            'T5 small' => [['light' => ['windowKind' => 'small']], true, false, ['small_window'], $model, 0.6],
            'T6 fixtures off' => [['light' => ['lampsOn' => false]], true, false, ['no_lamps_on'], $model, 0.6],
            'T6 no fixtures' => [['light' => ['lampsOn' => false, 'lightFixtures' => false]], true, false, ['no_lamps'], $model, 0.6],
            'T7 windows and lamps' => [[], true, true, ['windows_and_lamps'], $model, 0.6],
        ];
    }

    #[DataProvider('twilightRules')]
    public function test_twilight_rules(array $hints, bool $executable, bool $default, array $reasons, string $description, float $confidence): void
    {
        $built = $this->defaults->build($this->surveyResult($hints), 3, self::D1);
        $t = $this->candidate($built, 't3');
        $label = ($hints['setting'] ?? 'interior') === 'exterior' ? 'Whole view' : 'Whole room';
        $this->assertSame([$executable, $default, $reasons, $label, $description, $confidence],
            [$t['executable'], $t['defaultSelected'], $t['reasons'], $t['label'], $t['description'], $t['confidence']]);
        $expected = $default ? 'recommended' : ($executable ? 'possible' : 'not_applicable');
        $this->assertSame(['status' => $expected, 'reasons' => $reasons], $built['applicability']['twilight']);
        $this->defaults->assertCapture($built);
    }

    public function test_lighting_descriptions_are_cleaned_with_fallbacks(): void
    {
        $unsafe = ['lighting' => ['goldenHour' => ['description' => 'See https://example.test'], 'twilight' => ['description' => 'Your API key glows']]];
        $interior = $this->defaults->build($this->surveyResult($unsafe), 0, self::D1);
        $this->assertSame('Warm evening light through the windows.', $this->candidate($interior, 'g0')['description']);
        $this->assertSame('Blue hour outside the windows; lamps glow warm.', $this->candidate($interior, 't0')['description']);
        $exterior = $this->defaults->build($this->surveyResult($unsafe + ['setting' => 'exterior', 'skyShare' => 0.5]), 0, self::D1);
        $this->assertSame('Warm, low evening light across the whole view.', $this->candidate($exterior, 'g0')['description']);

        $long = $this->defaults->build($this->surveyResult(['lighting' => ['goldenHour' => ['description' => str_repeat('Warm glow ', 30)]]]), 0, self::D1);
        $description = $this->candidate($long, 'g0')['description'];
        $this->assertSame(160, mb_strlen($description));
        $this->assertStringEndsWith("\u{2026}", $description);
        $this->defaults->assertCapture($long);
    }

    public function test_d1_grass_and_declutter_are_never_executable(): void
    {
        $built = $this->defaults->build($this->exteriorWithEverything(), 4, self::D1);
        $grass = $this->candidates($built, 'grass');
        $declutter = $this->candidates($built, 'declutter');
        $this->assertCount(3, $grass);
        $this->assertCount(7, $declutter);
        foreach (array_merge($grass, $declutter) as $candidate) {
            $this->assertFalse($candidate['executable'], $candidate['id']);
            $this->assertFalse($candidate['defaultSelected'], $candidate['id']);
            $this->assertSame('preset_runs_not_enabled', $candidate['reasons'][0], $candidate['id']);
        }
        // Grass: small area (hfov*vfov < 150) and low confidence (< 0.6) add their reasons.
        $this->assertSame(['preset_runs_not_enabled'], $grass[0]['reasons']);
        $this->assertSame(['preset_runs_not_enabled', 'small_lawn_area'], $grass[1]['reasons']);
        $this->assertSame(['preset_runs_not_enabled', 'small_lawn_area', 'low_confidence'], $grass[2]['reasons']);
        $this->assertSame(['not_applicable', ['preset_runs_not_enabled', 'lawn_found']],
            [$built['applicability']['grass']['status'], $built['applicability']['grass']['reasons']]);
        // Declutter: glass and low confidence (< 0.5) add their reasons; groups exist but stay consistent.
        $byLabel = array_column($declutter, null, 'label');
        $this->assertSame(['preset_runs_not_enabled', 'glass_or_mirror'], $byLabel['Glass vase']['reasons']);
        $this->assertSame(['preset_runs_not_enabled', 'low_confidence'], $byLabel['Charger']['reasons']);
        $this->assertSame(['preset_runs_not_enabled'], $byLabel['Dish soap']['reasons']);
        $this->assertSame('p4a', $byLabel['Dish soap']['groupId']);
        $this->assertSame('p4a', $byLabel['Sponge']['groupId']);
        $this->assertSame(['not_applicable', ['preset_runs_not_enabled', 'loose_items_found']],
            [$built['applicability']['declutter']['status'], $built['applicability']['declutter']['reasons']]);
        // Grass and declutter carry the survey origin and the item details.
        $this->assertSame(['grass', 'lawn_area', 'survey', 'Front lawn', 'AI guess: front of the house.', null],
            [$grass[0]['preset'], $grass[0]['kind'], $grass[0]['origin'], $grass[0]['label'], $grass[0]['description'], $grass[0]['groupId']]);
        $this->assertSame(['AI guess: back of the house.', 'AI guess: position not certain.'], [$grass[1]['description'], $grass[2]['description']]);
        $this->assertSame(['declutter', 'item', 'survey', 'By the sink'], [$byLabel['Dish soap']['preset'], $byLabel['Dish soap']['kind'],
            $byLabel['Dish soap']['origin'], $byLabel['Dish soap']['description']]);
        $this->defaults->assertCapture($built);

        // The other applicability reasons of 6.3 and 6.4.
        $interior = $this->defaults->build($this->surveyResult(), 1, self::D1);
        $this->assertSame(['status' => 'not_applicable', 'reasons' => ['interior']], $interior['applicability']['grass']);
        $this->assertSame(['status' => 'not_applicable', 'reasons' => ['no_loose_items']], $interior['applicability']['declutter']);
        $none = $this->defaults->build($this->surveyResult(['setting' => 'exterior', 'skyShare' => 0.5]), 1, self::D1);
        $this->assertSame(['status' => 'not_applicable', 'reasons' => ['no_lawn_found']], $none['applicability']['grass']);
        $uncertain = $this->defaults->build($this->surveyResult(['setting' => 'exterior', 'lawn' => ['status' => 'uncertain', 'condition' => null, 'areas' => []]]), 1, self::D1);
        $this->assertSame(['status' => 'not_applicable', 'reasons' => ['lawn_uncertain']], $uncertain['applicability']['grass']);
        $uncertainArea = $this->surveyResult(['setting' => 'exterior']);
        $uncertainArea['lawn'] = ['status' => 'uncertain', 'condition' => null, 'areas' => [$this->area('Side strip', 'side', 50, 20, 0.5)]];
        $this->assertSame(['status' => 'not_applicable', 'reasons' => ['preset_runs_not_enabled', 'lawn_uncertain']],
            $this->defaults->build($uncertainArea, 1, self::D1)['applicability']['grass']);
    }

    public function test_provisional_flag_on_rules_for_grass_and_declutter(): void
    {
        $built = $this->defaults->build($this->exteriorWithEverything(), 4, self::ON);
        [$big, $small, $weak] = $this->candidates($built, 'grass');
        $this->assertSame([true, true, []], [$big['executable'], $big['defaultSelected'], $big['reasons']]);
        $this->assertSame([true, false, ['small_lawn_area']], [$small['executable'], $small['defaultSelected'], $small['reasons']]);
        $this->assertSame([true, false, ['small_lawn_area', 'low_confidence']], [$weak['executable'], $weak['defaultSelected'], $weak['reasons']]);
        $this->assertSame(['status' => 'recommended', 'reasons' => ['lawn_found']], $built['applicability']['grass']);
        $byLabel = array_column($this->candidates($built, 'declutter'), null, 'label');
        $this->assertSame([false, false, ['glass_or_mirror']], [$byLabel['Glass vase']['executable'], $byLabel['Glass vase']['defaultSelected'], $byLabel['Glass vase']['reasons']]);
        $this->assertSame([true, false, ['low_confidence']], [$byLabel['Charger']['executable'], $byLabel['Charger']['defaultSelected'], $byLabel['Charger']['reasons']]);
        $this->assertSame([true, true, []], [$byLabel['Keys']['executable'], $byLabel['Keys']['defaultSelected'], $byLabel['Keys']['reasons']]);
        $this->assertSame(['status' => 'recommended', 'reasons' => ['loose_items_found']], $built['applicability']['declutter']);
        $this->defaults->assertCapture($built);
    }

    public function test_ids_follow_the_order_scheme_and_are_unique_across_80_captures(): void
    {
        $candidateIds = $surfaceIds = $groupIds = $areaIds = [];
        for ($order = 0; $order < 80; $order++) {
            $built = $this->defaults->build($this->maximal(), $order, $order % 2 ? self::ON : self::D1);
            $this->defaults->assertCapture($built);
            $this->assertCount(29, $built['candidates']);
            $ids = array_column($built['candidates'], 'id');
            $this->assertSame(['g'.$order, 't'.$order], array_slice($ids, 0, 2));
            $this->assertSame(array_map(fn ($l) => 'l'.$order.$l, ['a', 'b', 'c']), array_slice($ids, 2, 3));
            $this->assertSame(array_map(fn ($l) => 'd'.$order.$l, range('a', 'x')), array_slice($ids, 5));
            $this->assertSame(array_map(fn ($l) => 's'.$order.$l, ['a', 'b', 'c', 'd']), array_column($built['survey']['clutter']['surfaces'], 'id'));
            $this->assertSame(array_column(array_slice($built['candidates'], 2, 3), 'id'), array_column($built['survey']['lawn']['areas'], 'id'));
            $groups = array_values(array_unique(array_filter(array_column($built['candidates'], 'groupId'))));
            $this->assertSame(array_map(fn ($l) => 'p'.$order.$l, range('a', 'l')), $groups, 'groups in order of their first member');
            array_push($candidateIds, ...$ids);
            array_push($surfaceIds, ...array_column($built['survey']['clutter']['surfaces'], 'id'));
            array_push($areaIds, ...array_column($built['survey']['lawn']['areas'], 'id'));
            array_push($groupIds, ...$groups);
        }
        foreach ([$candidateIds, $surfaceIds, $groupIds, $areaIds] as $list) {
            $this->assertSame(count($list), count(array_unique($list)));
            foreach ($list as $id) $this->assertMatchesRegularExpression('/\A[a-z0-9]{1,8}\z/', $id);
        }
        $this->assertSame(80 * 29, count($candidateIds));
        $this->assertSame(4, max(array_map('strlen', $candidateIds)));
    }

    public function test_groups_need_two_members_and_share_flags(): void
    {
        $result = $this->surveyResult();
        $result['clutter']['surfaces'] = [
            $this->surface('Counter', [
                $this->item('Keys', 0.9, false, 1),        // default
                $this->item('Mail', 0.6, false, 1),        // executable, not default: the group cannot be default
                $this->item('Remote', 0.9, false, 2),      // singleton: no group
                $this->item('Mirror tray', 0.9, true, 3),  // glass: not executable
                $this->item('Bottle', 0.9, false, 3),
            ]),
            $this->surface('Table', [$this->item('Cup', 0.9, false, 1), $this->item('Plate', 0.9, false, 1)]),
        ];
        $on = $this->defaults->build($result, 9, self::ON);
        $byLabel = array_column($this->candidates($on, 'declutter'), null, 'label');
        $this->assertSame(['p9a', true, false, ['group_member_not_selectable']], $this->flags($byLabel['Keys']));
        $this->assertSame(['p9a', true, false, []], $this->flags($byLabel['Mail']));
        $this->assertSame([null, true, true, []], $this->flags($byLabel['Remote']));
        $this->assertSame(['p9b', false, false, ['glass_or_mirror']], $this->flags($byLabel['Mirror tray']));
        $this->assertSame(['p9b', false, false, ['group_member_not_selectable']], $this->flags($byLabel['Bottle']));
        // The same touchGroup number on another surface is another group.
        $this->assertSame(['p9c', true, true, []], $this->flags($byLabel['Cup']));
        $this->assertSame(['p9c', true, true, []], $this->flags($byLabel['Plate']));
        $this->defaults->assertCapture($on);

        // D1: groups are formed and trivially consistent; no member changes, so no extra reason.
        $d1 = array_column($this->candidates($this->defaults->build($result, 9, self::D1), 'declutter'), null, 'label');
        $this->assertSame(['p9a', false, false, ['preset_runs_not_enabled']], $this->flags($d1['Keys']));
        $this->assertSame([null, false, false, ['preset_runs_not_enabled']], $this->flags($d1['Remote']));
    }

    public function test_applicability_is_derived_from_candidates(): void
    {
        foreach ([[$this->exteriorWithEverything(), self::D1], [$this->exteriorWithEverything(), self::ON], [$this->maximal(), self::ON],
            [$this->surveyResult(['light' => ['windows' => false, 'daylight' => false, 'lampsOn' => false, 'windowKind' => 'none']]), self::D1]] as [$result, $flags]) {
            $built = $this->defaults->build($result, 2, $flags);
            $this->assertSame(['golden-hour', 'twilight', 'grass', 'declutter'], array_keys($built['applicability']));
            foreach ($built['applicability'] as $preset => $applicability) {
                $list = $this->candidates($built, $preset);
                $expected = array_filter($list, fn ($c) => $c['defaultSelected']) ? 'recommended'
                    : (array_filter($list, fn ($c) => $c['executable']) ? 'possible' : 'not_applicable');
                $this->assertSame($expected, $applicability['status'], $preset);
                $this->assertTrue(array_is_list($applicability['reasons']));
                $this->assertLessThanOrEqual(8, count($applicability['reasons']));
                if ($expected === 'not_applicable') $this->assertNotEmpty($applicability['reasons'], $preset);
            }
        }
        // Flag on with only glass items: nothing executable, so not_applicable with low_confidence.
        $glass = $this->surveyResult();
        $glass['clutter']['surfaces'] = [$this->surface('Shelf', [$this->item('Glass', 0.9, true, 0)])];
        $this->assertSame(['status' => 'not_applicable', 'reasons' => ['low_confidence']], $this->defaults->build($glass, 0, self::ON)['applicability']['declutter']);
        $weak = $this->surveyResult();
        $weak['clutter']['surfaces'] = [$this->surface('Shelf', [$this->item('Toy', 0.55, false, 0)])];
        $this->assertSame(['status' => 'possible', 'reasons' => ['low_confidence']], $this->defaults->build($weak, 0, self::ON)['applicability']['declutter']);
    }

    public function test_whole_room_is_a_rule_over_the_full_sphere_without_outline(): void
    {
        foreach ([$this->surveyResult(), $this->exteriorWithEverything()] as $result) {
            $built = $this->defaults->build($result, 5, self::D1);
            foreach (['g5', 't5'] as $id) {
                $candidate = $this->candidate($built, $id);
                $this->assertSame(['id', 'groupId', 'preset', 'kind', 'label', 'description', 'origin', 'region', 'confidence', 'executable', 'defaultSelected', 'reasons'],
                    array_keys($candidate));
                $this->assertSame(['whole_room', 'rule', null, self::FULL_SPHERE], [$candidate['kind'], $candidate['origin'], $candidate['groupId'], $candidate['region']]);
                $this->assertArrayNotHasKey('outline', $candidate);
                $this->assertStringContainsString('"region":{"yaw":0,"pitch":0,"hfov":360,"vfov":180}', json_encode($candidate));
            }
        }
        foreach ($this->defaults->build($this->exteriorWithEverything(), 5, self::ON)['candidates'] as $candidate) {
            $this->assertArrayNotHasKey('outline', $candidate);
        }
    }

    public function test_contract_survey_object_hides_private_hints(): void
    {
        $result = $this->exteriorWithEverything();
        $result['notes'] = [['topic' => 'light', 'text' => 'Bright window.'], ['topic' => 'lawn', 'text' => 'Shaded lawn.']];
        $built = $this->defaults->build($result, 4, self::D1);
        $survey = $built['survey'];
        $this->assertSame(['space', 'skyShare', 'light', 'lawn', 'clutter', 'notes'], array_keys($survey));
        $this->assertSame(['setting' => 'exterior', 'label' => 'Front yard'], $survey['space']);
        $this->assertSame(['windows' => true, 'daylight' => true, 'lamps' => true, 'hardShadows' => false, 'mirrorsOrGlass' => false], $survey['light']);
        $this->assertSame(['status', 'condition', 'areas'], array_keys($survey['lawn']));
        $this->assertSame(['found', 'healthy'], [$survey['lawn']['status'], $survey['lawn']['condition']]);
        $this->assertSame(['id', 'label', 'position', 'region'], array_keys($survey['lawn']['areas'][0]));
        $this->assertSame(['id', 'label', 'region', 'itemIds'], array_keys($survey['clutter']['surfaces'][0]));
        $this->assertSame(['d4a', 'd4b', 'd4c', 'd4d', 'd4e'], $survey['clutter']['surfaces'][0]['itemIds']);
        $this->assertSame([['topic' => 'light', 'text' => 'Bright window.'], ['topic' => 'lawn', 'text' => 'Shaded lawn.']], $survey['notes']);
        $json = json_encode($built);
        foreach (['windowKind', 'daylightStrength', 'lightFixtures', 'lampsOn', 'touchGroup', 'glassOrMirror', 'warnings', 'goldenHour', 'tour-survey-result'] as $hint) {
            $this->assertStringNotContainsString($hint, $json);
        }
    }

    /** @return array<string, array{0: \Closure}> */
    public static function violations(): array
    {
        $at = fn (array &$c, string $id) => array_search($id, array_column($c['candidates'], 'id'), true);
        return [
            'two golden-hour candidates' => [function (array &$c) use ($at) { $copy = $c['candidates'][0]; $copy['id'] = 'g99'; $c['candidates'][] = $copy; }],
            'missing twilight candidate' => [function (array &$c) { array_splice($c['candidates'], 1, 1); }],
            'kind does not match preset' => [function (array &$c) use ($at) { $c['candidates'][$at($c, 'l4a')]['kind'] = 'item'; }],
            'whole room not over the full sphere' => [function (array &$c) { $c['candidates'][0]['region']['hfov'] = 359; }],
            'whole room with an outline' => [function (array &$c) { $c['candidates'][0]['outline'] = [[0, 0], [1, 1], [2, 0]]; }],
            'whole room not a rule' => [function (array &$c) { $c['candidates'][0]['origin'] = 'survey'; }],
            'lawn area not from the survey' => [function (array &$c) use ($at) { $c['candidates'][$at($c, 'l4a')]['origin'] = 'rule'; }],
            'item from a rule' => [function (array &$c) use ($at) { $c['candidates'][$at($c, 'd4a')]['origin'] = 'rule'; }],
            'grouped whole room' => [function (array &$c) { $c['candidates'][0]['groupId'] = 'p4z'; }],
            'default without executable' => [function (array &$c) use ($at) { $c['candidates'][$at($c, 'd4e')]['defaultSelected'] = true; }],
            'non-executable without reason' => [function (array &$c) use ($at) { $c['candidates'][$at($c, 'd4e')]['reasons'] = []; }],
            'duplicate candidate id' => [function (array &$c) use ($at) { $c['candidates'][$at($c, 'd4b')]['id'] = 'd4a'; }],
            'lawn area without grass candidate' => [function (array &$c) { $c['survey']['lawn']['areas'][0]['id'] = 'l4z'; }],
            'item on no surface' => [function (array &$c) { array_pop($c['survey']['clutter']['surfaces'][0]['itemIds']); }],
            'item on two surfaces' => [function (array &$c) { $c['survey']['clutter']['surfaces'][1]['itemIds'][] = 'd4a'; }],
            'duplicate surface id' => [function (array &$c) { $c['survey']['clutter']['surfaces'][1]['id'] = 's4a'; }],
            'more than 24 items' => [function (array &$c) {
                $template = $c['candidates'][count($c['candidates']) - 1];
                for ($i = 0; $i < 18; $i++) { $item = $template; $item['id'] = 'd4z'.$i; $item['groupId'] = null; $c['candidates'][] = $item; $c['survey']['clutter']['surfaces'][1]['itemIds'][] = $item['id']; }
            }],
            'group of one' => [function (array &$c) use ($at) { $c['candidates'][$at($c, 'd4b')]['groupId'] = null; }],
            'group members disagree' => [function (array &$c) use ($at) { $c['candidates'][$at($c, 'd4b')]['executable'] = true; $c['candidates'][$at($c, 'd4b')]['reasons'] = []; }],
            'group of another preset' => [function (array &$c) use ($at) { $c['candidates'][$at($c, 'l4a')]['groupId'] = 'p4a'; }],
            'applicability not derived' => [function (array &$c) { $c['applicability']['grass']['status'] = 'possible'; }],
            'not applicable without reason' => [function (array &$c) { $c['applicability']['declutter']['reasons'] = []; }],
            'lawn none with areas' => [function (array &$c) { $c['survey']['lawn']['status'] = 'none'; $c['survey']['lawn']['condition'] = null; }],
            'lawn none with condition' => [function (array &$c) {
                $c['survey']['lawn'] = ['status' => 'none', 'condition' => 'healthy', 'areas' => []];
                $c['candidates'] = array_values(array_filter($c['candidates'], fn ($x) => $x['preset'] !== 'grass'));
                $c['applicability']['grass'] = ['status' => 'not_applicable', 'reasons' => ['no_lawn_found']];
            }],
            'lawn found without areas' => [function (array &$c) {
                $c['survey']['lawn'] = ['status' => 'found', 'condition' => null, 'areas' => []];
                $c['candidates'] = array_values(array_filter($c['candidates'], fn ($x) => $x['preset'] !== 'grass'));
                $c['applicability']['grass'] = ['status' => 'not_applicable', 'reasons' => ['lawn_found']];
            }],
            'duplicate lawn area id' => [function (array &$c) use ($at) { $c['survey']['lawn']['areas'][1]['id'] = 'l4a'; $c['candidates'][$at($c, 'l4b')]['id'] = 'l4a'; }],
            'condition not a reason code' => [function (array &$c) { $c['survey']['lawn']['condition'] = 'Healthy!'; }],
            'reasons not a list' => [function (array &$c) use ($at) { $c['candidates'][$at($c, 'l4c')]['reasons'] = [0 => 'preset_runs_not_enabled', 2 => 'low_confidence']; }],
            'notes not a list' => [function (array &$c) { $c['survey']['notes'] = [1 => ['topic' => 'light', 'text' => 'Bright window.']]; }],
            'duplicate reason' => [function (array &$c) { $c['candidates'][0]['reasons'] = ['exterior_with_sky', 'exterior_with_sky']; }],
            'more than 8 reasons' => [function (array &$c) { $c['candidates'][0]['reasons'] = array_map(fn ($i) => 'r'.$i, range(1, 9)); }],
            'reason not a code' => [function (array &$c) { $c['candidates'][0]['reasons'] = ['Exterior sky']; }],
            'unsafe label' => [function (array &$c) use ($at) { $c['candidates'][$at($c, 'd4a')]['label'] = 'Card with https://example.test'; }],
            'label over 60 units' => [function (array &$c) { $c['survey']['space']['label'] = str_repeat('a', 61); }],
            'empty label' => [function (array &$c) { $c['survey']['clutter']['surfaces'][0]['label'] = ''; }],
            'description over 160 units' => [function (array &$c) { $c['candidates'][0]['description'] = str_repeat('a', 161); }],
            'control character in a note' => [function (array &$c) { $c['survey']['notes'] = [['topic' => 'light', 'text' => "Bright\nwindow"]]; }],
            'note topic unknown' => [function (array &$c) { $c['survey']['notes'] = [['topic' => 'sky', 'text' => 'Blue.']]; }],
            'yaw 180' => [function (array &$c) use ($at) { $c['candidates'][$at($c, 'd4a')]['region']['yaw'] = 180; }],
            'pitch past a pole' => [function (array &$c) use ($at) { $c['candidates'][$at($c, 'd4a')]['region'] = ['yaw' => 0, 'pitch' => 80, 'hfov' => 10, 'vfov' => 22]; }],
            'zero hfov' => [function (array &$c) { $c['survey']['clutter']['surfaces'][0]['region']['hfov'] = 0; }],
            'region with an extra key' => [function (array &$c) { $c['survey']['lawn']['areas'][0]['region']['roll'] = 0; }],
            'candidate with an extra key' => [function (array &$c) { $c['candidates'][0]['note'] = 'x'; }],
            'candidate without a key' => [function (array &$c) { unset($c['candidates'][0]['confidence']); }],
            'confidence above 1' => [function (array &$c) { $c['candidates'][0]['confidence'] = 1.2; }],
            'string boolean' => [function (array &$c) { $c['survey']['light']['windows'] = 'true'; }],
            'sky share above 1' => [function (array &$c) { $c['survey']['skyShare'] = 1.5; }],
            'unknown setting' => [function (array &$c) { $c['survey']['space']['setting'] = 'garage'; }],
            'unknown lawn position' => [function (array &$c) { $c['survey']['lawn']['areas'][0]['position'] = 'left'; }],
            'survey with an extra key' => [function (array &$c) { $c['survey']['warnings'] = []; }],
            'applicability without a preset' => [function (array &$c) { unset($c['applicability']['declutter']); }],
            'short id pattern' => [function (array &$c) use ($at) { $c['candidates'][$at($c, 'd4a')]['id'] = 'D4A'; $c['survey']['clutter']['surfaces'][0]['itemIds'][0] = 'D4A'; }],
            'surface without items' => [function (array &$c) { $c['survey']['clutter']['surfaces'][] = ['id' => 's4z', 'label' => 'Shelf', 'region' => ['yaw' => 0, 'pitch' => 0, 'hfov' => 10, 'vfov' => 10], 'itemIds' => []]; }],
            'too many candidates' => [function (array &$c) {
                $template = $c['candidates'][2];
                for ($i = 0; $i < 25; $i++) { $copy = $template; $copy['id'] = 'l4z'.$i; $c['candidates'][] = $copy; $c['survey']['lawn']['areas'][] = $c['survey']['lawn']['areas'][0]; }
            }],
        ];
    }

    #[DataProvider('violations')]
    public function test_assert_capture_rejects_each_contract_violation(\Closure $mutate): void
    {
        $capture = $this->defaults->build($this->exteriorWithEverything(), 4, self::D1);
        $this->defaults->assertCapture($capture);
        $mutate($capture);
        $this->expectException(\UnexpectedValueException::class);
        $this->defaults->assertCapture($capture);
    }

    public function test_assert_capture_accepts_a_capture_dto_and_names_only_the_field_path(): void
    {
        $built = $this->defaults->build($this->exteriorWithEverything(), 4, self::D1);
        $dto = ['caseId' => '8e6469ce-3440-4fcc-8cda-e30da915d677', 'order' => 4, 'roomName' => 'Yard', 'floorName' => null, 'status' => 'surveyed', 'error' => null] + $built;
        $this->defaults->assertCapture($dto);
        $dto['candidates'][5]['label'] = 'Secret drawer key';
        try {
            $this->defaults->assertCapture($dto);
            $this->fail('An unsafe label must be rejected.');
        } catch (\UnexpectedValueException $e) {
            $this->assertStringContainsString('candidates.5.label', $e->getMessage());
            $this->assertStringNotContainsString('Secret', $e->getMessage(), 'no model text in the error');
        }
    }

    // ------------------------------------------------------------------ builders

    private function surveyResult(array $hints = []): array
    {
        $setting = $hints['setting'] ?? 'interior';
        return [
            'version' => 'tour-survey-result/1',
            'space' => ['setting' => $setting, 'label' => $setting === 'exterior' ? 'Front yard' : 'Kitchen', 'confidence' => 0.95],
            'skyShare' => $hints['skyShare'] ?? 0.02,
            'light' => array_replace(['windows' => true, 'windowKind' => 'normal', 'daylight' => true, 'daylightStrength' => 'good', 'lightFixtures' => true,
                'lampsOn' => true, 'hardShadows' => false, 'mirrorsOrGlass' => false], $hints['light'] ?? []),
            'lighting' => [
                'goldenHour' => array_replace(['description' => 'Warm light through the window over the sink.', 'confidence' => 0.7], $hints['lighting']['goldenHour'] ?? []),
                'twilight' => array_replace(['description' => 'Blue hour in the window; pendants on.', 'confidence' => 0.6], $hints['lighting']['twilight'] ?? []),
            ],
            'lawn' => $hints['lawn'] ?? ['status' => 'none', 'condition' => null, 'areas' => []],
            'clutter' => ['surfaces' => []],
            'notes' => [],
            'warnings' => [],
        ];
    }

    private function area(string $label, string $position, float $hfov, float $vfov, float $confidence): array
    {
        return ['label' => $label, 'position' => $position, 'region' => ['yaw' => 10.0, 'pitch' => -40.0, 'hfov' => $hfov, 'vfov' => $vfov], 'confidence' => $confidence];
    }

    private function item(string $label, float $confidence, bool $glass, int $touchGroup): array
    {
        return ['label' => $label, 'description' => $label === 'Dish soap' ? 'By the sink' : '', 'region' => ['yaw' => -45.0, 'pitch' => -18.0, 'hfov' => 3.0, 'vfov' => 4.0],
            'confidence' => $confidence, 'glassOrMirror' => $glass, 'touchGroup' => $touchGroup];
    }

    private function surface(string $label, array $items): array
    {
        return ['label' => $label, 'region' => ['yaw' => -40.0, 'pitch' => -20.0, 'hfov' => 40.0, 'vfov' => 12.0], 'items' => $items];
    }

    /** Exterior with 3 lawn areas (big, small, small and weak) and 2 surfaces carrying 7 items (one touch group). */
    private function exteriorWithEverything(): array
    {
        $result = $this->surveyResult(['setting' => 'exterior', 'skyShare' => 0.35]);
        $result['lawn'] = ['status' => 'found', 'condition' => 'healthy', 'areas' => [
            $this->area('Front lawn', 'front', 120, 30, 0.9),
            $this->area('Back strip', 'back', 20, 7, 0.8),
            $this->area('Corner', 'unknown', 10, 10, 0.4),
        ]];
        $result['clutter']['surfaces'] = [
            $this->surface('Patio table', [$this->item('Dish soap', 0.9, false, 1), $this->item('Sponge', 0.88, false, 1), $this->item('Keys', 0.9, false, 0),
                $this->item('Charger', 0.45, false, 0), $this->item('Glass vase', 0.9, true, 0)]),
            $this->surface('Bench', [$this->item('Toy', 0.8, false, 2), $this->item('Ball', 0.8, false, 2)]),
        ];
        return $result;
    }

    /** 3 lawn areas, 4 surfaces, 24 items in 12 touch groups of 2. */
    private function maximal(): array
    {
        $result = $this->surveyResult(['setting' => 'exterior', 'skyShare' => 0.2]);
        $result['lawn'] = ['status' => 'found', 'condition' => 'patchy', 'areas' => [
            $this->area('Front lawn', 'front', 120, 30, 0.9), $this->area('Side lawn', 'side', 60, 20, 0.7), $this->area('Back lawn', 'back', 100, 25, 0.8)]];
        $surfaces = [];
        for ($s = 0; $s < 4; $s++) {
            $items = [];
            for ($i = 0; $i < 6; $i++) $items[] = $this->item('Item '.$s.$i, 0.9, false, intdiv($i, 2) + 1);
            $surfaces[] = $this->surface('Surface '.$s, $items);
        }
        $result['clutter']['surfaces'] = $surfaces;
        return $result;
    }

    private function candidate(array $built, string $id): array
    {
        foreach ($built['candidates'] as $candidate) if ($candidate['id'] === $id) return $candidate;
        $this->fail("Candidate $id not found.");
    }

    private function candidates(array $built, string $preset): array
    {
        return array_values(array_filter($built['candidates'], fn ($c) => $c['preset'] === $preset));
    }

    private function flags(array $candidate): array
    {
        return [$candidate['groupId'], $candidate['executable'], $candidate['defaultSelected'], $candidate['reasons']];
    }
}
