<?php
namespace App\Services\IguideLab;

/**
 * tour-defaults/1 (spec 6): turns one normalised survey result (5.8, "tour-survey-result/1") into the contract survey
 * object, the per-preset applicability and the candidate list of the capture at tour position $order.
 *
 * Candidates are frozen when the capture becomes surveyed: the flags are passed in (captures.defaults_flags) and never
 * re-read from live configuration. In D1 both flags are false (LabTourConfig 3.2), so only lighting is executable.
 * IDs are server short IDs derived from the order (6.1), so they are unique per analysis.
 */
class LabTourDefaults
{
    public const VERSION = 'tour-defaults/1';
    public const PRESETS = ['golden-hour', 'twilight', 'grass', 'declutter'];

    /** tourContract.ts:109. */
    private const KIND_FOR = ['golden-hour' => 'whole_room', 'twilight' => 'whole_room', 'grass' => 'lawn_area', 'declutter' => 'item'];
    private const ORIGINS = ['rule', 'survey', 'inventory'];
    private const SHORT_ID = '/\A[a-z0-9]{1,8}\z/D';
    private const REASON_CODE = '/\A[a-z0-9_]{1,64}\z/D';
    private const LETTERS = 'abcdefghijklmnopqrstuvwx';
    private const MAX_AREAS = 3;
    private const MAX_SURFACES = 4;
    private const MAX_ITEMS_PER_SURFACE = 12;
    private const MAX_ITEMS = 24;
    private const MAX_CANDIDATES = 29;
    private const MAX_REASONS = 8;
    private const MAX_NOTES = 6;
    private const NOTE_TOPICS = ['space', 'light', 'lawn', 'clutter', 'general'];
    private const POSITIONS = ['front', 'back', 'side', 'unknown'];
    private const POSITION_TEXT = [
        'front' => 'AI guess: front of the house.',
        'back' => 'AI guess: back of the house.',
        'side' => 'AI guess: side of the house.',
        'unknown' => 'AI guess: position not certain.',
    ];
    private const CONDITIONS_DROPPED = ['none', 'unknown'];

    /** The run flags of D1, read once when a capture becomes surveyed and frozen in captures.defaults_flags (6). */
    public static function currentFlags(): array
    {
        return ['grassRunsEnabled' => LabTourConfig::grassRunsEnabled(), 'declutterRunsEnabled' => LabTourConfig::declutterRunsEnabled()];
    }

    /**
     * @param array $surveyResult normalised survey result (5.8)
     * @param array{grassRunsEnabled?:bool,declutterRunsEnabled?:bool} $flags
     * @return array{survey:array,applicability:array,candidates:list<array>}
     */
    public function build(array $surveyResult, int $order, array $flags): array
    {
        if ($order < 0 || $order > 79) throw new \InvalidArgumentException('A capture order is 0..79.');
        $grassOn = ($flags['grassRunsEnabled'] ?? false) === true;
        $declutterOn = ($flags['declutterRunsEnabled'] ?? false) === true;
        $space = is_array($surveyResult['space'] ?? null) ? $surveyResult['space'] : [];
        $exterior = ($space['setting'] ?? null) === 'exterior';
        $light = is_array($surveyResult['light'] ?? null) ? $surveyResult['light'] : [];
        $lighting = is_array($surveyResult['lighting'] ?? null) ? $surveyResult['lighting'] : [];
        $sky = $this->fraction($surveyResult['skyShare'] ?? 0);
        $spaceConfidence = $this->fraction($space['confidence'] ?? 0);

        $candidates = [
            $this->goldenHour($order, $exterior, $sky, $light, $lighting['goldenHour'] ?? [], $spaceConfidence),
            $this->twilight($order, $exterior, $light, $lighting['twilight'] ?? [], $spaceConfidence),
        ];
        [$lawn, $grass, $grassReasons] = $this->lawn($order, $exterior, $surveyResult['lawn'] ?? [], $grassOn);
        [$surfaces, $declutter, $declutterReasons] = $this->clutter($order, $surveyResult['clutter'] ?? [], $declutterOn);
        $candidates = array_values(array_merge($candidates, $grass, $declutter));

        $survey = [
            'space' => ['setting' => $exterior ? 'exterior' : 'interior',
                'label' => LabTourText::clean($space['label'] ?? null, 1, 60, $exterior ? 'Exterior space' : 'Interior space')],
            'skyShare' => $sky,
            'light' => ['windows' => $this->flag($light, 'windows'), 'daylight' => $this->flag($light, 'daylight'), 'lamps' => $this->flag($light, 'lampsOn'),
                'hardShadows' => $this->flag($light, 'hardShadows'), 'mirrorsOrGlass' => $this->flag($light, 'mirrorsOrGlass')],
            'lawn' => $lawn,
            'clutter' => ['surfaces' => $surfaces],
            'notes' => $this->notes($surveyResult['notes'] ?? []),
        ];
        $applicability = [
            'golden-hour' => $this->applicability([$candidates[0]], $candidates[0]['reasons']),
            'twilight' => $this->applicability([$candidates[1]], $candidates[1]['reasons']),
            'grass' => $this->applicability($grass, $grassReasons),
            'declutter' => $this->applicability($declutter, $declutterReasons),
        ];

        return ['survey' => $survey, 'applicability' => $applicability, 'candidates' => $candidates];
    }

