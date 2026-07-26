<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature: ai-editing-studio-revamp, Property 9: Shoot search returns only authorized matches
 *
 * **Validates: Requirements 4.2**
 *
 * A deterministic generator (fixed seed, 120 reproducible cases) builds, for
 * every case, a matching Shoot owned by the actor, a non-matching Shoot owned
 * by the actor, a matching Shoot owned by a same-team peer, and a matching
 * Shoot owned by a cross-team editor. The actor role alternates between
 * `editor` (self-scoped) and a privileged role (team-scoped), and the query
 * rotates through property slug, MLS identifier, address fragment, and the
 * numeric shoot id.
 *
 * The expected result set is derived independently of the endpoint: the
 * authorized Shoot set for the actor's scope intersected with a reference match
 * predicate over the stored identifier/address columns. Scope assertions use
 * structural identity (id sets and per-record field comparison) rather than
 * substring matching, because short numeric ids appear incidentally inside
 * unrelated numbers in a serialized payload. Raw-substring assertions are used
 * only for unique non-numeric text sentinels.
 */
class StudioShootSearchAuthorizedMatchPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const ITERATIONS = 120;
    private const SEED = 9_04_02;
    private const SEARCH_URL = '/api/studio/shoots/search';
    private const PRIVILEGED_ROLES = ['admin', 'superadmin', 'editing_manager'];
    private const QUERY_KINDS = ['slug', 'mls', 'address', 'id'];

    public function test_property_9_shoot_search_returns_only_authorized_matches(): void
    {
        mt_srand(self::SEED);
        $coveredKinds = [];

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $kind = self::QUERY_KINDS[$iteration % count(self::QUERY_KINDS)];
            $this->runGeneratedCase($iteration, $kind);
            $coveredKinds[$kind] = true;
        }

        $this->assertEqualsCanonicalizing(self::QUERY_KINDS, array_keys($coveredKinds));
    }

    private function runGeneratedCase(int $iteration, string $kind): void
    {
        $teamId = 900_000 + ($iteration * 2);
        $otherTeamId = $teamId + 1;
        $isEditor = $iteration % 2 === 0;
        $role = $isEditor
            ? 'editor'
            : self::PRIVILEGED_ROLES[mt_rand(0, count(self::PRIVILEGED_ROLES) - 1)];

        $matchToken = sprintf('P9Match%03dZX', $iteration);
        $missToken = sprintf('P9Miss%03dQW', $iteration);
        $peerToken = "{$matchToken}PEERYY";
        $crossToken = "{$matchToken}CROSSYY";

        $actor = $this->actor($role, $teamId);
        $peer = $this->actor('editor', $teamId);
        $outsider = $this->actor('editor', $otherTeamId);

        $ownMatch = $this->shoot($actor, $matchToken);
        $ownMiss = $this->shoot($actor, $missToken);
        $peerMatch = $this->shoot($peer, $peerToken);
        $crossMatch = $this->shoot($outsider, $crossToken);

        // Authorized scope, derived from the role contract rather than the endpoint.
        $authorized = $isEditor
            ? [$ownMatch, $ownMiss]
            : [$ownMatch, $ownMiss, $peerMatch];
        $restricted = $isEditor
            ? ['same-team peer' => $peerMatch, 'cross-team' => $crossMatch]
            : ['cross-team' => $crossMatch];

        $query = $this->query($kind, $ownMatch, $matchToken);
        $context = sprintf(
            'seed=%d, iteration=%d, role=%s, kind=%s, query=%s',
            self::SEED,
            $iteration,
            $role,
            $kind,
            $query
        );

        $expected = collect($authorized)
            ->filter(fn (Shoot $shoot): bool => $this->matchesQuery($shoot, $query))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $this->assertContains(
            (int) $ownMatch->id,
            $expected,
            "Generator must produce an authorized match ({$context})."
        );

        Sanctum::actingAs($actor);
        $response = $this->getJson(self::SEARCH_URL . '?q=' . rawurlencode($query))->assertOk();
        $response->assertJsonPath('success', true)->assertJsonPath('meta.query', $query);

        $returned = collect($response->json('data'));
        $returnedIds = $returned->pluck('id')->map(fn ($id): int => (int) $id)->all();

        // Soundness + completeness in one structural comparison: every returned
        // shoot is authorized and matching, and no authorized match is dropped.
        $this->assertEqualsCanonicalizing(
            $expected,
            $returnedIds,
            "Shoot search result set mismatch ({$context})."
        );
        $response->assertJsonPath('meta.total', count($expected));

        $byId = collect($authorized)->keyBy(fn (Shoot $shoot): int => (int) $shoot->id);
        foreach ($returned as $result) {
            $shoot = $byId->get((int) $result['id']);
            $this->assertNotNull($shoot, "Returned shoot is outside the authorized scope ({$context}).");
            $this->assertSame($shoot->address, $result['address'], "Address identity mismatch ({$context}).");
            $this->assertSame(
                $shoot->property_slug,
                $result['propertyIdentifier'],
                "Property identifier mismatch ({$context})."
            );
            $this->assertTrue(
                $this->matchesQuery($shoot, $query),
                "Returned shoot does not match the entered query ({$context})."
            );
        }

        foreach ($restricted as $label => $shoot) {
            $this->assertNotContains(
                (int) $shoot->id,
                $returnedIds,
                "Restricted {$label} shoot id returned ({$context})."
            );
        }

        // Unique non-numeric sentinels guard text leakage only.
        $body = $response->getContent();
        $this->assertStringNotContainsString($crossToken, $body, "Cross-team shoot text leaked ({$context}).");
        if ($isEditor) {
            $this->assertStringNotContainsString($peerToken, $body, "Peer-owned shoot text leaked ({$context}).");
        }
        if ($kind !== 'id') {
            $this->assertStringNotContainsString($missToken, $body, "Non-matching shoot text leaked ({$context}).");
        }
    }

    private function query(string $kind, Shoot $shoot, string $token): string
    {
        return match ($kind) {
            'slug' => substr((string) $shoot->property_slug, 0, strlen($token) + 5),
            'mls' => "MLS-{$token}",
            'address' => "{$token} Harbor View",
            'id' => (string) $shoot->id,
        };
    }

    /**
     * Reference match predicate for Requirement 4.2: the shoot matches the
     * entered property identifier (slug, MLS id, or numeric shoot id) or its
     * address fields.
     */
    private function matchesQuery(Shoot $shoot, string $query): bool
    {
        $needle = mb_strtolower($query);
        $haystacks = [
            $shoot->property_slug,
            $shoot->mls_id,
            $shoot->address,
            $shoot->city,
            $shoot->state,
            $shoot->zip,
        ];

        foreach ($haystacks as $value) {
            if ($value !== null && $value !== '' && str_contains(mb_strtolower((string) $value), $needle)) {
                return true;
            }
        }

        return ctype_digit($query) && (int) $shoot->id === (int) $query;
    }

    private function actor(string $role, int $teamId): User
    {
        return User::factory()->create([
            'role' => $role,
            'metadata' => ['team_id' => $teamId],
        ]);
    }

    private function shoot(User $owner, string $token): Shoot
    {
        return Shoot::factory()->create([
            'client_id' => $owner->id,
            'editor_id' => $owner->id,
            'created_by' => $owner->id,
            'address' => "{$token} Harbor View Avenue",
            'property_slug' => "{$token}-harbor-house",
            'mls_id' => "MLS-{$token}",
            'city' => 'Studioville',
            'state' => 'CA',
            'zip' => '90001',
        ]);
    }
}
