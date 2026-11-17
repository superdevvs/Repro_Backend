<?php
namespace Tests\Unit;

use App\Services\IguideLab\LabDeclutterArtifacts;
use App\Services\IguideLab\LabInteriorDesignFramingProtocol;
use App\Services\IguideLab\LabProseText;
use App\Services\IguideLab\LabScenePlanProtocol;
use App\Services\IguideLab\LabStagingFramingProtocol;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

/** PHP parsers of model JSON clip over-long prose to its bound (same rule as Python); every other bound stays strict. */
final class IguideLabProseClippingProtocolsTest extends TestCase
{
    private const WORDS = 'Possible reflection in the tall hall mirror needs a closer look on the actual cut. ';

    private static function prose(int $length): string
    {
        $text = substr(str_repeat(self::WORDS, intdiv($length, strlen(self::WORDS)) + 2), 0, $length);
        return str_ends_with($text, ' ') ? substr($text, 0, -1).'x' : $text;
    }

    private function assertClipped(string $original, string $value, int $max): void
    {
        $this->assertLessThanOrEqual($max, mb_strlen($value));
        $this->assertStringEndsWith(LabProseText::ELLIPSIS, $value);
        $this->assertStringStartsWith(mb_substr($value, 0, -1), $original);
    }

    private function rejects(\Closure $parse): void
    {
        try { $parse(); } catch (InvalidArgumentException) { $this->addToAssertionCount(1); return; }
        $this->fail('An over-long non-prose field must still be rejected.');
    }

