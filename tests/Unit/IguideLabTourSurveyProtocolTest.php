<?php

namespace Tests\Unit;

use App\Services\IguideLab\LabTourSurveyParseException;
use App\Services\IguideLab\LabTourSurveyProtocol;
use Tests\TestCase;

/** tour-survey/1 recipe, context, wire request and response parser (spec 5.3-5.8, tests 13.3). No database. */
class IguideLabTourSurveyProtocolTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/iguide-lab/tour/responses/';
    /** sha256 of the 5.3.1 text copied from d1-spec.md (LF line breaks, no trailing newline). */
    private const INSTRUCTIONS_SHA256 = 'a0695eff02c9bb3f9d068e521ddeeb523fe726edf56f88ecf4428a138ab56978';
    /** The exact context of spec 5.5 for ("Kitchen", "Main Floor"). */
    private const CONTEXT_JSON = '{"schemaVersion":"tour-survey/1","capture":{"roomName":"Kitchen","floorName":"Main Floor"},"evidence":[{"id":"source_panorama","projection":"equirectangular","width":2048,"height":1024},{"id":"view_front","projection":"rectilinear","yawDegrees":0,"pitchDegrees":0,"hfovDegrees":100,"width":1024,"height":1024},{"id":"view_right","projection":"rectilinear","yawDegrees":90,"pitchDegrees":0,"hfovDegrees":100,"width":1024,"height":1024},{"id":"view_back","projection":"rectilinear","yawDegrees":-180,"pitchDegrees":0,"hfovDegrees":100,"width":1024,"height":1024},{"id":"view_left","projection":"rectilinear","yawDegrees":-90,"pitchDegrees":0,"hfovDegrees":100,"width":1024,"height":1024}]}';

    public function test_recipe_fingerprint_matches_the_file(): void
    {
        [$file, $sha] = LabTourSurveyProtocol::RECIPE;
        $this->assertSame('tour-survey-1.json', $file);
        $this->assertSame(hash_file('sha256', resource_path('iguide-lab/tour-recipes/'.$file)), $sha);
        $bytes = file_get_contents(resource_path('iguide-lab/tour-recipes/'.$file));
        $this->assertStringEndsWith("}\n", $bytes);
        $this->assertStringNotContainsString("\r", $bytes);
        $recipe = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['version', 'wireName', 'instructions', 'schema'], array_keys($recipe));
        $this->assertSame(['tour-survey/1', 'iguide_tour_survey'], [$recipe['version'], $recipe['wireName']]);
        $this->assertSame(self::INSTRUCTIONS_SHA256, hash('sha256', $recipe['instructions']));
        $this->assertSame($bytes, json_encode($recipe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    public function test_wire_schema_is_strict_everywhere(): void
    {
        $schema = $this->recipe()['schema'];
        $objects = 0;
        $walk = function (array $node, string $path) use (&$walk, &$objects): void {
            foreach (['minLength', 'maxLength', '$ref', 'anyOf', 'oneOf', 'pattern'] as $forbidden) $this->assertArrayNotHasKey($forbidden, $node, $path);
            $this->assertIsString($node['type'] ?? null, $path.' has one non-null type');
            $this->assertNotSame('null', $node['type'], $path);
            if ($node['type'] === 'object') {
                $objects++;
                $this->assertFalse($node['additionalProperties'], $path);
                $this->assertSame(array_keys($node['properties']), $node['required'], $path);
                foreach ($node['properties'] as $key => $child) $walk($child, $path.'.'.$key);
            }
            if ($node['type'] === 'array') $walk($node['items'], $path.'[]');
        };
        $walk($schema, '$');
        $this->assertSame(15, $objects);
        $this->assertSame(['space', 'skyShare', 'light', 'lighting', 'lawn', 'clutter', 'notes'], $schema['required']);
        $this->assertSame(['minimum' => 0, 'maximum' => 12], array_intersect_key(
            $schema['properties']['clutter']['properties']['surfaces']['items']['properties']['items']['items']['properties']['touchGroup'], ['minimum' => 1, 'maximum' => 1]));
    }

    public function test_request_envelope_is_exact(): void
    {
        $context = LabTourSurveyProtocol::context('Kitchen', 'Main Floor');
        $images = $this->images();
        $bytes = LabTourSurveyProtocol::request($context, $images);
        $wire = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($bytes, json_encode($wire, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(['model', 'store', 'service_tier', 'reasoning', 'max_output_tokens', 'instructions', 'input', 'text'], array_keys($wire));
        $this->assertSame(['gpt-6-astra', false, 'default', ['effort' => 'medium'], 16000],
            [$wire['model'], $wire['store'], $wire['service_tier'], $wire['reasoning'], $wire['max_output_tokens']]);
        $this->assertSame(self::INSTRUCTIONS_SHA256, hash('sha256', $wire['instructions']));
        $this->assertCount(1, $wire['input']);
        $this->assertSame(['role', 'content'], array_keys($wire['input'][0]));
        $this->assertSame('user', $wire['input'][0]['role']);
        $content = $wire['input'][0]['content'];
        $this->assertCount(11, $content);
        $this->assertSame(['type' => 'input_text', 'text' => self::CONTEXT_JSON], $content[0]);
        foreach (['source_panorama', 'view_front', 'view_right', 'view_back', 'view_left'] as $i => $id) {
            $this->assertSame(['type' => 'input_text', 'text' => 'Evidence ID: '.$id], $content[1 + 2 * $i]);
            $this->assertSame(['type' => 'input_image', 'detail' => 'high', 'image_url' => 'data:image/png;base64,'.base64_encode($images[$i]['bytes'])],
                $content[2 + 2 * $i]);
        }
        $this->assertSame(['format' => ['type' => 'json_schema', 'name' => 'iguide_tour_survey', 'strict' => true, 'schema' => $this->recipe()['schema']]], $wire['text']);

        // Config drives model, effort and token cap; an unsupported value fails before any bytes exist.
        config(['iguide-lab-tour.survey.effort' => 'high', 'iguide-lab-tour.survey.max_output_tokens' => 20000]);
        $wire = json_decode(LabTourSurveyProtocol::request($context, $images), true);
        $this->assertSame([['effort' => 'high'], 20000], [$wire['reasoning'], $wire['max_output_tokens']]);
        config(['iguide-lab-tour.survey.model' => 'gpt-5']);
        $this->assertThrowsCode(fn () => LabTourSurveyProtocol::request($context, $images), \App\Services\IguideLab\LabTourConfigException::class);
    }

    public function test_request_rechecks_every_image_binding(): void
    {
        $context = LabTourSurveyProtocol::context(null, null);
        $images = $this->images();
        $wrongSha = $images; $wrongSha[2]['sha256'] = str_repeat('0', 64);
        $wrongSize = $images; $wrongSize[1]['bytes'] = $images[0]['bytes']; $wrongSize[1]['sha256'] = $images[0]['sha256'];
        $wrongOrder = [$images[0], $images[2], $images[1], $images[3], $images[4]];
        foreach ([$wrongSha, $wrongSize, $wrongOrder, array_slice($images, 0, 4)] as $bad) {
            try {
                LabTourSurveyProtocol::request($context, $bad);
                $this->fail('A changed image binding compiled.');
            } catch (\RuntimeException $e) {
                $this->assertNotInstanceOf(\App\Services\IguideLab\LabTourConfigException::class, $e);
            }
        }
    }

    public function test_context_drops_unsafe_room_names_and_has_no_hash(): void
    {
        $this->assertSame(self::CONTEXT_JSON, json_encode(LabTourSurveyProtocol::context('Kitchen', 'Main Floor'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $context = LabTourSurveyProtocol::context('Kitchen https://youriguide.com/abc', "Main\nFloor");
        $this->assertSame(['roomName' => null, 'floorName' => 'Main Floor'], $context['capture']);
        $this->assertSame(['roomName' => null, 'floorName' => null], LabTourSurveyProtocol::context('', 'accessToken=abc')['capture']);
        $this->assertSame(str_repeat('a', 119)."\u{2026}", LabTourSurveyProtocol::context(str_repeat('a', 130), null)['capture']['roomName']);
        $json = json_encode($context, JSON_UNESCAPED_SLASHES);
        $this->assertDoesNotMatchRegularExpression('/[a-f0-9]{64}/i', $json);
        $this->assertStringNotContainsString('http', $json);
    }

    public function test_parse_valid_answers(): void
    {
        // Schema key order (5.4), overrides in place.
        $light = fn (array $o = []) => array_merge(['windows' => true, 'windowKind' => 'normal', 'daylight' => true, 'daylightStrength' => 'good',
            'lightFixtures' => true, 'lampsOn' => true, 'hardShadows' => false, 'mirrorsOrGlass' => false], $o);
        $region = fn ($yaw, $pitch, $hfov, $vfov) => ['yaw' => $yaw, 'pitch' => $pitch, 'hfov' => $hfov, 'vfov' => $vfov];
        $item = fn ($label, $description, $region, $confidence, $glass, $group) => ['label' => $label, 'description' => $description, 'region' => $region,
            'confidence' => $confidence, 'glassOrMirror' => $glass, 'touchGroup' => $group];
        $this->assertSame([
            'version' => 'tour-survey-result/1',
            'space' => ['setting' => 'exterior', 'label' => 'Front yard', 'confidence' => 0.93],
            'skyShare' => 0.31,
            'light' => $light(['windows' => false, 'windowKind' => 'none', 'lightFixtures' => false, 'lampsOn' => false, 'hardShadows' => true]),
            'lighting' => ['goldenHour' => ['description' => 'Low warm sun across the lawn and the front of the house.', 'confidence' => 0.88],
                'twilight' => ['description' => 'Blue sky over the house with the porch light warm.', 'confidence' => 0.4]],
            'lawn' => ['status' => 'found', 'condition' => 'healthy', 'areas' => [
                ['label' => 'Front lawn', 'position' => 'front', 'region' => $region(0.0, -51.3, 144.0, 59.4), 'confidence' => 0.9],
                ['label' => 'Side lawn', 'position' => 'side', 'region' => $region(-180.0, -40.5, 57.6, 45.0), 'confidence' => 0.64],
            ]],
            'clutter' => ['surfaces' => []],
            'notes' => [['topic' => 'lawn', 'text' => 'Part of the front lawn is in the shade of the house.']],
            'warnings' => [],
        ], $this->parseFixture('exterior-front'));

        $this->assertSame([
            'version' => 'tour-survey-result/1',
            'space' => ['setting' => 'interior', 'label' => 'Kitchen', 'confidence' => 0.95],
            'skyShare' => 0.02,
            'light' => $light(),
            'lighting' => ['goldenHour' => ['description' => 'Warm light through the window over the sink.', 'confidence' => 0.9],
                'twilight' => ['description' => 'Blue hour in the window; pendants on.', 'confidence' => 0.86]],
            'lawn' => ['status' => 'none', 'condition' => null, 'areas' => []],
            'clutter' => ['surfaces' => [
                ['label' => 'Counter', 'region' => $region(-32.4, -21.6, 43.2, 14.4), 'items' => [
                    $item('Dish soap', 'By the sink', $region(-45.0, -17.1, 3.6, 5.4), 0.92, false, 1),
                    $item('Sponge', 'Next to the dish soap', $region(-41.4, -19.8, 3.6, 3.6), 0.88, false, 1),
                    $item('Paper towels', 'At the end of the counter', $region(-18.0, -17.1, 7.2, 9.0), 0.8, false, 0),
                    $item('Glass bottle', 'Beside the stove', $region(-30.6, -16.2, 3.6, 7.2), 0.6, true, 0),
                ]],
                ['label' => 'Table', 'region' => $region(93.6, -32.4, 43.2, 21.6), 'items' => [
                    $item('Mail', 'Stack of letters on the table', $region(90.0, -27.0, 7.2, 3.6), 0.85, false, 0),
                    $item('Keys', 'Near the mail', $region(99.0, -27.9, 3.6, 1.8), 0.7, false, 0),
                ]],
            ]],
            'notes' => [['topic' => 'light', 'text' => 'The window over the sink is bright.']],
            'warnings' => [],
        ], $this->parseFixture('kitchen'));

        $closet = $this->parseFixture('walk-in-closet');
        $this->assertSame([
            'version' => 'tour-survey-result/1',
            'space' => ['setting' => 'interior', 'label' => 'Walk-in closet', 'confidence' => 0.9],
            'skyShare' => 0.0,
            'light' => $light(['windows' => false, 'windowKind' => 'none', 'daylight' => false, 'daylightStrength' => 'none', 'lampsOn' => false, 'mirrorsOrGlass' => true]),
            'lighting' => ['goldenHour' => ['description' => '', 'confidence' => 0.1],
                'twilight' => ['description' => 'No window and the ceiling light is off.', 'confidence' => 0.15]],
            'lawn' => ['status' => 'none', 'condition' => null, 'areas' => []],
            'clutter' => ['surfaces' => []],
            'notes' => [],
            'warnings' => [],
        ], $closet);
        // Stored as JSON, every list stays a list and the bytes (result_sha256) survive a decode/encode round trip.
        $json = json_encode($closet, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('"areas":[]', $json);
        $this->assertStringContainsString('"notes":[],"warnings":[]', $json);
        $this->assertSame($json, json_encode(json_decode($json, true), JSON_THROW_ON_ERROR));
    }

    public function test_incomplete_is_its_own_reason(): void
    {
        $this->assertParseFails('survey_incomplete', $this->fixture('incomplete'), 'resp_incomplete');
        $other = json_decode($this->fixture('incomplete'), true);
        $other['incomplete_details']['reason'] = 'content_filter';
        $this->assertParseFails('survey_unreadable', json_encode($other), 'resp_incomplete', 'envelope_status');
    }

    public function test_two_texts_refusal_oversized_text_bad_model_or_id_are_rejected(): void
    {
        $this->assertParseFails('survey_refused', $this->fixture('refusal'), 'resp_refusal');
        $kitchen = json_decode($this->fixture('kitchen'), true);
        $two = $kitchen; $two['output'][1]['content'][] = $kitchen['output'][1]['content'][0];
        $this->assertParseFails('survey_unreadable', json_encode($two), 'resp_kitchen');
        $twoMessages = $kitchen; $twoMessages['output'][] = $kitchen['output'][1];
        $this->assertParseFails('survey_unreadable', json_encode($twoMessages), 'resp_kitchen');
        $tool = $kitchen; $tool['output'][1]['type'] = 'function_call';
        $this->assertParseFails('survey_unreadable', json_encode($tool), 'resp_kitchen');
        $badModel = $kitchen; $badModel['model'] = 'gpt-5-mini';
        $this->assertParseFails('survey_unreadable', json_encode($badModel), 'resp_kitchen');
        $this->assertParseFails('survey_unreadable', $this->fixture('kitchen'), 'resp_other');
        $badId = $kitchen; $badId['id'] = 'resp kitchen';
        $this->assertParseFails('survey_unreadable', json_encode($badId), 'resp kitchen');
        $this->assertParseFails('survey_unreadable', 'not json', 'resp_kitchen', 'envelope_json');
        $notObject = $kitchen; $notObject['output'][1]['content'][0]['text'] = '["a list"]';
        $this->assertParseFails('survey_unreadable', json_encode($notObject), 'resp_kitchen');
        $failed = $kitchen; $failed['status'] = 'failed';
        $this->assertParseFails('survey_unreadable', json_encode($failed), 'resp_kitchen', 'envelope_status');
        config(['iguide-lab-tour.survey.max_output_text_bytes' => 1024]);
        $this->assertGreaterThan(1024, strlen($kitchen['output'][1]['content'][0]['text']));
        $this->assertParseFails('survey_unreadable', $this->fixture('kitchen'), 'resp_kitchen');
    }

    public function test_dated_model_snapshot_is_accepted(): void
    {
        $kitchen = json_decode($this->fixture('kitchen'), true);
        $kitchen['model'] = 'gpt-6-astra-2026-10-01';
        $this->assertSame('Kitchen', LabTourSurveyProtocol::parse(json_encode($kitchen), 'gpt-6-astra', 'resp_kitchen')['space']['label']);
        $kitchen['model'] = 'gpt-6-astra-latest';
        $this->assertParseFails('survey_unreadable', json_encode($kitchen), 'resp_kitchen');
    }

    public function test_strict_schema_violations_are_unreadable(): void
    {
        $this->assertParseFails('survey_unreadable', $this->fixture('unknown-key'), 'resp_unknown_key', 'schema:light.skylight');
        $cases = [
            'schema:space.label' => function (array &$a) { unset($a['space']['label']); },
            'schema:light.windowKind' => function (array &$a) { $a['light']['windowKind'] = 'huge'; },
            'schema:clutter.surfaces.0.items.1.touchGroup' => function (array &$a) { $a['clutter']['surfaces'][0]['items'][1]['touchGroup'] = '1'; },
            'schema:clutter.surfaces.1.box.uMax' => function (array &$a) { $a['clutter']['surfaces'][1]['box']['uMax'] = null; },
            'schema:skyShare' => function (array &$a) { $a['skyShare'] = '0.2'; },
            'schema:light.windows' => function (array &$a) { $a['light']['windows'] = 1; },
            'schema:notes' => function (array &$a) { $a['notes'] = ['topic' => 'light', 'text' => 'Not a list']; },
            'schema:lawn.areas' => function (array &$a) { $a['lawn']['areas'] = new \stdClass; },
            'schema:space' => function (array &$a) { $a['space'] = []; },
            'schema:lighting.extra' => function (array &$a) { $a['lighting']['extra'] = ['description' => 'x', 'confidence' => 1]; },
        ];
        foreach ($cases as $path => $mutate) {
            $answer = $this->answer('kitchen');
            $mutate($answer);
            $this->assertParseFails('survey_unreadable', $this->envelope('kitchen', $answer), 'resp_kitchen', $path);
        }
        // An integral float is a valid integer (touchGroup).
        $answer = $this->answer('kitchen');
        $answer['clutter']['surfaces'][0]['items'][0]['touchGroup'] = 1.0;
        $this->assertSame(1, LabTourSurveyProtocol::parse($this->envelope('kitchen', $answer), 'gpt-6-astra', 'resp_kitchen')['clutter']['surfaces'][0]['items'][0]['touchGroup']);
    }

    public function test_tolerant_text_and_box_handling(): void
    {
        $result = $this->parseFixture('unsafe-text', 'resp_unsafe_text');
        $this->assertSame(['setting' => 'interior', 'label' => 'Interior space', 'confidence' => 1.0], $result['space']);
        $this->assertSame('Warm light through the bay window.', $result['lighting']['goldenHour']['description']);
        $this->assertSame('Blue hour outside the windows; lamps glow warm.', $result['lighting']['twilight']['description']);
        $surface = $result['clutter']['surfaces'][0];
        $this->assertSame('Surface', $surface['label']);
        $this->assertSame(['yaw' => -126.0, 'pitch' => -18.0, 'hfov' => 36.0, 'vfov' => 18.0], $surface['region']);
        $this->assertSame("Stack of unopened envelopes, catalogues and flyers on the d\u{2026}", $surface['items'][0]['label']);
        $this->assertSame('On the desk by the lamp', $surface['items'][0]['description']);
        $this->assertSame("Plush toy bear with a ribbon around its neck on the chair \u{1F600}", $surface['items'][1]['label']);
        $this->assertSame([['topic' => 'light', 'text' => 'Strong reflections on the glass table.']], $result['notes']);
        $this->assertTrue(array_is_list($result['notes']));
        $this->assertStringStartsWith('[', json_encode($result['notes']));
        $this->assertSame(['text_replaced', 'value_clamped', 'note_dropped'], $result['warnings']);

        // Degenerate boxes drop an area, a surface with its items, or one item; lists stay lists.
        $answer = $this->answer('kitchen');
        $answer['clutter']['surfaces'][0]['items'][0]['box'] = ['uMin' => 0.4, 'uMax' => 0.4, 'vMin' => 0.5, 'vMax' => 0.6];
        $answer['clutter']['surfaces'][1]['box'] = ['uMin' => 0.1, 'uMax' => 0.2, 'vMin' => 0.6, 'vMax' => 0.6];
        $answer['notes'] = [['topic' => 'general', 'text' => "\n\t"], ['topic' => 'light', 'text' => 'Bright window.'], ['topic' => 'light', 'text' => 'api_key']];
        $answer['skyShare'] = 1.5;
        $answer['space']['confidence'] = -0.2;
        $result = LabTourSurveyProtocol::parse($this->envelope('kitchen', $answer), 'gpt-6-astra', 'resp_kitchen');
        $this->assertCount(1, $result['clutter']['surfaces']);
        $this->assertSame(['Sponge', 'Paper towels', 'Glass bottle'], array_column($result['clutter']['surfaces'][0]['items'], 'label'));
        $this->assertSame([['topic' => 'light', 'text' => 'Bright window.']], $result['notes']);
        $this->assertSame([1.0, 0.0], [$result['skyShare'], $result['space']['confidence']]);
        $this->assertSame(['value_clamped', 'box_dropped', 'note_dropped'], $result['warnings']);
        foreach (['surfaces' => $result['clutter']['surfaces'], 'items' => $result['clutter']['surfaces'][0]['items'], 'notes' => $result['notes']] as $name => $list) {
            $this->assertTrue(array_is_list($list), $name);
        }
    }

    public function test_interior_lawn_forced_none_and_found_without_areas_becomes_uncertain(): void
    {
        $answer = $this->answer('kitchen');
        $answer['lawn'] = ['status' => 'found', 'condition' => 'healthy', 'areas' => [
            ['label' => 'Lawn outside', 'position' => 'back', 'box' => ['uMin' => 0.1, 'uMax' => 0.2, 'vMin' => 0.4, 'vMax' => 0.5], 'confidence' => 0.8]]];
        $result = LabTourSurveyProtocol::parse($this->envelope('kitchen', $answer), 'gpt-6-astra', 'resp_kitchen');
        $this->assertSame(['status' => 'none', 'condition' => null, 'areas' => []], $result['lawn']);
        $this->assertSame(['interior_lawn_ignored'], $result['warnings']);

        $answer = $this->answer('exterior-front');
        foreach ($answer['lawn']['areas'] as $i => $area) $answer['lawn']['areas'][$i]['box']['uMax'] = $area['box']['uMin'];
        $result = LabTourSurveyProtocol::parse($this->envelope('exterior-front', $answer), 'gpt-6-astra', 'resp_exterior_front');
        $this->assertSame(['status' => 'uncertain', 'condition' => 'healthy', 'areas' => []], $result['lawn']);
        $this->assertSame(['box_dropped', 'lawn_found_without_areas'], $result['warnings']);

        $answer = $this->answer('exterior-front');
        $answer['lawn']['status'] = 'uncertain';
        $answer['lawn']['condition'] = 'unknown';
        $answer['lawn']['areas'] = array_merge($answer['lawn']['areas'], $answer['lawn']['areas']);
        $result = LabTourSurveyProtocol::parse($this->envelope('exterior-front', $answer), 'gpt-6-astra', 'resp_exterior_front');
        $this->assertSame(['uncertain', null, 3], [$result['lawn']['status'], $result['lawn']['condition'], count($result['lawn']['areas'])]);
        $this->assertSame(['truncated_areas'], $result['warnings']);
    }

    public function test_exterior_none_lawn_drops_the_condition(): void
    {
        $result = $this->parseFixture('exterior-lawn-none-condition', 'resp_exterior_lawn_none_condition');
        $this->assertSame(['status' => 'none', 'condition' => null, 'areas' => []], $result['lawn']);
        $this->assertSame(['lawn_condition_dropped'], $result['warnings']);
        $this->assertSame('exterior', $result['space']['setting']);

        $answer = $this->answer('exterior-front');
        $answer['lawn']['status'] = 'none';
        $result = LabTourSurveyProtocol::parse($this->envelope('exterior-front', $answer), 'gpt-6-astra', 'resp_exterior_front');
        $this->assertSame(['status' => 'none', 'condition' => null, 'areas' => []], $result['lawn']);
        $this->assertSame(['lawn_condition_dropped', 'lawn_areas_dropped'], $result['warnings']);
        // Contract lawn refinements (tourContract.ts:167-170) on the normalised lawn.
        foreach ([$result, $this->parseFixture('exterior-front'), $this->parseFixture('kitchen')] as $parsed) {
            $lawn = $parsed['lawn'];
            if ($lawn['status'] === 'none') $this->assertSame([[], null], [$lawn['areas'], $lawn['condition']]);
            if ($lawn['status'] === 'found') $this->assertNotEmpty($lawn['areas']);
            $this->assertTrue($lawn['condition'] === null || preg_match('/\A[a-z0-9_]{1,64}\z/D', $lawn['condition']) === 1);
        }
    }

    public function test_items_capped_at_24_and_empty_surfaces_dropped(): void
    {
        $answer = $this->answer('kitchen');
        $template = $answer['clutter']['surfaces'][0]['items'][0];
        $items = fn (string $prefix) => array_map(fn ($i) => ['label' => $prefix.' '.$i] + $template, range(1, 12));
        $surface = fn (string $label, array $items) => ['label' => $label, 'box' => ['uMin' => 0.3, 'uMax' => 0.5, 'vMin' => 0.55, 'vMax' => 0.7], 'items' => $items];
        $second = $items('Cup');
        $second[4]['box'] = ['uMin' => 0.37, 'uMax' => 0.38, 'vMin' => 0.61, 'vMax' => 0.61];
        $answer['clutter']['surfaces'] = [$surface('Shelf', []), $surface('Counter', $second), $surface('Table', $items('Toy')), $surface('Desk', $items('Pen'))];
        $result = LabTourSurveyProtocol::parse($this->envelope('kitchen', $answer), 'gpt-6-astra', 'resp_kitchen');
        $surfaces = $result['clutter']['surfaces'];
        $this->assertSame(['Counter', 'Table', 'Desk'], array_column($surfaces, 'label'));
        $this->assertSame([11, 12, 1], array_map(fn ($s) => count($s['items']), $surfaces));
        $this->assertSame(['Cup 1', 'Cup 2', 'Cup 3', 'Cup 4', 'Cup 6'], array_slice(array_column($surfaces[0]['items'], 'label'), 0, 5));
        $this->assertSame(['Pen 1'], array_column($surfaces[2]['items'], 'label'));
        $this->assertSame(['empty_surface_dropped', 'box_dropped', 'items_over_capture_limit'], $result['warnings']);
        $this->assertTrue(array_is_list($surfaces));
        foreach ($surfaces as $kept) $this->assertTrue(array_is_list($kept['items']));

        // A model that ignores maxItems is truncated to 4 surfaces and 12 items per surface.
        $answer['clutter']['surfaces'] = [$surface('A', array_merge($items('x'), $items('y'))), $surface('B', $items('b')), $surface('C', []), $surface('D', []), $surface('E', $items('e'))];
        $result = LabTourSurveyProtocol::parse($this->envelope('kitchen', $answer), 'gpt-6-astra', 'resp_kitchen');
        $this->assertSame(['A', 'B'], array_column($result['clutter']['surfaces'], 'label'));
        $this->assertSame([12, 12], array_map(fn ($s) => count($s['items']), $result['clutter']['surfaces']));
        $this->assertSame(['truncated_surfaces', 'truncated_items', 'empty_surface_dropped'], $result['warnings']);
    }

    private function recipe(): array
    {
        return json_decode(file_get_contents(resource_path('iguide-lab/tour-recipes/tour-survey-1.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<array{id:string,sha256:string,bytes:string}> */
    private function images(): array
    {
        $png = function (int $width, int $height, array $rgb): string {
            $image = imagecreatetruecolor($width, $height);
            imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
            ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);

            return $bytes;
        };
        $images = [];
        foreach ([['source_panorama', 2048, 1024], ['view_front', 1024, 1024], ['view_right', 1024, 1024], ['view_back', 1024, 1024], ['view_left', 1024, 1024]] as $i => [$id, $w, $h]) {
            $bytes = $png($w, $h, [40 * $i, 120, 200 - 30 * $i]);
            $images[] = ['id' => $id, 'sha256' => hash('sha256', $bytes), 'bytes' => $bytes];
        }

        return $images;
    }

    private function fixture(string $name): string { return file_get_contents(self::FIXTURES.$name.'.json'); }

    private function answer(string $name): array
    {
        return json_decode(json_decode($this->fixture($name), true)['output'][1]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
    }

    private function envelope(string $name, array $answer): string
    {
        $envelope = json_decode($this->fixture($name), true);
        $envelope['output'][1]['content'][0]['text'] = json_encode($answer, JSON_THROW_ON_ERROR);

        return json_encode($envelope, JSON_THROW_ON_ERROR);
    }

    private function parseFixture(string $name, ?string $id = null): array
    {
        return LabTourSurveyProtocol::parse($this->fixture($name), 'gpt-6-astra', $id ?? 'resp_'.str_replace('-', '_', $name));
    }

    private function assertParseFails(string $code, string $body, string $responseId, ?string $parseError = null): void
    {
        try {
            LabTourSurveyProtocol::parse($body, 'gpt-6-astra', $responseId);
            $this->fail('Expected '.$code);
        } catch (LabTourSurveyParseException $e) {
            $this->assertSame($code, $e->reasonCode);
            $this->assertMatchesRegularExpression('/\A[a-z0-9_]{1,64}\z/D', $e->reasonCode);
            $this->assertIsString($e->parseError);
            $this->assertLessThanOrEqual(120, strlen($e->parseError));
            if ($parseError !== null) $this->assertSame($parseError, $e->parseError);
        }
    }

    private function assertThrowsCode(callable $call, string $class): void
    {
        try {
            $call();
            $this->fail('Expected '.$class);
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);
        }
    }
}
