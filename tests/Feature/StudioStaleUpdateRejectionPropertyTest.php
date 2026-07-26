<?php

namespace Tests\Feature;

use App\Models\BrandState;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature: ai-editing-studio-revamp, Property 29: Stale updates are rejected
 *
 * **Validates: Requirements 10.9**
 *
 * A seeded generator drives 21 reproducible conflict cases (7 per mutation)
 * across the real Studio mutation endpoints that carry versions (template
 * update, template delete, brand update). In every case two authorized writers
 * load the same record version, a second writer commits first (one or two
 * times), and then the first writer submits its now-stale version.
 *
 * For every case the property asserts:
 *  - the stale write is rejected (409 + `stale_version`, never a 2xx),
 *  - the response returns the latest committed version and the latest
 *    committed state of the record,
 *  - the stored record is unchanged by the rejected write (version, payload,
 *    ownership, update timestamp, and existence all match what the winning
 *    writer committed).
 */
class StudioStaleUpdateRejectionPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const ITERATIONS = 21;
    private const SEED = 29_10_9;

    private const TEMPLATES_URL = '/api/studio/templates';
    private const BRAND_URL = '/api/studio/brand';

    /** Roles with team-wide scope, so two distinct writers can conflict on one record. */
    private const TEAM_SCOPED_ROLES = ['admin', 'superadmin', 'editing_manager'];

    private const WORKFLOW_IDS = [
        'photo-enhancement',
        'twilight',
        'video-cleanup',
        'listing-video',
        'reel-generator',
        'batch-ai-jobs',
    ];

    private const MUTATIONS = ['template.update', 'template.delete', 'brand.update'];

    public function test_property_29_stale_updates_are_rejected(): void
    {
        mt_srand(self::SEED);

        $covered = [];
        $cases = 0;

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            match ($iteration % 3) {
                0 => $this->runTemplateUpdateConflict($iteration, $covered),
                1 => $this->runTemplateDeleteConflict($iteration, $covered),
                default => $this->runBrandUpdateConflict($iteration, $covered),
            };
            $cases++;
        }

        $this->assertSame(self::ITERATIONS, $cases, 'Every generated case must execute.');
        $this->assertGreaterThanOrEqual(21, $cases, 'The property needs at least 21 reproducible cases.');
        $this->assertEqualsCanonicalizing(
            self::MUTATIONS,
            array_keys($covered),
            'Every versioned Studio mutation endpoint must be exercised.'
        );
    }

    /**
     * Writer A loads a template, writer B commits updates first, A's stale update is rejected.
     *
     * @param array<string, true> $covered
     */
    private function runTemplateUpdateConflict(int $iteration, array &$covered): void
    {
        $teamId = 290_000 + $iteration;
        [$writerA, $writerB] = $this->writers($teamId);
        $context = $this->context($iteration, "template.update, team={$teamId}");

        $templateId = $this->createTemplate($writerA, $iteration);
        $staleVersion = (int) Template::query()->findOrFail($templateId)->version;

        $winning = $this->commitTemplateUpdates($writerB, $templateId, $iteration, $context);
        $before = $this->templateSnapshot($templateId);
        $this->assertSame($winning['version'], $before['version'], "Winning writer version mismatch ({$context}).");
        $this->assertGreaterThan($staleVersion, $before['version'], "Second writer must advance the version ({$context}).");

        $this->advanceTime();
        Sanctum::actingAs($writerA);
        $response = $this->putJson(self::TEMPLATES_URL . '/' . $templateId, [
            'name' => $this->generatedName('Stale', $iteration),
            'workflowId' => $this->pick(self::WORKFLOW_IDS),
            'config' => $this->generatedConfig(),
            'version' => $staleVersion,
        ]);

        $this->assertStaleRejection($response->getStatusCode(), $response->json(), $before['version'], $context);

        $data = $response->json('data');
        $this->assertLatestTemplateState($data, $before, $context);
        $this->assertSame($before, $this->templateSnapshot($templateId), "Rejected update mutated the stored template ({$context}).");
        $this->assertSame(1, Template::query()->whereKey($templateId)->count(), "Rejected update changed template existence ({$context}).");

        $covered['template.update'] = true;
    }

    /**
     * Writer A loads a template, writer B commits updates first, A's stale delete is rejected.
     *
     * @param array<string, true> $covered
     */
    private function runTemplateDeleteConflict(int $iteration, array &$covered): void
    {
        $teamId = 390_000 + $iteration;
        [$writerA, $writerB] = $this->writers($teamId);
        $context = $this->context($iteration, "template.delete, team={$teamId}");

        $templateId = $this->createTemplate($writerA, $iteration);
        $staleVersion = (int) Template::query()->findOrFail($templateId)->version;

        $this->commitTemplateUpdates($writerB, $templateId, $iteration, $context);
        $before = $this->templateSnapshot($templateId);
        $this->assertGreaterThan($staleVersion, $before['version'], "Second writer must advance the version ({$context}).");

        $this->advanceTime();
        Sanctum::actingAs($writerA);
        $response = $this->deleteJson(self::TEMPLATES_URL . '/' . $templateId, [
            'version' => $staleVersion,
        ]);

        $this->assertStaleRejection($response->getStatusCode(), $response->json(), $before['version'], $context);

        $data = $response->json('data');
        $this->assertLatestTemplateState($data, $before, $context);
        $this->assertNotTrue($data['deleted'] ?? false, "Rejected delete must not report removal ({$context}).");
        $this->assertNotNull(Template::query()->find($templateId), "Rejected delete removed the template ({$context}).");
        $this->assertSame($before, $this->templateSnapshot($templateId), "Rejected delete mutated the stored template ({$context}).");

        $covered['template.delete'] = true;
    }

    /**
     * Writer A loads brand state, writer B commits updates first, A's stale update is rejected.
     *
     * @param array<string, true> $covered
     */
    private function runBrandUpdateConflict(int $iteration, array &$covered): void
    {
        $teamId = 490_000 + $iteration;
        [$writerA, $writerB] = $this->writers($teamId);
        $context = $this->context($iteration, "brand.update, team={$teamId}");

        // Writer A reads the version it will later submit; an unseeded team reads as 0.
        Sanctum::actingAs($writerA);
        $staleVersion = (int) $this->getJson(self::BRAND_URL)->assertOk()->json('data.version');

        $committedVersion = $staleVersion;
        $commits = mt_rand(1, 2);
        Sanctum::actingAs($writerB);
        for ($commit = 0; $commit < $commits; $commit++) {
            $this->advanceTime();
            $committed = $this->putJson(self::BRAND_URL, [
                'version' => $committedVersion,
                'settings' => $this->generatedBrandSettings($iteration, $commit),
            ])->assertOk()->json('data');
            $this->assertSame(
                $committedVersion + 1,
                (int) $committed['version'],
                "Winning brand writer version mismatch ({$context})."
            );
            $committedVersion = (int) $committed['version'];
        }

        $before = $this->brandSnapshot($teamId);
        $this->assertSame($committedVersion, $before['version'], "Winning brand version mismatch ({$context}).");
        $this->assertGreaterThan($staleVersion, $before['version'], "Second writer must advance the brand version ({$context}).");

        $this->advanceTime();
        Sanctum::actingAs($writerA);
        $response = $this->putJson(self::BRAND_URL, [
            'version' => $staleVersion,
            'settings' => $this->generatedBrandSettings($iteration, 99),
        ]);

        $this->assertStaleRejection($response->getStatusCode(), $response->json(), $before['version'], $context);

        $data = $response->json('data');
        $this->assertSame($teamId, (int) $data['teamId'], "Brand response escaped its team scope ({$context}).");
        $this->assertSame($before['version'], (int) $data['version'], "Brand response must return the committed version ({$context}).");
        $this->assertSame($before['settings'], $data['settings'], "Brand response must return the committed settings ({$context}).");
        $this->assertSame($before['updatedBy'], (int) $data['updatedBy'], "Brand response must return the committed updater ({$context}).");
        $this->assertSame($before, $this->brandSnapshot($teamId), "Rejected update mutated the stored brand state ({$context}).");

        $covered['brand.update'] = true;
    }

    /**
     * A stale write must be refused with a conflict, never applied.
     *
     * @param array<string, mixed>|null $body
     */
    private function assertStaleRejection(int $status, ?array $body, int $latestVersion, string $context): void
    {
        $this->assertSame(409, $status, "Stale write was not rejected with a conflict ({$context}).");
        $this->assertIsArray($body, "Stale rejection must return a body ({$context}).");
        $this->assertFalse($body['success'] ?? true, "Stale rejection must not report success ({$context}).");
        $this->assertSame(
            'stale_version',
            $body['error']['code'] ?? null,
            "Stale rejection must identify the version conflict ({$context})."
        );

        if (array_key_exists('latestVersion', $body['error'] ?? [])) {
            $this->assertSame(
                $latestVersion,
                (int) $body['error']['latestVersion'],
                "Stale rejection must report the latest committed version ({$context})."
            );
        }

        $this->assertArrayHasKey('data', $body, "Stale rejection must return the latest committed state ({$context}).");
        $this->assertSame(
            $latestVersion,
            (int) ($body['data']['version'] ?? -1),
            "Stale rejection must return the latest committed version ({$context})."
        );
    }

    /**
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $before
     */
    private function assertLatestTemplateState(?array $data, array $before, string $context): void
    {
        $this->assertIsArray($data, "Stale rejection must return the latest template state ({$context}).");
        $this->assertSame($before['id'], (string) ($data['id'] ?? ''), "Returned state is another template ({$context}).");
        $this->assertSame($before['name'], (string) ($data['name'] ?? ''), "Returned name is not the committed name ({$context}).");
        $this->assertSame($before['workflowId'], (string) ($data['workflowId'] ?? ''), "Returned workflow is not the committed workflow ({$context}).");
        $this->assertSame($before['config'], $data['config'] ?? null, "Returned config is not the committed config ({$context}).");
        $this->assertSame($before['version'], (int) ($data['version'] ?? -1), "Returned version is not the committed version ({$context}).");
    }

    /**
     * Writer B commits one or two updates, each with the current committed version.
     *
     * @return array{version: int, name: string, workflowId: string, config: array<string, mixed>}
     */
    private function commitTemplateUpdates(User $writer, string $templateId, int $iteration, string $context): array
    {
        Sanctum::actingAs($writer);
        $version = (int) Template::query()->findOrFail($templateId)->version;
        $committed = [];
        $commits = mt_rand(1, 2);

        for ($commit = 0; $commit < $commits; $commit++) {
            $this->advanceTime();
            $payload = [
                'name' => $this->generatedName('Winner', $iteration) . " c{$commit}",
                'workflowId' => $this->pick(self::WORKFLOW_IDS),
                'config' => $this->generatedConfig(),
                'version' => $version,
            ];
            $data = $this->putJson(self::TEMPLATES_URL . '/' . $templateId, $payload)
                ->assertOk()
                ->json('data');

            $this->assertSame($version + 1, (int) $data['version'], "Winning writer version mismatch ({$context}).");
            $version = (int) $data['version'];
            $committed = [
                'version' => $version,
                'name' => $payload['name'],
                'workflowId' => $payload['workflowId'],
                'config' => $payload['config'],
            ];
        }

        return $committed;
    }

    private function createTemplate(User $owner, int $iteration): string
    {
        Sanctum::actingAs($owner);

        return (string) $this->postJson(self::TEMPLATES_URL, [
            'name' => $this->generatedName('Template', $iteration),
            'workflowId' => $this->pick(self::WORKFLOW_IDS),
            'config' => $this->generatedConfig(),
        ])->assertCreated()->json('data.id');
    }

    /** @return array<string, mixed> */
    private function templateSnapshot(string $templateId): array
    {
        $template = Template::query()->findOrFail($templateId);

        return [
            'id' => (string) $template->id,
            'name' => (string) $template->name,
            'workflowId' => (string) $template->workflow_id,
            'config' => $template->config ?? [],
            'version' => (int) $template->version,
            'teamId' => (int) $template->team_id,
            'createdBy' => (int) $template->created_by,
            'updatedAt' => $this->stamp($template->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    private function brandSnapshot(int $teamId): array
    {
        $brand = BrandState::query()->findOrFail($teamId);

        return [
            'teamId' => (int) $brand->team_id,
            'settings' => $brand->settings ?? [],
            'version' => (int) $brand->version,
            'updatedBy' => (int) $brand->updated_by,
            'updatedAt' => $this->stamp($brand->updated_at),
        ];
    }

    private function stamp(?Carbon $value): ?string
    {
        return $value?->toISOString();
    }

    /** @return array{0: User, 1: User} */
    private function writers(int $teamId): array
    {
        return [
            $this->actor($this->pick(self::TEAM_SCOPED_ROLES), $teamId),
            $this->actor($this->pick(self::TEAM_SCOPED_ROLES), $teamId),
        ];
    }

    private function actor(string $role, int $teamId): User
    {
        return User::factory()->create([
            'role' => $role,
            'metadata' => ['team_id' => $teamId],
        ]);
    }

    /**
     * @param array<int, string> $values
     */
    private function pick(array $values): string
    {
        return $values[mt_rand(0, count($values) - 1)];
    }

    private function advanceTime(): void
    {
        $this->travel(mt_rand(1, 3))->seconds();
    }

    private function generatedName(string $prefix, int $iteration): string
    {
        return sprintf('%s %03d-%02d', $prefix, $iteration, mt_rand(0, 99));
    }

    /** @return array<string, mixed> */
    private function generatedConfig(): array
    {
        return [
            'strength' => mt_rand(0, 100),
            'preset' => $this->pick(['warm', 'neutral', 'cool']),
            'include_branding' => mt_rand(0, 1) === 1,
        ];
    }

    /** @return array<string, mixed> */
    private function generatedBrandSettings(int $iteration, int $commit): array
    {
        $settings = [
            'logo' => sprintf('brands/%03d-%d.svg', $iteration, $commit),
            'primary_color' => sprintf('#%06X', mt_rand(0, 0xFFFFFF)),
        ];

        if (mt_rand(0, 1) === 1) {
            $settings['font_family'] = $this->pick(['Inter', 'Roboto', 'Poppins']);
        }
        if (mt_rand(0, 1) === 1) {
            $settings['include_logo'] = mt_rand(0, 1) === 1;
        }

        return $settings;
    }

    private function context(int $iteration, string $detail): string
    {
        return 'seed=' . self::SEED . ", iteration={$iteration}, {$detail}";
    }
}