    private function scenePlan(array $changes = []): array
    {
        $context = ['sourceSha256' => str_repeat('a', 64), 'projection' => 'equirectangular', 'preset' => 'declutter', 'style' => null, 'viewIds' => ['source', 'yaw_0']];
        $proposal = array_replace(['schemaVersion' => LabScenePlanProtocol::VERSION, 'sourceSha256' => str_repeat('a', 64), 'projection' => 'equirectangular', 'preset' => 'declutter', 'style' => null,
            'summary' => 'Remove the vase on the kitchen counter.',
            'protectedFeatures' => [['id' => 'window', 'description' => 'Window frame', 'evidenceViewIds' => ['source'], 'confidence' => 'high']],
            'targets' => [['id' => 'vase', 'description' => 'The vase', 'action' => 'remove', 'objectCount' => 1, 'evidenceViewIds' => ['yaw_0']]],
            'sourceArtifacts' => ['Camera silhouette at the nadir'], 'uncertainties' => ['Mask needs review'],
            'workflow' => ['mode' => 'whole_panorama', 'reason' => 'Small change.', 'sections' => []],
            'generationPrompt' => 'Remove only the vase. Keep everything else unchanged.',
            'reviewChecks' => array_map(fn ($c) => ['check' => $c, 'instruction' => 'Inspect the source and result.'], ['edit', 'geometry', 'seam', 'appearance'])], $changes);
        $response = ['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => json_encode($proposal, JSON_THROW_ON_ERROR)]]]]];
        return LabScenePlanProtocol::parse($response, $context)['proposal'];
    }

    public function test_scene_plan_clips_summary_descriptions_reasons_and_caveat_lists(): void
    {
        Log::spy();
        $long = ['summary' => self::prose(803), 'description' => self::prose(1200), 'reason' => self::prose(801), 'artifact' => self::prose(301), 'uncertainty' => self::prose(999)];
        $p = $this->scenePlan(['summary' => $long['summary'],
            'protectedFeatures' => [['id' => 'window', 'description' => $long['description'], 'evidenceViewIds' => ['source'], 'confidence' => 'high']],
            'targets' => [['id' => 'vase', 'description' => $long['description'], 'action' => 'remove', 'objectCount' => 1, 'evidenceViewIds' => ['yaw_0']]],
            'sourceArtifacts' => [$long['artifact']], 'uncertainties' => [$long['uncertainty']],
            'workflow' => ['mode' => 'whole_panorama', 'reason' => $long['reason'], 'sections' => []]]);
        $this->assertClipped($long['summary'], $p['summary'], 800);
        $this->assertClipped($long['description'], $p['protectedFeatures'][0]['description'], 400);
        $this->assertClipped($long['description'], $p['targets'][0]['description'], 500);
        $this->assertClipped($long['reason'], $p['workflow']['reason'], 800);
        $this->assertClipped($long['artifact'], $p['sourceArtifacts'][0], 300);
        $this->assertClipped($long['uncertainty'], $p['uncertainties'][0], 400);
        Log::shouldHaveReceived('warning')->with('iguide-lab.prose_clipped', \Mockery::on(fn ($c) => $c === ['protocol' => 'scene-plan', 'path' => '$.targets[0].description', 'length' => 1200, 'maxLength' => 500]))->once();
    }

    public function test_scene_plan_keeps_ids_prompts_instructions_and_counts_strict(): void
    {
        $this->assertSame('Remove the vase on the kitchen counter.', $this->scenePlan()['summary']);
        $this->rejects(fn () => $this->scenePlan(['targets' => [['id' => str_repeat('v', 41), 'description' => 'Vase', 'action' => 'remove', 'objectCount' => 1, 'evidenceViewIds' => ['yaw_0']]]]));
        $this->rejects(fn () => $this->scenePlan(['generationPrompt' => str_repeat('Remove the vase. ', 300)]));
        $this->rejects(fn () => $this->scenePlan(['reviewChecks' => array_map(fn ($c) => ['check' => $c, 'instruction' => self::prose(451)], ['edit', 'geometry', 'seam', 'appearance'])]));
        $this->rejects(fn () => $this->scenePlan(['uncertainties' => array_fill(0, 7, 'Unclear.')]));
        $this->rejects(fn () => $this->scenePlan(['summary' => '']));
        $this->rejects(fn () => $this->scenePlan(['sourceSha256' => str_repeat('a', 65)]));
    }

    public function test_staging_framing_clips_summary_and_constraint_reasons_only(): void
    {
        [$context] = IguideLabStagingFramingProtocolTest::fixture();
        $value = IguideLabStagingFramingProtocolTest::candidate($context);
        $summary = self::prose(1290); $reason = self::prose(560);
        $value['summary'] = $summary; $value['constraints']['optical_dependencies']['reason'] = $reason;
        $parsed = LabStagingFramingProtocol::parse(IguideLabStagingFramingProtocolTest::response($value), $context);
        $this->assertSame('framing_candidate', $parsed['status']);
        $this->assertClipped($summary, $parsed['summary'], 1200);
        $this->assertClipped($reason, $parsed['constraints']['optical_dependencies']['reason'], 550);
        $bad = IguideLabStagingFramingProtocolTest::candidate($context); $bad['sourceSha256'] .= 'a';
        $this->rejects(fn () => LabStagingFramingProtocol::parse(IguideLabStagingFramingProtocolTest::response($bad), $context));
        $bad = IguideLabStagingFramingProtocolTest::candidate($context); $bad['constraints']['optical_dependencies']['reason'] = ' ';
        $this->rejects(fn () => LabStagingFramingProtocol::parse(IguideLabStagingFramingProtocolTest::response($bad), $context));
    }

    public function test_interior_framing_clips_style_descriptions_but_echoed_material_identity_stays_strict(): void
    {
        [$context] = IguideLabInteriorDesignFramingProtocolTest::fixture();
        $value = IguideLabInteriorDesignFramingProtocolTest::candidate($context);
        $feature = self::prose(640); $summary = self::prose(1250); $value['styleFeatures'][0]['description'] = $feature; $value['summary'] = $summary;
        $parsed = LabInteriorDesignFramingProtocol::parse(IguideLabInteriorDesignFramingProtocolTest::response($value), $context);
        $this->assertClipped($feature, $parsed['styleFeatures'][0]['description'], 500);
        $this->assertClipped($summary, $parsed['summary'], 1200);
        // sourceDescription/plannedChange are echoed exactly by the cut and candidate phases: never clipped.
        foreach (['sourceDescription' => 201, 'plannedChange' => 501] as $key => $length) {
            $bad = IguideLabInteriorDesignFramingProtocolTest::candidate($context); $bad['targets'][0][$key] = self::prose($length);
            $this->rejects(fn () => LabInteriorDesignFramingProtocol::parse(IguideLabInteriorDesignFramingProtocolTest::response($bad), $context));
        }
    }

    public function test_removal_review_context_keeps_a_long_protected_feature_note_within_the_evaluator_bound(): void
    {
        // A plan note may use its whole 600-character bound; 'id: note' must not abort the final check after the image exists.
        $source = ['protectedFeatures' => [], 'uncertainties' => [], 'sourceArtifacts' => [], 'reviewChecks' => []];
        $note = self::prose(600);
        $plan = ['target' => ['description' => 'opaque knife block'], 'shadow' => ['status' => 'none_observed'], 'uncertainties' => [],
            'protectedFeatures' => [['id' => str_repeat('f', 80), 'notes' => $note]], 'receiver' => ['material' => 'matte_simple_continuous']];
        $features = app(LabDeclutterArtifacts::class)->removalReviewContext($source, $plan, [])['protectedFeatures'];
        $this->assertClipped(str_repeat('f', 80).': '.$note, $features[0], 600);
    }

    public function test_path_patterns_match_only_the_named_prose(): void
    {
        $value = ['summary' => str_repeat('s', 20), 'id' => str_repeat('i', 20), 'checks' => ['a' => ['reason' => str_repeat('r', 20), 'id' => str_repeat('i', 20)]],
            'deep' => ['x' => ['y' => ['reason' => str_repeat('d', 20)]]]];
        $fit = LabProseText::fitPaths($value, ['summary' => 10, 'checks.*.reason' => 10, '**.y.reason' => 5], 'test');
        $this->assertSame([str_repeat('s', 9).'…', str_repeat('i', 20), str_repeat('r', 9).'…', str_repeat('i', 20), str_repeat('d', 4).'…'],
            [$fit['summary'], $fit['id'], $fit['checks']['a']['reason'], $fit['checks']['a']['id'], $fit['deep']['x']['y']['reason']]);
    }
}