    /**
     * Re-check a built capture (or a capture DTO with these keys) against the contract (6.7, tourContract.ts:111-222).
     * The message names only the field path, never model text.
     *
     * @throws \UnexpectedValueException
     */
    public function assertCapture(array $captureDto): void
    {
        foreach (['survey', 'applicability', 'candidates'] as $key) if (! array_key_exists($key, $captureDto)) $this->violation($key);
        $survey = $this->object($captureDto['survey'], ['space', 'skyShare', 'light', 'lawn', 'clutter', 'notes'], 'survey');
        $space = $this->object($survey['space'], ['setting', 'label'], 'survey.space');
        $this->enum($space['setting'], ['interior', 'exterior'], 'survey.space.setting');
        $this->text($space['label'], 1, 60, 'survey.space.label');
        $this->number($survey['skyShare'], 0, 1, 'survey.skyShare');
        $light = $this->object($survey['light'], ['windows', 'daylight', 'lamps', 'hardShadows', 'mirrorsOrGlass'], 'survey.light');
        foreach ($light as $key => $value) if (! is_bool($value)) $this->violation('survey.light.'.$key);

        $lawn = $this->object($survey['lawn'], ['status', 'condition', 'areas'], 'survey.lawn');
        $this->enum($lawn['status'], ['none', 'found', 'uncertain'], 'survey.lawn.status');
        if ($lawn['condition'] !== null && (! is_string($lawn['condition']) || ! preg_match(self::REASON_CODE, $lawn['condition']))) $this->violation('survey.lawn.condition');
        $areas = $this->list($lawn['areas'], 0, self::MAX_AREAS, 'survey.lawn.areas');
        $areaIds = [];
        foreach ($areas as $i => $area) {
            $path = 'survey.lawn.areas.'.$i;
            $area = $this->object($area, ['id', 'label', 'position', 'region'], $path);
            $areaIds[] = $this->shortId($area['id'], $path.'.id');
            $this->text($area['label'], 1, 60, $path.'.label');
            $this->enum($area['position'], self::POSITIONS, $path.'.position');
            $this->region($area['region'], $path.'.region');
        }
        if ($lawn['status'] === 'none' && ($areas !== [] || $lawn['condition'] !== null)) $this->violation('survey.lawn');
        if ($lawn['status'] === 'found' && $areas === []) $this->violation('survey.lawn.areas');
        if (! $this->unique($areaIds)) $this->violation('survey.lawn.areas');

        $clutter = $this->object($survey['clutter'], ['surfaces'], 'survey.clutter');
        $surfaces = $this->list($clutter['surfaces'], 0, self::MAX_SURFACES, 'survey.clutter.surfaces');
        $surfaceIds = $itemIds = [];
        foreach ($surfaces as $i => $surface) {
            $path = 'survey.clutter.surfaces.'.$i;
            $surface = $this->object($surface, ['id', 'label', 'region', 'itemIds'], $path);
            $surfaceIds[] = $this->shortId($surface['id'], $path.'.id');
            $this->text($surface['label'], 1, 60, $path.'.label');
            $this->region($surface['region'], $path.'.region');
            foreach ($this->list($surface['itemIds'], 1, self::MAX_ITEMS_PER_SURFACE, $path.'.itemIds') as $j => $id) $itemIds[] = $this->shortId($id, $path.'.itemIds.'.$j);
        }
        if (! $this->unique($surfaceIds)) $this->violation('survey.clutter.surfaces');
        foreach ($this->list($survey['notes'], 0, self::MAX_NOTES, 'survey.notes') as $i => $note) {
            $note = $this->object($note, ['topic', 'text'], 'survey.notes.'.$i);
            $this->enum($note['topic'], self::NOTE_TOPICS, 'survey.notes.'.$i.'.topic');
            $this->text($note['text'], 1, 200, 'survey.notes.'.$i.'.text');
        }

        $candidates = $this->list($captureDto['candidates'], 0, self::MAX_CANDIDATES, 'candidates');
        $byPreset = array_fill_keys(self::PRESETS, []);
        $ids = [];
        foreach ($candidates as $i => $candidate) {
            $path = 'candidates.'.$i;
            $c = $this->object($candidate, ['id', 'groupId', 'preset', 'kind', 'label', 'description', 'origin', 'region', 'confidence', 'executable',
                'defaultSelected', 'reasons'], $path);
            $ids[] = $this->shortId($c['id'], $path.'.id');
            if ($c['groupId'] !== null) $this->shortId($c['groupId'], $path.'.groupId');
            $this->enum($c['preset'], self::PRESETS, $path.'.preset');
            $this->enum($c['kind'], array_values(array_unique(self::KIND_FOR)), $path.'.kind');
            $this->text($c['label'], 1, 60, $path.'.label');
            $this->text($c['description'], 0, 160, $path.'.description');
            $this->enum($c['origin'], self::ORIGINS, $path.'.origin');
            $this->region($c['region'], $path.'.region');
            $this->number($c['confidence'], 0, 1, $path.'.confidence');
            if (! is_bool($c['executable'])) $this->violation($path.'.executable');
            if (! is_bool($c['defaultSelected'])) $this->violation($path.'.defaultSelected');
            $this->reasons($c['reasons'], $path.'.reasons');
            if ($c['kind'] !== self::KIND_FOR[$c['preset']]) $this->violation($path.'.kind');
            if ($c['kind'] === 'whole_room' && ($c['origin'] !== 'rule' || ! $this->fullSphere($c['region']))) $this->violation($path.'.region');
            if ($c['kind'] === 'lawn_area' && $c['origin'] !== 'survey') $this->violation($path.'.origin');
            if ($c['kind'] === 'item' && $c['origin'] === 'rule') $this->violation($path.'.origin');
            if ($c['groupId'] !== null && $c['kind'] !== 'item') $this->violation($path.'.groupId');
            if ($c['defaultSelected'] && ! $c['executable']) $this->violation($path.'.defaultSelected');
            if (! $c['executable'] && $c['reasons'] === []) $this->violation($path.'.reasons');
            $byPreset[$c['preset']][] = $c;
        }
        if (! $this->unique($ids)) $this->violation('candidates');
        foreach (['golden-hour', 'twilight'] as $preset) if (count($byPreset[$preset]) !== 1) $this->violation('candidates');
        if (! $this->sameSet($areaIds, array_column($byPreset['grass'], 'id'))) $this->violation('survey.lawn.areas');
        if (count($byPreset['declutter']) > self::MAX_ITEMS) $this->violation('candidates');
        if (! $this->sameSet($itemIds, array_column($byPreset['declutter'], 'id'))) $this->violation('survey.clutter.surfaces');
        $groups = [];
        foreach ($candidates as $c) if ($c['groupId'] !== null) $groups[$c['groupId']][] = $c;
        foreach ($groups as $members) {
            if (count($members) < 2) $this->violation('candidates');
            foreach ($members as $m) {
                if ($m['preset'] !== $members[0]['preset'] || $m['defaultSelected'] !== $members[0]['defaultSelected'] || $m['executable'] !== $members[0]['executable']) {
                    $this->violation('candidates');
                }
            }
        }

        $applicability = $this->object($captureDto['applicability'], self::PRESETS, 'applicability');
        foreach (self::PRESETS as $preset) {
            $path = 'applicability.'.$preset;
            $entry = $this->object($applicability[$preset], ['status', 'reasons'], $path);
            $this->enum($entry['status'], ['recommended', 'possible', 'not_applicable'], $path.'.status');
            $this->reasons($entry['reasons'], $path.'.reasons');
            if ($entry['status'] === 'not_applicable' && $entry['reasons'] === []) $this->violation($path.'.reasons');
            if ($entry['status'] !== $this->derivedStatus($byPreset[$preset])) $this->violation($path.'.status');
        }
    }

