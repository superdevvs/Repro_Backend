<?php

namespace Tests\Feature\Auth;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Users\EmailVerificationPilot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SalesRepImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function withToken($token, $type = 'Bearer')
    {
        $this->app['auth']->forgetGuards();

        return parent::withToken($token, $type);
    }

    public function test_rep_can_use_each_assignment_id_alias_and_legacy_creator_assignment(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client']);
        $token = $rep->createToken('rep-browser')->plainTextToken;

        foreach (['accountRepId', 'account_rep_id', 'repId', 'rep_id'] as $key) {
            $client->forceFill(['metadata' => [$key => (string) $rep->id]])->save();
            $this->withToken($token)->withHeader('X-Impersonate-User-Id', (string) $client->id)
                ->getJson('/api/user')->assertOk()->assertJsonPath('id', $client->id)->assertJsonPath('role', 'client');
            $this->withToken($token)->getJson('/api/admin/users')->assertForbidden();
        }

        $client->forceFill(['metadata' => [], 'created_by_id' => $rep->id])->save();
        $this->withToken($token)->getJson('/api/user')->assertOk()->assertJsonPath('id', $client->id);
        $this->withToken($token)->withHeader('X-Impersonate-User-Id', '')
            ->getJson('/api/user')->assertOk()->assertJsonPath('id', $rep->id);
        $this->assertSame(1, $rep->tokens()->count());
    }

    public function test_rep_cannot_impersonate_unassigned_foreign_or_staff_accounts(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $otherRep = User::factory()->create(['role' => 'salesRep']);
        $token = $rep->createToken('rep-browser')->plainTextToken;
        $targets = collect([
            ['role' => 'client'],
            ['role' => 'client', 'metadata' => ['accountRepId' => (string) $otherRep->id]],
            ['role' => 'client', 'created_by_id' => $rep->id, 'metadata' => ['accountRepId' => (string) $otherRep->id]],
            ['role' => 'client', 'metadata' => ['accountRepId' => $otherRep->id, 'account_rep_id' => $rep->id]],
            ['role' => 'client', 'created_by_id' => $rep->id, 'metadata' => ['accountRepId' => null]],
            ['role' => 'client', 'created_by_id' => $rep->id, 'metadata' => ['accountRepId' => '']],
            ['role' => 'client', 'metadata' => ['accountRep' => $rep->name]],
            ['role' => 'client', 'created_by_id' => $rep->id, 'metadata' => ['accountRep' => $otherRep->name]],
            ['role' => 'client', 'metadata' => ['accountRepId' => [$rep->id]]],
            ['role' => 'client', 'metadata' => ['accountRepId' => $rep->id.'.5']],
            ['role' => 'client', 'secondary_roles' => ['admin'], 'metadata' => ['accountRepId' => $rep->id]],
            ...array_map(fn ($role) => ['role' => $role, 'metadata' => ['accountRepId' => $rep->id]],
                ['admin', 'superadmin', 'salesRep', 'photographer', 'editor', 'editing_manager']),
        ])->map(fn ($attributes) => User::factory()->create($attributes));

        // A rep assigned to a shoot does not acquire the whole client account.
        Shoot::factory()->create(['client_id' => $targets->first()->id, 'rep_id' => $rep->id]);

        foreach ($targets as $target) {
            $this->withToken($token)->withHeader('X-Impersonate-User-Id', (string) $target->id)
                ->getJson('/api/user')->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
        }
        foreach (['999999999', 'not-an-id', '1 OR 1=1'] as $id) {
            $this->withToken($token)->withHeader('X-Impersonate-User-Id', $id)
                ->getJson('/api/user')->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
        }
    }

    public function test_reassignment_revokes_access_on_the_next_request(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $otherRep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client', 'created_by_id' => $rep->id, 'metadata' => ['accountRepId' => $rep->id]]);
        $token = $rep->createToken('rep-browser')->plainTextToken;

        $this->withToken($token)->withHeader('X-Impersonate-User-Id', (string) $client->id)
            ->getJson('/api/user')->assertOk()->assertJsonPath('id', $client->id);
        $client->forceFill(['metadata' => ['accountRepId' => $otherRep->id]])->save();
        $this->withToken($token)->getJson('/api/user')->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
        $this->withToken($token)->withHeader('X-Impersonate-User-Id', '')
            ->getJson('/api/user')->assertOk()->assertJsonPath('id', $rep->id);
    }

    public function test_rep_aliases_and_secondary_rep_roles_use_the_same_assignment_boundary(): void
    {
        foreach (['sales_rep', 'sales-rep', 'sales rep', 'rep', 'representative', 'photographer'] as $role) {
            $rep = User::factory()->create(['role' => $role, 'secondary_roles' => $role === 'photographer' ? ['salesRep'] : []]);
            $client = User::factory()->create(['role' => 'client', 'metadata' => ['accountRepId' => $rep->id]]);
            $this->withToken($rep->createToken('rep-browser')->plainTextToken)
                ->withHeader('X-Impersonate-User-Id', (string) $client->id)
                ->getJson('/api/user')->assertOk()->assertJsonPath('id', $client->id);
        }
    }

    public function test_authorized_rep_can_view_an_unverified_client_without_verifying_it(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $this->artisan('auth:start-email-verification-pilot', ['--apply' => true])->assertSuccessful();
        $client = User::factory()->create(['role' => 'client', 'email_verified_at' => null, 'metadata' => ['accountRepId' => $rep->id]]);
        $this->travel(14)->days();
        $token = $rep->createToken('rep-browser')->plainTextToken;

        $this->withToken($token)->withHeader('X-Impersonate-User-Id', (string) $client->id)
            ->getJson('/api/shoots')->assertOk();
        $this->withToken($token)->getJson('/api/profile/security')->assertForbidden();
        $this->assertNull($client->fresh()->email_verified_at);
        $this->assertTrue(app(EmailVerificationPilot::class)->status($client->fresh())['required']);

        $this->withToken($client->createToken('client-browser')->plainTextToken)->withHeader('X-Impersonate-User-Id', '')
            ->getJson('/api/profile/activity')->assertForbidden()->assertJsonPath('code', 'email_verification_required');
    }

    public function test_original_rep_verification_and_both_account_active_gates_remain_enforced(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $this->artisan('auth:start-email-verification-pilot', ['--apply' => true])->assertSuccessful();
        $unverifiedRep = User::factory()->create(['role' => 'salesRep', 'email_verified_at' => null]);
        $client->forceFill(['metadata' => ['accountRepId' => $unverifiedRep->id]])->save();
        $this->travel(14)->days();
        $this->withToken($unverifiedRep->createToken('rep-browser')->plainTextToken)
            ->withHeader('X-Impersonate-User-Id', (string) $client->id)
            ->getJson('/api/shoots')->assertForbidden()->assertJsonPath('code', 'email_verification_required');

        $inactiveRep = User::factory()->create(['role' => 'salesRep', 'account_status' => 'inactive']);
        $client->forceFill(['metadata' => ['accountRepId' => $inactiveRep->id]])->save();
        $this->withToken($inactiveRep->createToken('inactive-rep')->plainTextToken)
            ->getJson('/api/user')->assertUnauthorized();
        $this->assertSame(0, $inactiveRep->tokens()->count());

        $rep = User::factory()->create(['role' => 'salesRep']);
        $client->forceFill(['metadata' => ['accountRepId' => $rep->id], 'account_status' => 'inactive'])->save();
        $this->withToken($rep->createToken('active-rep')->plainTextToken)->getJson('/api/user')->assertUnauthorized();
        $this->assertSame(1, $rep->tokens()->count());
        $client->forceFill(['account_status' => 'active', 'locked_at' => now()])->save();
        $this->withToken($rep->createToken('locked-target')->plainTextToken)->getJson('/api/user')->assertUnauthorized();
        $client->delete();
        $this->withToken($rep->createToken('deleted-target')->plainTextToken)->getJson('/api/user')
            ->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
    }

    public function test_directory_search_and_details_expose_only_the_viewers_authorized_targets(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $assigned = User::factory()->create(['role' => 'client', 'metadata' => ['accountRepId' => $rep->id]]);
        $unassigned = User::factory()->create(['role' => 'client']);
        $inactive = User::factory()->create(['role' => 'client', 'account_status' => 'inactive', 'metadata' => ['accountRepId' => $rep->id]]);
        $token = $rep->createToken('rep-browser')->plainTextToken;

        foreach (['/api/admin/users', '/api/admin/users?light=1', '/api/admin/users/search'] as $path) {
            $users = collect($this->withToken($token)->getJson($path)->assertOk()->json('users'));
            $this->assertTrue($users->firstWhere('id', $assigned->id)['can_impersonate']);
            $this->assertFalse($users->firstWhere('id', $unassigned->id)['can_impersonate']);
            $this->assertFalse($users->firstWhere('id', $inactive->id)['can_impersonate']);
        }
        $this->withToken($token)->getJson('/api/admin/users/'.$assigned->id)->assertOk()->assertJsonPath('user.can_impersonate', true);
        $this->withToken($token)->getJson('/api/admin/users/'.$unassigned->id)->assertOk()->assertJsonPath('user.can_impersonate', false);
    }

    public function test_rep_cannot_self_assign_or_clear_another_reps_assignment_through_account_editing(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $otherRep = User::factory()->create(['role' => 'salesRep']);
        $original = ['accountRepId' => (string) $otherRep->id, 'account_rep_id' => $otherRep->id, 'accountRep' => $otherRep->name];
        $client = User::factory()->create(['role' => 'client', 'created_by_id' => $otherRep->id, 'metadata' => $original]);
        $token = $rep->createToken('rep-browser')->plainTextToken;

        foreach ([['accountRepId' => (string) $rep->id, 'rep_id' => $rep->id, 'accountRep' => $rep->name], []] as $metadata) {
            $this->withToken($token)->putJson('/api/admin/users/'.$client->id, [
                'metadata' => $metadata + ['preferences' => ['notificationEmail' => false]],
                'created_by_id' => $rep->id,
            ])->assertOk()->assertJsonPath('user.can_impersonate', false);
            $this->assertSame($otherRep->id, (int) $client->fresh()->created_by_id);
            foreach ($original as $key => $value) {
                $this->assertSame($value, $client->fresh()->metadata[$key]);
            }
            $this->assertArrayNotHasKey('rep_id', $client->fresh()->metadata);
        }
        $unassigned = User::factory()->create(['role' => 'client']);
        $this->withToken($token)->putJson('/api/admin/users/'.$unassigned->id, ['metadata' => ['account_rep_id' => $rep->id]])
            ->assertOk()->assertJsonPath('user.can_impersonate', false);
        $this->assertArrayNotHasKey('account_rep_id', $unassigned->fresh()->metadata);
        $this->withToken($token)->withHeader('X-Impersonate-User-Id', (string) $client->id)
            ->getJson('/api/user')->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
    }

    public function test_admins_keep_assignment_controls_even_with_a_secondary_rep_role(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        foreach ([[], ['salesRep']] as $secondaryRoles) {
            $admin = User::factory()->create(['role' => 'admin', 'secondary_roles' => $secondaryRoles]);
            $client = User::factory()->create(['role' => 'client']);
            $this->withToken($admin->createToken('admin-browser')->plainTextToken)
                ->putJson('/api/admin/users/'.$client->id, ['metadata' => ['accountRepId' => (string) $rep->id]])
                ->assertOk();
            $this->assertSame((string) $rep->id, $client->fresh()->metadata['accountRepId']);
        }
    }
}
