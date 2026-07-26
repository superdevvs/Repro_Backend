<?php

namespace Tests\Feature;

use App\Models\AiListingVideoJob;
use App\Models\Project;
use App\Models\Shoot;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature: ai-editing-studio-revamp, Property 17: Search returns only authorized studio record types
 *
 * **Validates: Requirements 6.1, 6.2, 6.3, 6.10, 15.6**
 *
 * A seeded generator creates matching records owned by the actor, a same-team
 * peer, and a cross-team editor. Editors must see only their own records;
 * privileged users must see their team but never another team. Every returned
 * result is shape-checked and resolved through the real deep-link endpoint.
 *
 * The generator runs 20 scope cases (alternating editor/privileged actors) plus
 * 5 workflow-only cases, one per WORKFLOW_QUERIES entry, on every fourth
 * iteration.
 *
 * Leak detection is structural: the returned (recordType, recordId) pairs are
 * compared by identity against the exact authorized set, and restricted record
 * identities are asserted absent per record type. Raw-substring assertions are
 * used only for unique non-numeric text sentinels in titles/contexts, because
 * short numeric ids (e.g. a shoot id of "3") appear incidentally inside
 * authorized UUIDs and timestamps in the serialized payload.
 */
class StudioSearchScopingPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const ITERATIONS = 20;
    private const WORKFLOW_CASE_EVERY = 4;
    private const SEED = 17_06_15;
    private const SEARCH_URL = '/api/studio/search';
    private const RESOLVE_URL = '/api/studio/deep-links/resolve';
    private const ALLOWED_TYPES = ['project', 'shoot', 'template', 'workflow', 'ai_job'];
    private const DATA_TYPES = ['project', 'shoot', 'template', 'ai_job'];
    private const PRIVILEGED_ROLES = ['admin', 'superadmin', 'editing_manager'];
    private const WORKFLOW_QUERIES = ['Photo Enhancement', 'Twilight', 'Video Cleanup', 'Reel Generator', 'Batch AI Jobs'];

    public function test_property_17_search_returns_only_authorized_studio_record_types(): void
    {
        mt_srand(self::SEED);
        $coveredTypes = [];

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $this->runGeneratedScopeCase($iteration, $coveredTypes);

            if ($iteration % self::WORKFLOW_CASE_EVERY === 0) {
                $this->runWorkflowCase($iteration, $coveredTypes);
            }
        }

        $this->assertEqualsCanonicalizing(self::ALLOWED_TYPES, array_keys($coveredTypes));
    }

    /** @param array<string, true> $coveredTypes */
    private function runGeneratedScopeCase(int $iteration, array &$coveredTypes): void
    {
        $teamId = 700_000 + ($iteration * 2);
        $otherTeamId = $teamId + 1;
        $isEditor = $iteration % 2 === 0;
        $role = $isEditor
            ? 'editor'
            : self::PRIVILEGED_ROLES[mt_rand(0, count(self::PRIVILEGED_ROLES) - 1)];
        $needle = sprintf('P17Scope%03dZX', $iteration);
        $context = "seed=" . self::SEED . ", iteration={$iteration}, role={$role}, query={$needle}";

        $actor = $this->actor($role, $teamId);
        $peer = $this->actor('editor', $teamId);
        $outsider = $this->actor('editor', $otherTeamId);

        $ownSentinel = "{$needle}-OWNQQ";
        $peerSentinel = "{$needle}-PEERQQ";
        $crossSentinel = "{$needle}-CROSSQQ";
        $nonStudioSentinel = "{$needle}-NONSTUDIOQQ";

        $own = $this->studioRecords($teamId, $actor, $ownSentinel);
        $sameTeam = $this->studioRecords($teamId, $peer, $peerSentinel);
        $crossTeam = $this->studioRecords($otherTeamId, $outsider, $crossSentinel);
        User::factory()->create(['name' => $nonStudioSentinel]);

        Sanctum::actingAs($actor);
        $response = $this->getJson(self::SEARCH_URL . '?q=' . rawurlencode($needle))->assertOk();
        $response->assertJsonPath('success', true);
        $results = $this->flattenResults($response->json('data'));
        $this->assertNotEmpty($results, "Generated search must return matching records ({$context}).");

        // Identity index of what the server actually returned: type => [ids].
        $actualIdsByType = [];
        foreach ($results as $result) {
            $actualIdsByType[(string) $result['recordType']][] = (string) $result['recordId'];
        }

        // Authorized expectation: editors see only their own records; privileged
        // roles see their own plus the same-team peer, never the other team.
        $expectedIdsByType = [];
        foreach (self::DATA_TYPES as $type) {
            $expectedIdsByType[$type] = $isEditor
                ? [$own[$type]]
                : [$own[$type], $sameTeam[$type]];
        }

        foreach (self::DATA_TYPES as $type) {
            $this->assertEqualsCanonicalizing(
                $expectedIdsByType[$type],
                $actualIdsByType[$type] ?? [],
                "Search scope mismatch for {$type} ({$context})."
            );
        }

        // Restricted records must be absent by identity per record type, never by
        // substring search over the serialized body.
        $restricted = ['cross-team' => $crossTeam];
        if ($isEditor) {
            $restricted['same-team peer'] = $sameTeam;
        }
        foreach ($restricted as $label => $records) {
            foreach ($records as $type => $restrictedId) {
                $this->assertNotContains(
                    (string) $restrictedId,
                    $actualIdsByType[$type] ?? [],
                    "Restricted {$label} {$type} id returned ({$context})."
                );
            }
        }

        // Unique non-numeric text sentinels guard title/context leakage only.
        $resultText = collect($results)
            ->map(fn (array $result): string => (string) $result['title'] . ' ' . (string) $result['context'])
            ->implode("\n");
        $this->assertStringNotContainsString($crossSentinel, $resultText, "Cross-team context leaked ({$context}).");
        $this->assertStringNotContainsString($nonStudioSentinel, $resultText, "Non-Studio record leaked ({$context}).");
        $this->assertStringContainsString($ownSentinel, $resultText, "Own record context missing ({$context}).");
        if ($isEditor) {
            $this->assertStringNotContainsString($peerSentinel, $resultText, "Editor received peer context ({$context}).");
        } else {
            $this->assertStringContainsString($peerSentinel, $resultText, "Team peer context missing ({$context}).");
        }

        foreach ($results as $result) {
            $this->assertResultContractAndResolution($result, $context);
            $coveredTypes[$result['recordType']] = true;
        }
    }

    /** @param array<string, true> $coveredTypes */
    private function runWorkflowCase(int $iteration, array &$coveredTypes): void
    {
        $query = self::WORKFLOW_QUERIES[
            intdiv($iteration, self::WORKFLOW_CASE_EVERY) % count(self::WORKFLOW_QUERIES)
        ];
        $context = "seed=" . self::SEED . ", workflow iteration={$iteration}, query={$query}";
        $response = $this->getJson(self::SEARCH_URL . '?q=' . rawurlencode($query))->assertOk();
        $results = $this->flattenResults($response->json('data'));

        $this->assertNotEmpty($results, "Workflow search must return a result ({$context}).");
        foreach ($results as $result) {
            $this->assertSame('workflow', $result['recordType'], "Workflow-only query returned persisted data ({$context}).");
            $this->assertResultContractAndResolution($result, $context);
            $coveredTypes[$result['recordType']] = true;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     * @return array<int, array<string, mixed>>
     */
    private function flattenResults(array $groups): array
    {
        $results = [];
        foreach ($groups as $group) {
            $this->assertContains($group['recordType'], self::ALLOWED_TYPES);
            $this->assertIsArray($group['results']);
            foreach ($group['results'] as $result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /** @param array<string, mixed> $result */
    private function assertResultContractAndResolution(array $result, string $context): void
    {
        foreach (['recordType', 'recordId', 'title', 'context', 'deepLink'] as $field) {
            $this->assertArrayHasKey($field, $result, "Missing {$field} ({$context}).");
        }

        $this->assertContains($result['recordType'], self::ALLOWED_TYPES, "Unsupported record type ({$context}).");
        $this->assertNotSame('', trim((string) $result['title']), "Empty result title ({$context}).");
        $this->assertNotSame('', trim((string) $result['context']), "Empty result context ({$context}).");
        $this->assertIsArray($result['deepLink'], "Deep link must be structured ({$context}).");
        $this->assertSame($result['recordType'], $result['deepLink']['recordType'] ?? null, "Deep-link type mismatch ({$context}).");
        $this->assertSame($result['recordId'], $result['deepLink']['recordId'] ?? null, "Deep-link id mismatch ({$context}).");
        $this->assertNotSame('', trim((string) ($result['deepLink']['destination'] ?? '')), "Missing destination ({$context}).");

        $resolved = $this->postJson(self::RESOLVE_URL, $result['deepLink'])->assertOk();
        $resolved->assertJsonPath('success', true);
        $resolved->assertJsonPath('data.destination', $result['deepLink']['destination']);
        $resolved->assertJsonPath('data.record.recordType', $result['recordType']);
        $resolved->assertJsonPath('data.record.id', $result['recordId']);
    }

    private function actor(string $role, int $teamId): User
    {
        return User::factory()->create([
            'role' => $role,
            'metadata' => ['team_id' => $teamId],
        ]);
    }

    /** @return array{project: string, shoot: string, template: string, ai_job: string} */
    private function studioRecords(int $teamId, User $owner, string $sentinel): array
    {
        $shoot = Shoot::factory()->create([
            'client_id' => $owner->id,
            'editor_id' => $owner->id,
            'created_by' => $owner->id,
            'address' => "{$sentinel} Avenue",
            'property_slug' => strtolower($sentinel),
        ]);
        $project = Project::query()->create([
            'team_id' => $teamId,
            'created_by' => $owner->id,
            'shoot_id' => $shoot->id,
            'name' => "{$sentinel} Project",
            'address' => $shoot->address,
            'source_type' => 'shoot',
            'workflow_id' => 'listing-video',
            'status' => ['draft', 'processing', 'completed'][mt_rand(0, 2)],
        ]);
        $template = Template::query()->create([
            'team_id' => $teamId,
            'created_by' => $owner->id,
            'name' => "{$sentinel} Template",
            'workflow_id' => 'listing-video',
            'config' => ['target_seconds' => [15, 30, 45][mt_rand(0, 2)]],
        ]);
        $job = AiListingVideoJob::query()->create([
            'project_id' => $project->id,
            'shoot_id' => $shoot->id,
            'user_id' => $owner->id,
            'provider' => 'fal',
            'selected_file_ids' => [1, 2, 3, 4, 5, 6],
            'target_seconds' => 30,
            'status' => [
                AiListingVideoJob::STATUS_QUEUED,
                AiListingVideoJob::STATUS_PROCESSING,
                AiListingVideoJob::STATUS_COMPLETED,
            ][mt_rand(0, 2)],
        ]);

        return [
            'project' => (string) $project->id,
            'shoot' => (string) $shoot->id,
            'template' => (string) $template->id,
            'ai_job' => 'video-' . $job->id,
        ];
    }
}