    // ------------------------------------------------------------------ lighting (6.2)

    private function goldenHour(int $order, bool $exterior, float $sky, array $light, mixed $look, float $spaceConfidence): array
    {
        $windows = $this->flag($light, 'windows');
        $daylight = $this->flag($light, 'daylight');
        $kind = $light['windowKind'] ?? null;
        $description = null;
        [$executable, $default, $reasons] = match (true) {
            $exterior && $sky >= 0.05 => [true, true, ['exterior_with_sky']],                              // G1
            $exterior => [true, false, ['exterior_little_sky']],                                           // G2
            ! $windows && ! $daylight => [false, false, ['no_window']],                                    // G3
            ! $windows => [true, false, ['indirect_daylight']],                                            // G4
            $kind === 'frosted' => [true, false, ['frosted_window']],                                      // G5
            $kind === 'small' => [true, false, ['small_window']],                                          // G6
            ! $daylight || ($light['daylightStrength'] ?? null) !== 'good' => [true, false, ['weak_daylight']], // G7
            default => [true, true, ['daylight_windows']],                                                 // G8
        };
        if (! $exterior && ! $windows && ! $daylight) $description = 'No window, so there is no daylight to warm.';
        $fallback = $exterior ? 'Warm, low evening light across the whole view.' : 'Warm evening light through the windows.';

        return $this->wholeRoom('g'.$order, 'golden-hour', $exterior, $description ?? $this->lookText($look, $fallback),
            $executable ? $this->fraction(is_array($look) ? ($look['confidence'] ?? 0) : 0) : $spaceConfidence, $executable, $default, $reasons);
    }

