<?php

namespace Tests\Feature;

use App\Services\Accounting\AiUsageRecorder;
use App\Services\IguideLab\LabAnalysisRecipes;
use App\Services\IguideLab\LabInteriorDesignCandidateProtocol;
use App\Services\IguideLab\LabInteriorDesignCutProtocol;
use App\Services\IguideLab\LabInteriorDesignFramingProtocol;
use App\Services\IguideLab\LabInteriorDesignProtocol;
use App\Services\IguideLab\LabInteriorMaterialSourceProtocol;
use App\Services\IguideLab\LabInteriorRoomProfiles;
use App\Services\IguideLab\LabInteriorRoomRouteProtocol;
use App\Services\IguideLab\LabLifestyleFramingProtocol;
use App\Services\IguideLab\LabLifestyleOpticalProtocol;
use App\Services\IguideLab\LabLifestyleOriginalDetailTransport;
use App\Services\IguideLab\LabReasoningModel;
use App\Services\IguideLab\LabReasoningModelException;
use App\Services\IguideLab\LabRoomFurnishingFramingProtocol;
use App\Services\IguideLab\LabRuntimeManifestCheck;
use App\Services\IguideLab\LabRuntimeManifests;
use App\Services\IguideLab\LabScenePlanProtocol;
use App\Services\IguideLab\LabStagingFramingProtocol;
use App\Services\IguideLab\LabTourConfig;
use App\Services\IguideLab\LabTourConfigException;
use App\Services\IguideLab\LabTourSurveyProtocol;
use Tests\TestCase;
use Tests\Unit\IguideLabInteriorDesignCandidateProtocolTest;
use Tests\Unit\IguideLabInteriorDesignCutProtocolTest;
use Tests\Unit\IguideLabInteriorDesignFramingProtocolTest;
use Tests\Unit\IguideLabInteriorMaterialSourceProtocolTest;
use Tests\Unit\IguideLabInteriorRoomRouteProtocolTest;
use Tests\Unit\IguideLabLifestyleFramingProtocolTest;
use Tests\Unit\IguideLabLifestyleOpticalCutProtocolTest;
use Tests\Unit\IguideLabLifestyleOpticalProtocolTest;
use Tests\Unit\IguideLabRoomFurnishingProtocolTest;
use Tests\Unit\IguideLabStagingFramingProtocolTest;

/**
 * config('iguide-lab-models.reasoning') is the one lab reasoning model. The per-family paid flows (send, dated echo,
 * historical receipts, reserved requests) are covered in each family's feature test via Tests\Concerns\LabReasoningModelFlows.
 */
class IguideLabReasoningModelTest extends TestCase
{
    private const TERRA = 'gpt-6.1-terra';

    public function test_without_the_env_setting_every_lab_reasoning_request_still_names_astra(): void
    {
        $this->withoutEnv(['IGUIDE_LAB_REASONING_MODEL', 'IGUIDE_LAB_TOUR_SURVEY_MODEL'], function () {
            $this->assertSame('gpt-6-astra', (require config_path('iguide-lab-models.php'))['reasoning']);
            $this->assertSame('gpt-6-astra', (require config_path('iguide-lab-tour.php'))['survey']['model']);
            config(['iguide-lab-models' => require config_path('iguide-lab-models.php'), 'iguide-lab-tour' => require config_path('iguide-lab-tour.php')]);
        });
        $this->assertSame('gpt-6-astra', LabReasoningModel::current());
        foreach ($this->requests() as $family => $request) $this->assertSame('gpt-6-astra', $request()['model'], $family);
        $this->assertSame('gpt-6-astra', LabScenePlanProtocol::model());
    }

    public function test_each_request_family_names_the_configured_model_and_the_recipe_files_do_not_change(): void
    {
        $recipes = app(LabAnalysisRecipes::class); $before = $recipes->snapshot(LabAnalysisRecipes::BASELINE);
        config(['iguide-lab-models.reasoning' => self::TERRA, 'iguide-lab-tour.survey.model' => self::TERRA]);
        foreach ($this->requests() as $family => $request) $this->assertSame(self::TERRA, $request()['model'], $family);
        $this->assertSame(self::TERRA, LabScenePlanProtocol::model());
        // The content-addressed recipe still says what it was written with; only the request names the setting.
        $this->assertSame($before, $recipes->snapshot(LabAnalysisRecipes::BASELINE));
        $this->assertSame('gpt-6-astra', $before['recipe']['model']);
    }

