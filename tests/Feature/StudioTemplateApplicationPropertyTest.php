<?php

namespace Tests\Feature;

use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature: ai-editing-studio-revamp, Property 39: Templates apply their persisted configuration
 *
 * **Validates: Requirements 13.10**
 *
 * The project-create operation is currently represented by the template API's
 * projectDefaults handoff. This property verifies that the handoff always uses
 * the latest authorized persisted values and never caller-supplied demo defaults.
 *
 * The deterministic sweep runs 24 cases, the smallest multiple of the six
 * workflow ids and eight generated config shapes, so every workflow id and
 * every config shape is exercised at least once (asserted by a coverage guard).
 */
class StudioTemplateApplicationPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/studio/templates';
    private const ITERATIONS = 24;
    private const CONFIG_SHAPES = 8;
    private const SEED = 39_13_10;
    private const WORKFLOW_IDS = [
        'photo-enhancement',
        'twilight',
        'video-cleanup',
        'listing-video',
        'reel-generator',
        'batch-ai-jobs',
    ];

    public function test_property_39_templates_apply_their_persisted_configuration(): void
    {
        $teamId = 391_310;
        $owner = $this->actor('editor', $teamId);
        $peer = $this->actor('editor', $teamId);
        $teamAdmin = $this->actor('admin', $teamId);
        $outsideAdmin = $this->actor('admin', $teamId + 1);

        $workflowsExercised = [];
        $configShapesExercised = [];

        for ($case = 0; $case < self::ITERATIONS; $case++) {
            $workflowsExercised[$case % count(self::WORKFLOW_IDS)] = self::WORKFLOW_IDS[$case % count(self::WORKFLOW_IDS)];
            $configShapesExercised[$case % self::CONFIG_SHAPES] = true;
            $this->assertTemplateApplicationCase($case, $owner, $peer, $teamAdmin, $outsideAdmin);
        }

        ksort($workflowsExercised);
        ksort($configShapesExercised);

        $this->assertSame(
            self::WORKFLOW_IDS,
            array_values($workflowsExercised),
            'Reduced case count must still exercise every workflow id.'
        );
        $this->assertSame(
            range(0, self::CONFIG_SHAPES - 1),
            array_keys($configShapesExercised),
            'Reduced case count must still exercise every generated config shape.'
        );
    }

    private function assertTemplateApplicationCase(
        int $case,
        User $owner,
        User $peer,
        User $teamAdmin,
        User $outsideAdmin
    ): void {
        $context = 'seed='.self::SEED.", case={$case}";
        $workflowId = self::WORKFLOW_IDS[$case % count(self::WORKFLOW_IDS)];
        $latestConfig = $this->generatedConfig($case);
        $staleSentinel = "STALE-PERSISTED-{$case}";
        $clientSentinel = "CLIENT-DEMO-{$case}";

        $template = Template::query()->create([
            'team_id' => 391_310,
            'created_by' => $owner->id,
            'name' => "Generated Template {$case}",
            'workflow_id' => self::WORKFLOW_IDS[($case + 1) % count(self::WORKFLOW_IDS)],
            'config' => ['preset' => $staleSentinel],
        ]);

        Sanctum::actingAs($owner);
        $updated = $this->putJson(self::URL.'/'.$template->id, [
            'name' => "Generated Template {$case}",
            'workflowId' => $workflowId,
            'config' => $latestConfig,
            'version' => 1,
            'projectDefaults' => [
                'workflowId' => 'client-demo-workflow',
                'workflowConfig' => ['preset' => $clientSentinel],
            ],
            'workflowConfig' => ['preset' => $clientSentinel],
        ])->assertOk();

        $persisted = $template->fresh();
        $this->assertSame($workflowId, $persisted->workflow_id, "Persisted workflow mismatch ({$context}).");
        $this->assertSame($latestConfig, $persisted->config, "Persisted config mismatch ({$context}).");
        $this->assertExactDefaults($updated->json('data'), $persisted, $latestConfig, $context);

        $query = http_build_query([
            'workflowId' => 'client-demo-workflow',
            'workflowConfig' => $clientSentinel,
        ]);
        Sanctum::actingAs($owner);
        $ownerData = $this->getJson(self::URL.'?'.$query)->assertOk()->json('data');
        $this->assertExactDefaults(
            $this->templateFromResponse($ownerData, $template, $context),
            $persisted,
            $latestConfig,
            "owner; {$context}"
        );

        Sanctum::actingAs($teamAdmin);
        $adminData = $this->getJson(self::URL.'?'.$query)->assertOk()->json('data');
        $this->assertExactDefaults(
            $this->templateFromResponse($adminData, $template, $context),
            $persisted,
            $latestConfig,
            "team admin; {$context}"
        );

        Sanctum::actingAs($peer);
        $peerData = $this->getJson(self::URL)->assertOk()->json('data');
        $this->assertTemplateIsAbsent($peerData, $template, "same-team peer editor; {$context}");

        Sanctum::actingAs($outsideAdmin);
        $outsideData = $this->getJson(self::URL)->assertOk()->json('data');
        $this->assertTemplateIsAbsent($outsideData, $template, "outside-team admin; {$context}");
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $expectedConfig
     */
    private function assertExactDefaults(
        array $data,
        Template $persisted,
        array $expectedConfig,
        string $context
    ): void {
        $this->assertSame((string) $persisted->id, $data['projectDefaults']['templateId'] ?? null, "Template id mismatch ({$context}).");
        $this->assertSame($persisted->workflow_id, $data['workflowId'] ?? null, "API workflow mismatch ({$context}).");
        $this->assertSame($expectedConfig, $data['config'] ?? null, "API config mismatch ({$context}).");
        $this->assertSame($persisted->workflow_id, $data['projectDefaults']['workflowId'] ?? null, "Project workflow default mismatch ({$context}).");
        $this->assertSame($expectedConfig, $data['projectDefaults']['workflowConfig'] ?? null, "Project config default mismatch ({$context}).");

        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('CLIENT-DEMO-', $encoded, "Client/demo defaults leaked into application defaults ({$context}).");
        $this->assertStringNotContainsString('STALE-PERSISTED-', $encoded, "Stale persisted defaults replaced latest values ({$context}).");
    }

    /**
     * @param array<int, array<string, mixed>> $data
     * @return array<string, mixed>
     */
    private function templateFromResponse(array $data, Template $template, string $context): array
    {
        $match = collect($data)->firstWhere('id', (string) $template->id);
        $this->assertIsArray($match, "Authorized template was omitted ({$context}).");

        return $match;
    }

    /** @param array<int, array<string, mixed>> $data */
    private function assertTemplateIsAbsent(array $data, Template $template, string $context): void
    {
        $this->assertFalse(
            collect($data)->contains(fn (array $item): bool => ($item['id'] ?? null) === (string) $template->id),
            "Out-of-scope template was exposed ({$context})."
        );
    }

    /** @return array<string, mixed> */
    private function generatedConfig(int $case): array
    {
        $token = substr(hash('sha256', self::SEED.':'.$case), 0, 12);

        return match ($case % self::CONFIG_SHAPES) {
            0 => ['case' => $case],
            1 => ['strength' => ($case * 17) % 101, 'enabled' => $case % 2 === 0, 'token' => $token],
            2 => ['look' => ['temperature' => ($case % 81) - 40, 'contrast' => $case % 101], 'case' => $case],
            3 => ['transitions' => ["cut-{$token}", "fade-{$case}"], 'durationSeconds' => 5 + ($case % 176)],
            4 => ['branding' => ['enabled' => true, 'placement' => $case % 2 === 0 ? 'top-left' : 'bottom-right'], 'case' => $case],
            5 => ['optionalAsset' => null, 'labels' => ['case' => "case-{$case}", 'token' => $token]],
            6 => ['limits' => ['minimum' => -$case, 'maximum' => $case * 1000], 'preserveOriginal' => false],
            default => ['pipeline' => [['step' => 'normalize', 'value' => $case], ['step' => 'render', 'value' => $token]]],
        };
    }

    private function actor(string $role, int $teamId): User
    {
        return User::factory()->create([
            'role' => $role,
            'metadata' => ['team_id' => $teamId],
        ]);
    }
}
