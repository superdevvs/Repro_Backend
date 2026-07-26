<?php

namespace Tests\Feature;

use App\Http\Controllers\API\StudioController;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature: ai-editing-studio-revamp, Property 41: Out-of-scope requests are rejected or omitted
 *
 * **Validates: Requirements 15.2, 15.3, 15.5, 15.6**
 *
 * A deterministic generator runs 24 cases exercising privileged team scope,
 * editor self-scope, cross-team access, same-team non-owner access, and
 * disallowed roles against real persisted Studio Project records. Restricted
 * sentinel values must never appear in scoped aggregate results or
 * authorization errors.
 *
 * Case count is 24 because role and action selections cycle deterministically
 * (3 privileged roles x 4 unauthorized roles x 5 record actions), which covers
 * every distinct role and action combination pairing within 24 cases; larger
 * counts only repeat already-covered combinations.
 */
class StudioAuthorizationIsolationPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const ITERATIONS = 24;
    private const SEED = 41_15_02;

    private const PRIVILEGED_ROLES = ['admin', 'superadmin', 'editing_manager'];
    private const UNAUTHORIZED_ROLES = ['client', 'photographer', 'sales', 'viewer'];
    private const RECORD_ACTIONS = ['view', 'update', 'retry', 'cancel', 'delete'];

    public function test_property_41_out_of_scope_requests_are_rejected_or_omitted(): void
    {
        mt_srand(self::SEED);
        $probe = app(StudioAuthorizationIsolationProbe::class);

        $exercisedPrivilegedRoles = [];
        $exercisedUnauthorizedRoles = [];
        $exercisedActions = [];

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $teamId = 100_000 + ($iteration * 2);
            $otherTeamId = $teamId + 1;
            $context = "seed=" . self::SEED . ", iteration={$iteration}";

            $privilegedRole = self::PRIVILEGED_ROLES[$iteration % count(self::PRIVILEGED_ROLES)];
            $unauthorizedRole = self::UNAUTHORIZED_ROLES[$iteration % count(self::UNAUTHORIZED_ROLES)];
            $exercisedPrivilegedRoles[$privilegedRole] = true;
            $exercisedUnauthorizedRoles[$unauthorizedRole] = true;

            $privileged = $this->actor($privilegedRole, $teamId);
            $editor = $this->actor('editor', $teamId);
            $peer = $this->actor('editor', $teamId);
            $outsider = $this->actor('editor', $otherTeamId);
            $unauthorized = $this->actor($unauthorizedRole, $teamId);

            $privilegedProject = $this->project($teamId, $privileged, "TEAM-PRIV-{$iteration}-SECRET");
            $editorProject = $this->project($teamId, $editor, "EDITOR-SELF-{$iteration}-VISIBLE");
            $peerProject = $this->project($teamId, $peer, "TEAM-PEER-{$iteration}-SECRET");
            $crossTeamProject = $this->project($otherTeamId, $outsider, "CROSS-TEAM-{$iteration}-SECRET");

            $teamVisible = $probe->scope(Project::query(), $privileged)
                ->pluck('name')
                ->all();
            $this->assertEqualsCanonicalizing(
                [$privilegedProject->name, $editorProject->name, $peerProject->name],
                $teamVisible,
                "Privileged scope must include its complete team only ({$context})."
            );
            $this->assertNotContains(
                $crossTeamProject->name,
                $teamVisible,
                "Privileged aggregate leaked cross-team data ({$context})."
            );

            $editorVisible = $probe->scope(Project::query(), $editor)
                ->pluck('name')
                ->all();
            $this->assertSame(
                [$editorProject->name],
                $editorVisible,
                "Editor aggregate must be self-scoped within its team ({$context})."
            );
            $this->assertNotContains($peerProject->name, $editorVisible, "Editor aggregate leaked peer data ({$context}).");
            $this->assertNotContains($crossTeamProject->name, $editorVisible, "Editor aggregate leaked cross-team data ({$context}).");

            $action = self::RECORD_ACTIONS[$iteration % count(self::RECORD_ACTIONS)];
            $exercisedActions[$action] = true;
            $probe->authorize($privileged, $action, $peerProject);
            $probe->authorize($editor, $action, $editorProject);

            $this->assertDeniedWithoutLeak(
                fn () => $probe->authorize($editor, $action, $peerProject),
                [$peerProject->name, $peerProject->address],
                "same-team non-owner {$action}; {$context}"
            );

            $this->assertDeniedWithoutLeak(
                fn () => $probe->authorize($privileged, $action, $crossTeamProject),
                [$crossTeamProject->name, $crossTeamProject->address],
                "privileged cross-team {$action}; {$context}"
            );
            $this->assertDeniedWithoutLeak(
                fn () => $probe->authorize($editor, $action, $crossTeamProject),
                [$crossTeamProject->name, $crossTeamProject->address],
                "editor cross-team {$action}; {$context}"
            );
            $this->assertDeniedWithoutLeak(
                fn () => $probe->authorize($unauthorized, $action, $editorProject),
                [$editorProject->name, $editorProject->address],
                "unauthorized role {$unauthorized->role} {$action}; {$context}"
            );

            $unchangedName = $peerProject->name;
            $this->assertDeniedWithoutLeak(
                fn () => $probe->authorize($editor, 'update', $peerProject),
                [$peerProject->name, $peerProject->address],
                "mutation guard; {$context}"
            );
            $this->assertSame(
                $unchangedName,
                $peerProject->fresh()->name,
                "Rejected mutation must leave the restricted record unchanged ({$context})."
            );
        }

        // Coverage guards: the reduced case count must still exercise every
        // distinct privileged role, disallowed role, and record action.
        $this->assertEqualsCanonicalizing(
            self::PRIVILEGED_ROLES,
            array_keys($exercisedPrivilegedRoles),
            'Every privileged role must be exercised.'
        );
        $this->assertEqualsCanonicalizing(
            self::UNAUTHORIZED_ROLES,
            array_keys($exercisedUnauthorizedRoles),
            'Every disallowed role must be exercised.'
        );
        $this->assertEqualsCanonicalizing(
            self::RECORD_ACTIONS,
            array_keys($exercisedActions),
            'Every record action must be exercised.'
        );
    }

    private function actor(string $role, int $teamId): User
    {
        return User::factory()->create([
            'role' => $role,
            'metadata' => ['team_id' => $teamId],
        ]);
    }

    private function project(int $teamId, User $owner, string $sentinel): Project
    {
        return Project::query()->create([
            'team_id' => $teamId,
            'created_by' => $owner->id,
            'name' => $sentinel,
            'address' => "{$sentinel}-ADDRESS",
            'source_type' => mt_rand(0, 1) === 0 ? 'shoot' : 'upload',
            'workflow_id' => 'workflow-' . mt_rand(1, 6),
            'status' => 'draft',
        ]);
    }

    /**
     * @param callable(): void $request
     * @param array<int, string> $restrictedValues
     */
    private function assertDeniedWithoutLeak(callable $request, array $restrictedValues, string $context): void
    {
        try {
            $request();
            $this->fail("Out-of-scope request was not rejected ({$context}).");
        } catch (AuthorizationException $exception) {
            $this->assertSame(
                'This action is not authorized.',
                $exception->getMessage(),
                "Authorization response must remain generic ({$context})."
            );

            foreach ($restrictedValues as $restrictedValue) {
                $this->assertStringNotContainsString(
                    $restrictedValue,
                    $exception->getMessage(),
                    "Authorization response leaked restricted data ({$context})."
                );
            }
        }
    }
}

class StudioAuthorizationIsolationProbe extends StudioController
{
    public function scope(Builder $query, Authenticatable $user): Builder
    {
        return $this->scopeStudioQuery($query, $user);
    }

    public function authorize(
        ?Authenticatable $user,
        string $action,
        ?Model $record = null
    ): void {
        $this->authorizeStudioAction($user, $action, $record);
    }
}
