<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InjectsDatabaseWriteFailures;
use Tests\TestCase;

class BrokerageLogoTest extends TestCase
{
    use RefreshDatabase;
    use InjectsDatabaseWriteFailures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        Queue::fake();
        Notification::fake();
        Mail::fake();
    }

    public function test_client_can_upload_replace_reload_and_remove_their_logo_without_changing_other_branding(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        DB::table('user_branding')->insert([
            'user_id' => $client->id, 'logo' => '/previous.png', 'about' => 'Keep this biography',
            'primary_color' => '#123456', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($client);

        $uploaded = $this->postJson($this->url($client), ['logo' => $this->logo()])->assertOk();
        $firstUrl = $uploaded->json('data.logo');
        $this->assertStringContainsString('/storage/branding/brokerage/'.$client->id.'/', $firstUrl);
        $uploaded->assertJsonPath('data.can_manage', true);
        $this->assertCount(1, Storage::disk('public')->allFiles());
        $this->getJson($this->url($client))->assertOk()->assertJsonPath('data.logo', $firstUrl);
        $this->getJson("/api/users/{$client->id}/branding")->assertOk()->assertJsonPath('data.branding.logo', $firstUrl);

        $replaced = $this->postJson($this->url($client), ['logo' => $this->logo('replacement.jpg')])->assertOk();
        $this->assertNotSame($firstUrl, $replaced->json('data.logo'));
        $this->deleteJson($this->url($client))->assertOk()->assertJsonPath('data.logo', null);
        $this->deleteJson($this->url($client))->assertOk()->assertJsonPath('data.logo', null);
        $this->getJson($this->url($client))->assertOk()->assertJsonPath('data.logo', null);
        $this->assertDatabaseHas('user_branding', ['user_id' => $client->id, 'logo' => null, 'about' => 'Keep this biography', 'primary_color' => '#123456']);
        // Previously shared logo URLs are retained; deleting a reference must not
        // break another branding setting or a page already using that asset.
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public static function staffRoles(): array
    {
        return [
            'super admin' => ['superadmin', true],
            'admin' => ['admin', true],
            'assigned rep' => ['salesRep', true],
            'editing manager' => ['editing_manager', false],
            'photographer' => ['photographer', false],
            'editor' => ['editor', false],
            'another client' => ['client', false],
        ];
    }

    #[DataProvider('staffRoles')]
    public function test_only_approved_roles_can_upload_or_remove_a_client_logo(string $role, bool $allowed): void
    {
        $actor = User::factory()->create(['role' => $role]);
        $client = User::factory()->create(['role' => 'client', 'metadata' => ['accountRepId' => $actor->id]]);
        $this->actingAs($actor);
        $upload = $this->postJson($this->url($client), ['logo' => $this->logo()]);
        $remove = $this->deleteJson($this->url($client));

        if ($allowed) {
            $upload->assertOk();
            $remove->assertOk();
        } else {
            $upload->assertForbidden();
            $remove->assertForbidden();
            $this->assertDatabaseMissing('user_branding', ['user_id' => $client->id]);
            $this->assertCount(0, Storage::disk('public')->allFiles());
        }
    }

    public function test_unassigned_reps_cannot_write_and_reassignment_revokes_the_previous_rep(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $other = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client', 'metadata' => ['accountRepId' => $rep->id], 'created_by_id' => $rep->id]);
        $this->actingAs($other)->getJson($this->url($client))->assertOk()->assertJsonPath('data.can_manage', false);
        $this->postJson($this->url($client), ['logo' => $this->logo()])->assertForbidden();
        $this->actingAs($rep)->postJson($this->url($client), ['logo' => $this->logo()])->assertOk();

        $client->forceFill(['metadata' => ['accountRepId' => $other->id]])->saveQuietly();
        $this->getJson($this->url($client))->assertOk()->assertJsonPath('data.can_manage', false);
        $this->postJson($this->url($client), ['logo' => $this->logo()])->assertForbidden();
        $this->deleteJson($this->url($client))->assertForbidden();
        $this->actingAs($other)->deleteJson($this->url($client))->assertOk();
    }

    public static function repKeys(): array
    {
        return array_map(fn ($key) => [$key], ['accountRepId', 'account_rep_id', 'repId', 'rep_id']);
    }

    #[DataProvider('repKeys')]
    public function test_existing_account_rep_metadata_is_respected(string $key): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client', 'metadata' => [$key => (string) $rep->id]]);
        $this->actingAs($rep)->getJson($this->url($client))->assertOk()->assertJsonPath('data.can_manage', true);
    }

    public function test_legacy_rep_creator_is_supported_but_a_shoot_assignment_alone_does_not_grant_branding_control(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $legacyClient = User::factory()->create(['role' => 'client', 'created_by_id' => $rep->id]);
        $unassigned = User::factory()->create(['role' => 'client', 'created_by_id' => null]);
        Shoot::factory()->create(['client_id' => $unassigned->id, 'rep_id' => $rep->id]);
        $this->actingAs($rep)->getJson($this->url($legacyClient))->assertOk()->assertJsonPath('data.can_manage', true);
        $this->getJson($this->url($unassigned))->assertOk()->assertJsonPath('data.can_manage', false);
    }

    public function test_effective_client_permissions_are_enforced_and_the_old_branding_endpoint_cannot_bypass_them(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        foreach (['admin', 'salesRep'] as $role) {
            $actor = User::factory()->create(['role' => $role, 'permission_overrides' => ['deny' => ['clients-update'], 'allow' => []]]);
            $client->forceFill(['metadata' => ['accountRepId' => $actor->id]])->saveQuietly();
            $this->actingAs($actor)->getJson($this->url($client))->assertOk()->assertJsonPath('data.can_manage', false);
            $this->postJson($this->url($client), ['logo' => $this->logo()])->assertForbidden();
            $this->deleteJson($this->url($client))->assertForbidden();
            $this->putJson("/api/users/{$client->id}/branding", ['branding' => ['logo' => '/bypass.png']])->assertForbidden();
        }
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $this->actingAs($manager)->getJson($this->url($client))->assertOk()->assertJsonPath('data.can_manage', false);
        $this->putJson("/api/users/{$client->id}/branding", ['branding' => ['logo' => null]])->assertForbidden();
    }

    public function test_other_clients_and_unauthorized_roles_cannot_read_private_profile_branding(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        foreach (['client', 'photographer', 'editor'] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $this->actingAs($actor)->getJson($this->url($client))->assertForbidden();
            $this->getJson("/api/users/{$client->id}/branding")->assertForbidden();
        }
        $restricted = User::factory()->create(['role' => 'admin', 'permission_overrides' => ['deny' => ['clients-view'], 'allow' => []]]);
        $this->actingAs($restricted)->getJson($this->url($client))->assertForbidden();
    }

    public function test_non_client_accounts_do_not_get_brokerage_logo_controls(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson($this->url($admin))->assertForbidden();
        $this->postJson($this->url($admin), ['logo' => $this->logo()])->assertForbidden();
        $this->deleteJson($this->url($admin))->assertForbidden();
    }

    public function test_upload_requires_authentication_and_rejects_non_images_svg_and_oversized_files(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $this->getJson($this->url($client))->assertUnauthorized();
        $this->postJson($this->url($client), ['logo' => $this->logo()])->assertUnauthorized();
        $this->deleteJson($this->url($client))->assertUnauthorized();
        $this->actingAs($client);
        foreach ([
            UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
            $this->logo()->size(5121),
            UploadedFile::fake()->createWithContent('pretend.png', 'not an image'),
        ] as $file) {
            $this->postJson($this->url($client), ['logo' => $file])->assertUnprocessable()->assertJsonValidationErrors('logo');
        }
        $this->assertDatabaseMissing('user_branding', ['user_id' => $client->id]);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_a_database_failure_keeps_the_previous_logo_and_cleans_up_the_new_upload(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        DB::table('user_branding')->insert(['user_id' => $client->id, 'logo' => '/previous.png']);
        $restore = $this->failDatabaseUpdatesTo('user_branding');
        try {
            $this->actingAs($client)->postJson($this->url($client), ['logo' => $this->logo()])->assertServerError();
        } finally {
            $restore();
        }
        $this->assertDatabaseHas('user_branding', ['user_id' => $client->id, 'logo' => '/previous.png']);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_saved_logo_reaches_branded_tours_and_is_absent_from_both_mls_variants(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $response = $this->actingAs($client)->postJson($this->url($client), ['logo' => $this->logo()])->assertOk();
        $logo = $response->json('data.logo');
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id, 'status' => 'delivered', 'workflow_status' => 'delivered',
            'payment_status' => 'paid', 'bypass_paywall' => true,
        ]);
        // Public tour routes are also exercised without an authenticated viewer.
        auth()->forgetGuards();
        $this->getJson("/api/public/shoots/{$shoot->id}/branded")->assertOk()->assertJsonPath('branding.logo', $logo);
        foreach (['mls', 'g-mls'] as $type) {
            $this->getJson("/api/public/shoots/{$shoot->id}/{$type}")->assertOk()->assertJsonMissingPath('branding');
        }
        $this->actingAs($client)->deleteJson($this->url($client))->assertOk();
        $this->getJson("/api/public/shoots/{$shoot->id}/branded")->assertOk()->assertJsonPath('branding.logo', null);
    }

    private function url(User $user): string
    {
        return "/api/users/{$user->id}/brokerage-logo";
    }

    private function logo(string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 600, 180);
    }
}