    private function twilight(int $order, bool $exterior, array $light, mixed $look, float $spaceConfidence): array
    {
        $windows = $this->flag($light, 'windows');
        $lampsOn = $this->flag($light, 'lampsOn');
        $lampReason = $this->flag($light, 'lightFixtures') ? 'no_lamps_on' : 'no_lamps';
        $kind = $light['windowKind'] ?? null;
        [$executable, $default, $reasons, $description] = match (true) {
            $exterior => [false, false, ['outdoor_twilight_pending'], 'Outdoor twilight comes in a later release.'], // T1
            ! $windows && ! $lampsOn => [false, false, ['no_window', $lampReason], 'No window or lamp light to work with.'], // T2
            ! $windows => [true, false, ['lamps_only'], null],                                                     // T3
            $kind === 'frosted' => [true, false, ['frosted_window'], null],                                        // T4
            $kind === 'small' => [true, false, ['small_window'], null],                                            // T5
            ! $lampsOn => [true, false, [$lampReason], null],                                                      // T6
            default => [true, true, ['windows_and_lamps'], null],                                                  // T7
        };

        return $this->wholeRoom('t'.$order, 'twilight', $exterior, $description ?? $this->lookText($look, 'Blue hour outside the windows; lamps glow warm.'),
            $executable ? $this->fraction(is_array($look) ? ($look['confidence'] ?? 0) : 0) : $spaceConfidence, $executable, $default, $reasons);
    }

    private function wholeRoom(string $id, string $preset, bool $exterior, string $description, float $confidence, bool $executable, bool $default, array $reasons): array
    {
        return ['id' => $id, 'groupId' => null, 'preset' => $preset, 'kind' => 'whole_room', 'label' => $exterior ? 'Whole view' : 'Whole room',
            'description' => $description, 'origin' => 'rule', 'region' => LabTourGeometry::FULL_SPHERE, 'confidence' => $confidence,
            'executable' => $executable, 'defaultSelected' => $executable && $default, 'reasons' => $this->reasonList($reasons)];
    }

    private function lookText(mixed $look, string $fallback): string
    {
        return LabTourText::clean(is_array($look) ? ($look['description'] ?? null) : null, 0, 160, $fallback);
    }

    // ------------------------------------------------------------------ grass (6.3)