    public function test_tour_survey_follows_the_lab_setting_unless_its_own_is_set_and_accepts_a_dated_echo(): void
    {
        $this->withoutEnv(['IGUIDE_LAB_REASONING_MODEL', 'IGUIDE_LAB_TOUR_SURVEY_MODEL'], function () {
            $this->withEnv(['IGUIDE_LAB_REASONING_MODEL' => self::TERRA], fn () => $this->assertSame(self::TERRA, (require config_path('iguide-lab-tour.php'))['survey']['model']));
            $this->withEnv(['IGUIDE_LAB_REASONING_MODEL' => self::TERRA, 'IGUIDE_LAB_TOUR_SURVEY_MODEL' => 'gpt-6-sol'],
                fn () => $this->assertSame('gpt-6-sol', (require config_path('iguide-lab-tour.php'))['survey']['model']));
        });
        config(['iguide-lab-tour.survey.model' => self::TERRA]);
        $this->assertSame(self::TERRA, LabTourConfig::surveyModel());
        $this->assertSame(self::TERRA, LabTourSurveyProtocol::snapshot()['model']);
        $kitchen = json_decode(file_get_contents(base_path('tests/Fixtures/iguide-lab/tour/responses/kitchen.json')), true, flags: JSON_THROW_ON_ERROR);
        $kitchen['model'] = 'gpt-6.1-terra-2026-11-01';
        $this->assertSame('Kitchen', LabTourSurveyProtocol::parse(json_encode($kitchen), self::TERRA, 'resp_kitchen')['space']['label']);
        $kitchen['model'] = 'gpt-6-astra';
        try { LabTourSurveyProtocol::parse(json_encode($kitchen), self::TERRA, 'resp_kitchen'); $this->fail('Another model\'s echo accepted.'); }
        catch (\App\Services\IguideLab\LabTourSurveyParseException $e) { $this->assertSame(['survey_unreadable', 'envelope_model'], [$e->reasonCode, $e->parseError]); }
        foreach (['gpt-5', 'gpt-6.1-terra-2026-11-01', ''] as $bad) {
            config(['iguide-lab-tour.survey.model' => $bad]);
            try { LabTourConfig::surveyModel(); $this->fail('Unsupported survey model accepted: '.$bad); }
            catch (LabTourConfigException $e) { $this->assertSame('survey_misconfigured', $e->reasonCode); }
        }
    }

    public function test_an_unlisted_configured_model_is_a_clear_misconfiguration_never_a_fallback(): void
    {
        foreach (['gpt-5', 'gpt-6.1-terra-2026-11-01', 'gpt-6-luna', '', null, ['gpt-6-astra']] as $bad) {
            config(['iguide-lab-models.reasoning' => $bad]);
            try { LabReasoningModel::current(); $this->fail('Unlisted model accepted.'); }
            catch (LabReasoningModelException $e) { $this->assertStringContainsString('IGUIDE_LAB_REASONING_MODEL', $e->getMessage()); $this->assertStringContainsString('gpt-6.1-terra', $e->getMessage()); }
        }
        config(['iguide-lab-models.reasoning' => 'gpt-5']);
        [$context, $images] = IguideLabStagingFramingProtocolTest::fixture();
        $this->expectException(LabReasoningModelException::class);
        LabStagingFramingProtocol::request($context, $images);
    }