    /** @return array{0: array, 1: list<array>, 2: list<string>} [survey lawn, grass candidates, applicability reasons] */
    private function lawn(int $order, bool $exterior, mixed $lawn, bool $grassOn): array
    {
        $lawn = is_array($lawn) ? $lawn : [];
        $status = in_array($lawn['status'] ?? null, ['found', 'uncertain'], true) && $exterior ? $lawn['status'] : 'none';
        $areas = [];
        if ($status !== 'none') {
            foreach (is_array($lawn['areas'] ?? null) ? array_values($lawn['areas']) : [] as $area) {
                if (count($areas) >= self::MAX_AREAS) break;
                if (! is_array($area) || ! is_array($area['region'] ?? null)) continue;
                $areas[] = $area;
            }
        }
        if ($status === 'found' && $areas === []) $status = 'uncertain';
        $condition = $lawn['condition'] ?? null;
        $condition = $status !== 'none' && is_string($condition) && ! in_array($condition, self::CONDITIONS_DROPPED, true) && preg_match(self::REASON_CODE, $condition)
            ? $condition : null;

        $surveyAreas = $candidates = [];
        foreach ($areas as $i => $area) {
            $id = 'l'.$order.self::LETTERS[$i];
            $region = $area['region'];
            $confidence = $this->fraction($area['confidence'] ?? 0);
            $position = in_array($area['position'] ?? null, self::POSITIONS, true) ? $area['position'] : 'unknown';
            $label = LabTourText::clean($area['label'] ?? null, 1, 60, 'Lawn area');
            $small = $this->area($region) < 150;
            $low = $confidence < 0.6;
            $reasons = array_merge($grassOn ? [] : ['preset_runs_not_enabled'], $small ? ['small_lawn_area'] : [], $low ? ['low_confidence'] : []);
            $surveyAreas[] = ['id' => $id, 'label' => $label, 'position' => $position, 'region' => $region];
            $candidates[] = ['id' => $id, 'groupId' => null, 'preset' => 'grass', 'kind' => 'lawn_area', 'label' => $label,
                'description' => self::POSITION_TEXT[$position], 'origin' => 'survey', 'region' => $region, 'confidence' => $confidence,
                'executable' => $grassOn, 'defaultSelected' => $grassOn && $status === 'found' && ! $low && ! $small, 'reasons' => $this->reasonList($reasons)];
        }

        $found = $status === 'found' ? 'lawn_found' : 'lawn_uncertain';
        $reasons = match (true) {
            ! $exterior => ['interior'],
            $status === 'none' => ['no_lawn_found'],
            $candidates === [] => ['lawn_uncertain'],
            default => $grassOn ? [$found] : ['preset_runs_not_enabled', $found],
        };

        return [['status' => $status, 'condition' => $condition, 'areas' => $surveyAreas], $candidates, $reasons];
    }

    // ------------------------------------------------------------------ declutter (6.4)

    /** @return array{0: list<array>, 1: list<array>, 2: list<string>} [survey surfaces, declutter candidates, applicability reasons] */
    private function clutter(int $order, mixed $clutter, bool $declutterOn): array
    {
        $raw = is_array($clutter) && is_array($clutter['surfaces'] ?? null) ? array_values($clutter['surfaces']) : [];
        $kept = [];
        $total = 0;
        foreach (array_slice($raw, 0, self::MAX_SURFACES) as $surface) {
            if (! is_array($surface) || ! is_array($surface['region'] ?? null)) continue;
            $items = [];
            foreach (array_slice(is_array($surface['items'] ?? null) ? array_values($surface['items']) : [], 0, self::MAX_ITEMS_PER_SURFACE) as $item) {
                if ($total >= self::MAX_ITEMS) break;
                if (! is_array($item) || ! is_array($item['region'] ?? null)) continue;
                $items[] = $item;
                $total++;
            }
            if ($items !== []) $kept[] = ['surface' => $surface, 'items' => $items];
        }

        // Group keys: one surface and one touchGroup >= 1, with at least two kept members.
        $members = [];
        foreach ($kept as $s => $entry) foreach ($entry['items'] as $item) {
            $touch = $this->touchGroup($item['touchGroup'] ?? 0);
            if ($touch >= 1) $members[$s.':'.$touch] = ($members[$s.':'.$touch] ?? 0) + 1;
        }
        $surfaces = $candidates = $groupOf = [];
        $groupLetters = [];
        foreach ($kept as $s => $entry) {
            $itemIds = [];
            foreach ($entry['items'] as $item) {
                $id = 'd'.$order.self::LETTERS[count($candidates)];
                $itemIds[] = $id;
                $confidence = $this->fraction($item['confidence'] ?? 0);
                $glass = ($item['glassOrMirror'] ?? false) === true;
                $executable = $declutterOn && ! $glass;
                $touch = $this->touchGroup($item['touchGroup'] ?? 0);
                $groupId = null;
                if ($touch >= 1 && ($members[$s.':'.$touch] ?? 0) >= 2) {
                    $groupLetters[$s.':'.$touch] ??= self::LETTERS[count($groupLetters)];
                    $groupId = 'p'.$order.$groupLetters[$s.':'.$touch];
                }
                $candidates[] = ['id' => $id, 'groupId' => $groupId, 'preset' => 'declutter', 'kind' => 'item',
                    'label' => LabTourText::clean($item['label'] ?? null, 1, 60, 'Item'),
                    'description' => LabTourText::clean($item['description'] ?? null, 0, 160, ''), 'origin' => 'survey', 'region' => $item['region'],
                    'confidence' => $confidence, 'executable' => $executable, 'defaultSelected' => $executable && $confidence >= 0.7,
                    'reasons' => array_merge($declutterOn ? [] : ['preset_runs_not_enabled'], $glass ? ['glass_or_mirror'] : [], $confidence < 0.5 ? ['low_confidence'] : [])];
            }
            $surfaces[] = ['id' => 's'.$order.self::LETTERS[$s], 'label' => LabTourText::clean($entry['surface']['label'] ?? null, 1, 60, 'Surface'),
                'region' => $entry['surface']['region'], 'itemIds' => $itemIds];
        }

        // Members of a group share executability and default (tourContract.ts:217-221): AND over the members.
        foreach ($candidates as $c) if ($c['groupId'] !== null) {
            $groupOf[$c['groupId']]['executable'] = ($groupOf[$c['groupId']]['executable'] ?? true) && $c['executable'];
            $groupOf[$c['groupId']]['default'] = ($groupOf[$c['groupId']]['default'] ?? true) && $c['defaultSelected'];
        }
        foreach ($candidates as $i => $c) {
            if ($c['groupId'] !== null) {
                $executable = $groupOf[$c['groupId']]['executable'];
                $default = $groupOf[$c['groupId']]['default'] && $executable;
                if ($executable !== $c['executable'] || $default !== $c['defaultSelected']) $c['reasons'][] = 'group_member_not_selectable';
                [$c['executable'], $c['defaultSelected']] = [$executable, $default];
            }
            $c['reasons'] = $this->reasonList($c['reasons']);
            $candidates[$i] = $c;
        }
        $candidates = array_values($candidates);

        $anyDefault = (bool) array_filter($candidates, fn ($c) => $c['defaultSelected']);
        $reasons = match (true) {
            $candidates === [] => ['no_loose_items'],
            ! $declutterOn => ['preset_runs_not_enabled', 'loose_items_found'],
            $anyDefault => ['loose_items_found'],
            default => ['low_confidence'],
        };

        return [array_values($surfaces), $candidates, $reasons];
    }

    // ------------------------------------------------------------------ survey parts and helpers

    /** @return list<array{topic:string,text:string}> */
    private function notes(mixed $notes): array
    {
        $out = [];
        foreach (is_array($notes) ? array_values($notes) : [] as $note) {
            if (count($out) >= self::MAX_NOTES) break;
            if (! is_array($note) || ! in_array($note['topic'] ?? null, self::NOTE_TOPICS, true)) continue;
            $text = LabTourText::clean($note['text'] ?? null, 1, 200, null);
            if ($text !== null) $out[] = ['topic' => $note['topic'], 'text' => $text];
        }

        return array_values($out);
    }

    /** 6.6: recommended if any candidate is ticked by default, possible if any is executable, else not_applicable. */
    private function applicability(array $candidates, array $reasons): array
    {
        return ['status' => $this->derivedStatus($candidates), 'reasons' => $this->reasonList($reasons)];
    }