    public function test_an_echo_must_be_the_requested_model_or_its_dated_snapshot(): void
    {
        $this->assertTrue(LabReasoningModel::echoes(self::TERRA, self::TERRA));
        $this->assertTrue(LabReasoningModel::echoes(self::TERRA, 'gpt-6.1-terra-2026-11-01'));
        $this->assertTrue(LabReasoningModel::echoes('gpt-6-astra', 'gpt-6-astra-2026-10-01'));
        foreach (['gpt-6.1-terra-latest', 'gpt-6.1-terra-2026-11', 'gpt-6-astra', 'gpt-6.1-terra-2026-11-01x', 'GPT-6.1-TERRA', null, 7] as $echo) {
            $this->assertFalse(LabReasoningModel::echoes(self::TERRA, $echo), json_encode($echo));
        }
        $this->assertFalse(LabReasoningModel::echoes('gpt-5', 'gpt-5'), 'An unlisted request never validates.');
        $this->assertTrue(LabReasoningModel::known('gpt-6-sol-2026-01-31')); $this->assertTrue(LabReasoningModel::known('gpt-6-astra'));
        $this->assertFalse(LabReasoningModel::known('gpt-5')); $this->assertFalse(LabReasoningModel::known('gpt-6-solar'));
        $this->assertSame(self::TERRA, LabReasoningModel::attempted([['requestedModelId' => self::TERRA], ['requestedModelId' => self::TERRA]]));
        foreach ([[], [['requestedModelId' => self::TERRA], ['requestedModelId' => 'gpt-6-astra']], [['requestedModelId' => 'gpt-5']], [[]], null] as $attempts) {
            $this->assertNull(LabReasoningModel::attempted($attempts));
        }
        $this->assertSame(self::TERRA, LabReasoningModel::ofPayload('{"model":"gpt-6.1-terra","store":false}'));
        foreach (['{"store":false,"model":"gpt-6.1-terra"}', '{"model":"gpt-5"}', ''] as $json) {
            try { LabReasoningModel::ofPayload($json); $this->fail('Unlisted stored model accepted.'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        // Interior parsers re-check a known echo; the paid receipt checks bind it to the requested model.
        $value = ['version' => 'fixture'];
        $this->assertSame($value, LabInteriorDesignProtocol::parsed(['model' => 'gpt-6.1-terra-2026-11-01'] + LabInteriorDesignProtocol::response($value)));
        $this->expectException(\InvalidArgumentException::class);
        LabInteriorDesignProtocol::parsed(['model' => 'gpt-5'] + LabInteriorDesignProtocol::response($value));
    }

    public function test_replay_recompiles_with_the_stored_model_and_restores_the_setting(): void
    {
        config(['iguide-lab-models.reasoning' => self::TERRA]);
        [$context, $images] = IguideLabStagingFramingProtocolTest::fixture();
        $astra = LabReasoningModel::replay('gpt-6-astra', fn () => LabStagingFramingProtocol::request($context, $images));
        $this->assertSame('gpt-6-astra', $astra['model']); $this->assertSame(self::TERRA, LabReasoningModel::current());
        $this->assertSame(array_replace($astra, ['model' => self::TERRA]), LabStagingFramingProtocol::request($context, $images));
        // A reserved Python compile request is rerun with the model it already names; a new one takes the setting.
        $file = tempnam(sys_get_temp_dir(), 'compile-request-'); unlink($file);
        try {
            $this->assertSame(self::TERRA, LabReasoningModel::forRequestFile($file));
            file_put_contents($file, json_encode(['operation' => 'compile', 'model' => 'gpt-6-astra']));
            $this->assertSame('gpt-6-astra', LabReasoningModel::forRequestFile($file));
            foreach ([json_encode(['operation' => 'compile']), json_encode(['model' => 'gpt-5']), 'not json'] as $bad) {
                file_put_contents($file, $bad);
                try { LabReasoningModel::forRequestFile($file); $this->fail('A reserved request without an allowed model was reused.'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
            }
        } finally { if (is_file($file)) unlink($file); }
        $this->expectException(\InvalidArgumentException::class);
        LabReasoningModel::replay('gpt-5', fn () => null);
    }

    public function test_php_and_python_allow_lists_are_identical(): void
    {
        $python = file_get_contents(resource_path('iguide-lab/staging_assessment_protocol.py'));
        $this->assertSame(1, preg_match('/^REASONING_MODELS=\(([^)]*)\)$/m', $python, $match));
        preg_match_all("/'([^']+)'/", $match[1], $models);
        $this->assertSame(config('iguide-lab-models.allowed'), $models[1]);
        $this->assertSame((require config_path('iguide-lab-models.php'))['allowed'], LabReasoningModel::allowed());
        foreach (['grass_assessment', 'grass_source_admission', 'declutter_removal', 'lifestyle_assessment'] as $protocol) {
            $source = file_get_contents(resource_path('iguide-lab/'.$protocol.'_protocol.py'));
            $this->assertStringNotContainsString('gpt-6', $source, $protocol.' must use the shared list.');
        }
    }

    public function test_changing_the_setting_changes_no_runtime_manifest_pin_or_reserved_policy(): void
    {
        config(['iguide-lab.pipeline_python_path' => PHP_BINARY, 'iguide-lab.lighting_d2_enabled' => true]);
        $pinned = function (): array {
            $out = [];
            foreach (array_keys(LabRuntimeManifestCheck::OWNERS) as $key) {
                try { $out[$key] = app(LabRuntimeManifests::class)->pins($key); }
                catch (\Throwable $e) { $out[$key] = 'unavailable: '.$e::class; }
            }
            $out['policies'] = [LabInteriorRoomProfiles::snapshot(), LabInteriorRoomRouteProtocol::snapshot(), LabLifestyleOriginalDetailTransport::snapshot()];

            return $out;
        };
        $astra = $pinned();
        config(['iguide-lab-models.reasoning' => self::TERRA]); $terra = $pinned();
        config(['iguide-lab-models.reasoning' => 'gpt-6-sol']); $sol = $pinned();
        $this->assertSame($astra, $terra); $this->assertSame($astra, $sol);
        // The owners whose pinned implementation used to carry the model value are really computed here.
        foreach (['staging_framing', 'lifestyle_framing', 'interior_design', 'interior_room', 'lifestyle_optical', 'staging_assessment', 'grass_assessment',
            'grass_source_admission', 'declutter_removal', 'lifestyle_assessment'] as $key) $this->assertIsArray($astra[$key], $key);
        $this->assertStringNotContainsString('gpt-6', json_encode($astra));
    }

    public function test_usage_estimates_price_dotted_and_dated_model_ids_and_label_lab_features(): void
    {
        $recorder = app(AiUsageRecorder::class); $usage = ['input_tokens' => 1_000_000, 'output_tokens' => 1_000_000, 'input_tokens_details' => ['cached_tokens' => 200_000]];
        $this->assertSame(58.2, $recorder->estimate('gpt-6-astra', $usage));
        $this->assertSame(5.81, $recorder->estimate('gpt-6.1-terra-2026-11-01', $usage));
        $this->assertSame(11.64, $recorder->estimate('gpt-6-sol', $usage));
        $this->assertSame(11.62, $recorder->estimate('gpt-6.1-sol', $usage));
        $this->assertSame(0.582, $recorder->estimate('gpt-6-luna', $usage));
        $this->assertNull($recorder->estimate('gpt-6.1-unknown', $usage));
        $image = ['input_tokens' => 300, 'output_tokens' => 1000, 'input_tokens_details' => ['text_tokens' => 100, 'image_tokens' => 200]];
        $this->assertSame(round((100 * 5 + 200 * 8 + 1000 * 30) / 1_000_000, 8), $recorder->estimate('gpt-image-2.6-sunburst', $image));
        $this->assertSame('2026-11-17', config('ai_usage.pricing_version'));
        foreach (['workflow_staging_assessment', 'workflow_staging_framing', 'workflow_grass_assessment', 'workflow_grass_source_admission', 'workflow_declutter_removal',
            'workflow_interior_assessment', 'workflow_interior_room_assessment', 'workflow_lifestyle_assessment', 'workflow_lifestyle_framing', 'workflow_visual_evaluation'] as $feature) {
            $this->assertArrayHasKey($feature, AiUsageRecorder::FEATURES); $this->assertNotSame('', AiUsageRecorder::FEATURES[$feature]);
        }
    }

    /** @return array<string, callable(): array> one request builder per PHP-compiled lab reasoning family */
    private function requests(): array
    {
        $recipe = function (string $version, array $context, array $images): array {
            $recipes = app(LabAnalysisRecipes::class);

            return $recipes->request($recipes->snapshot($version), $context, $images);
        };
        $png = (string) \Intervention\Image\ImageManager::gd()->create(128, 64)->fill('888888')->toPng();
        $scene = [['sourceSha256' => hash('sha256', $png), 'projection' => 'equirectangular', 'preset' => 'declutter', 'style' => null, 'viewIds' => ['source'], 'instructions' => ''],
            ['data:image/png;base64,'.base64_encode($png)]];

        return [
            'staging framing' => fn () => LabStagingFramingProtocol::request(...IguideLabStagingFramingProtocolTest::fixture()),
            'lifestyle framing' => fn () => LabLifestyleFramingProtocol::request(...IguideLabLifestyleFramingProtocolTest::fixture()),
            'lifestyle optical' => fn () => LabLifestyleOpticalProtocol::request(...IguideLabLifestyleOpticalProtocolTest::fixture()),
            'lifestyle optical native detail' => function () {
                [$context, $images] = IguideLabLifestyleOpticalProtocolTest::fixture();
                return LabLifestyleOriginalDetailTransport::framing(...[...IguideLabLifestyleOpticalProtocolTest::followup($context, $images), LabLifestyleOriginalDetailTransport::snapshot()]);
            },
            'interior framing' => fn () => LabInteriorDesignFramingProtocol::request(...IguideLabInteriorDesignFramingProtocolTest::fixture()),
            'interior cut' => fn () => LabInteriorDesignCutProtocol::request(...IguideLabInteriorDesignCutProtocolTest::fixture()),
            'interior candidate' => fn () => LabInteriorDesignCandidateProtocol::request(...IguideLabInteriorDesignCandidateProtocolTest::fixture()),
            'interior material evidence' => fn () => LabInteriorMaterialSourceProtocol::request(...IguideLabInteriorMaterialSourceProtocolTest::fixture()),
            'interior room route' => fn () => LabInteriorRoomRouteProtocol::request(...IguideLabInteriorRoomRouteProtocolTest::fixture()),
            'interior room furnishing' => fn () => IguideLabRoomFurnishingProtocolTest::wireFixture(LabRoomFurnishingFramingProtocol::class)[2],
            'scene plan' => fn () => LabScenePlanProtocol::request(...$scene),
            'scene analysis recipe' => fn () => $recipe(LabAnalysisRecipes::CANDIDATE, ...$scene),
            'lifestyle original optical cut recipe' => fn () => $recipe(LabAnalysisRecipes::LIFESTYLE_ORIGINAL_OPTICAL_CUT, ...array_slice(IguideLabLifestyleOpticalCutProtocolTest::fixture(2), 0, 2)),
            'tour survey' => fn () => json_decode(LabTourSurveyProtocol::request(LabTourSurveyProtocol::context('Kitchen', null), $this->surveyImages()), true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    /** @return list<array{id:string,sha256:string,bytes:string}> */
    private function surveyImages(): array
    {
        $images = [];
        foreach ([['source_panorama', 2048, 1024], ['view_front', 1024, 1024], ['view_right', 1024, 1024], ['view_back', 1024, 1024], ['view_left', 1024, 1024]] as $i => [$id, $w, $h]) {
            $image = imagecreatetruecolor($w, $h); imagefill($image, 0, 0, imagecolorallocate($image, 40 * $i, 120, 90));
            ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
            $images[] = ['id' => $id, 'sha256' => hash('sha256', $bytes), 'bytes' => $bytes];
        }

        return $images;
    }

    private function withoutEnv(array $names, callable $callback): void
    {
        $saved = [];
        foreach ($names as $name) { $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)]; unset($_ENV[$name], $_SERVER[$name]); putenv($name); }
        try { $callback(); } finally {
            foreach ($saved as $name => [$env, $server, $process]) {
                if ($env !== null) $_ENV[$name] = $env;
                if ($server !== null) $_SERVER[$name] = $server;
                if ($process !== false) putenv($name.'='.$process);
            }
        }
    }

    private function withEnv(array $values, callable $callback): void
    {
        foreach ($values as $name => $value) { $_ENV[$name] = $_SERVER[$name] = $value; putenv($name.'='.$value); }
        try { $callback(); } finally {
            foreach (array_keys($values) as $name) { unset($_ENV[$name], $_SERVER[$name]); putenv($name); }
        }
    }
}