    private function derivedStatus(array $candidates): string
    {
        foreach ($candidates as $c) if (($c['defaultSelected'] ?? false) === true) return 'recommended';
        foreach ($candidates as $c) if (($c['executable'] ?? false) === true) return 'possible';

        return 'not_applicable';
    }

    /** @return list<string> */
    private function reasonList(array $reasons): array
    {
        return array_slice(array_values(array_unique($reasons)), 0, self::MAX_REASONS);
    }

    private function flag(array $values, string $key): bool
    {
        return ($values[$key] ?? false) === true;
    }

    private function fraction(mixed $value): float
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) return 0.0;

        return round(min(1.0, max(0.0, (float) $value)), 2) + 0.0;
    }

    private function touchGroup(mixed $value): int
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value) $value = (int) $value;

        return is_int($value) && $value >= 1 && $value <= 12 ? $value : 0;
    }

    private function area(mixed $region): float
    {
        return is_array($region) ? (float) ($region['hfov'] ?? 0) * (float) ($region['vfov'] ?? 0) : 0.0;
    }

    // ------------------------------------------------------------------ assertCapture checks

    private function violation(string $path): never
    {
        throw new \UnexpectedValueException('defaults_invalid at '.$path);
    }

    /** An associative array with exactly these keys (strict contract objects). */
    private function object(mixed $value, array $keys, string $path): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) $this->violation($path);
        $actual = array_keys($value);
        $expected = $keys;
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) $this->violation($path);

        return $value;
    }

    private function list(mixed $value, int $min, int $max, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) < $min || count($value) > $max) $this->violation($path);

        return $value;
    }

    private function enum(mixed $value, array $allowed, string $path): void
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) $this->violation($path);
    }

    private function shortId(mixed $value, string $path): string
    {
        if (! is_string($value) || ! preg_match(self::SHORT_ID, $value)) $this->violation($path);

        return $value;
    }

    private function text(mixed $value, int $min, int $max, string $path): void
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || ! LabTourText::safe($value)) $this->violation($path);
        $length = LabTourText::utf16Length($value);
        if ($length < $min || $length > $max) $this->violation($path);
    }

    private function number(mixed $value, float $min, float $max, string $path): void
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < $min || $value > $max) $this->violation($path);
    }

    private function reasons(mixed $value, string $path): void
    {
        $list = $this->list($value, 0, self::MAX_REASONS, $path);
        foreach ($list as $i => $code) if (! is_string($code) || ! preg_match(self::REASON_CODE, $code)) $this->violation($path.'.'.$i);
        if (! $this->unique($list)) $this->violation($path);
    }

    /** regionSchema (tourContract.ts:92-93): yaw [-180,180), pitch [-90,90], hfov (0,360], vfov (0,180], |pitch| + vfov/2 <= 90.5. */
    private function region(mixed $value, string $path): void
    {
        $r = $this->object($value, ['yaw', 'pitch', 'hfov', 'vfov'], $path);
        foreach ($r as $key => $number) if ((! is_int($number) && ! is_float($number)) || ! is_finite((float) $number)) $this->violation($path.'.'.$key);
        if ($r['yaw'] < -180 || $r['yaw'] >= 180) $this->violation($path.'.yaw');
        if ($r['pitch'] < -90 || $r['pitch'] > 90) $this->violation($path.'.pitch');
        if ($r['hfov'] <= 0 || $r['hfov'] > 360) $this->violation($path.'.hfov');
        if ($r['vfov'] <= 0 || $r['vfov'] > 180) $this->violation($path.'.vfov');
        if (abs($r['pitch']) + $r['vfov'] / 2 > 90.5) $this->violation($path);
    }

    private function fullSphere(array $region): bool
    {
        return (float) $region['yaw'] === 0.0 && (float) $region['pitch'] === 0.0 && (float) $region['hfov'] === 360.0 && (float) $region['vfov'] === 180.0;
    }

    private function unique(array $values): bool
    {
        return count($values) === count(array_unique(array_map('strval', $values), SORT_STRING));
    }

    /** sameSet of tourContract.ts:69: equal length, both unique, every member shared. */
    private function sameSet(array $a, array $b): bool
    {
        return count($a) === count($b) && $this->unique($a) && $this->unique($b) && array_diff($a, $b) === [];
    }
}
