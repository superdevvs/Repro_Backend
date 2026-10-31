<?php

namespace Tests\Feature;

use App\Models\ListingStudioGuestTrial;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\ListingStudio\ListingStudioGuestTrialService;
use App\Services\ListingStudio\ListingStudioTrialPipeline;
use App\Services\ListingStudio\ListingStudioVisitorIp;
use App\Services\Studio\StudioProviderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ListingStudioGuestTrialTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/external/listing-studio';

    private const BRIDGE_KEY = 'a-secret-bridge-key-with-at-least-thirty-two-characters';

    private string $session;

    private array $challengeReply = ['success' => true, 'hostname' => 'reprophotos.com', 'action' => 'listing_studio_trial'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = str_repeat('a', 64);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'studio.client_access_enabled' => false,
            'listing-studio-trials.enabled' => true, 'listing-studio-trials.bridge_key' => self::BRIDGE_KEY,
            'listing-studio-trials.services.staging.enabled' => true, 'listing-studio-trials.services.decluttering.enabled' => true,
            'listing-studio-trials.services.twilight.enabled' => true,
            'listing-studio-trials.turnstile_secret' => 'challenge-secret', 'listing-studio-trials.turnstile_site_key' => 'challenge-public-key',
            'listing-studio-trials.turnstile_hosts' => ['reprophotos.com']]);
        $private = Storage::fake('listing_studio_trials', config('filesystems.disks.listing_studio_trials'));
        $private->makeDirectory('');
        $private->setVisibility('', 'private');
        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake(['challenges.cloudflare.com/*' => fn () => Http::response($this->challengeReply)]);
        Queue::fake();
        Mail::fake();
        $this->mock(StudioProviderSettings::class, function ($mock) {
            $mock->shouldReceive('route')->andReturn(['provider' => 'test', 'model' => 'test']);
            $mock->shouldReceive('readiness')->andReturn(['ready' => true]);
        });
        $this->mock(ListingStudioTrialPipeline::class, function ($mock) {
            $mock->shouldReceive('start')->andReturnUsing(function (ListingStudioGuestTrial $trial) {
                $workspace = StudioWorkspace::create([
                    'listing_studio_trial_id' => $trial->id, 'team_id' => null, 'created_by' => null,
                    'name' => 'Trial', 'preset_id' => 'listing-ready', 'media' => [], 'config' => [], 'status' => 'generating',
                ]);
                $trial->update(['workspace_id' => $workspace->id, 'status' => 'processing']);

                return $workspace;
            });
        });
    }

    private function bridge(array $extra = []): static
    {
        return $this->withHeaders(['Accept' => 'application/json', 'X-Studio-Key' => self::BRIDGE_KEY,
            'X-Studio-Session' => $this->session, 'X-Studio-IP' => '203.0.113.10', ...$extra]);
    }

    private function payload(string $service = 'grass', ?UploadedFile $image = null): array
    {
        return ['service' => $service, 'image' => $image ?? UploadedFile::fake()->image('property.jpg', 1600, 1000), 'admissionToken' => str_repeat('e', 64)];
    }

    private function start(string $service = 'grass', ?string $key = null, ?UploadedFile $image = null)
    {
        $key ??= (string) Str::uuid();
        $token = bin2hex(random_bytes(32));
        DB::table('listing_studio_trial_admissions')->insert(['token_hash' => hash('sha256', $token),
            'session_hash' => hash_hmac('sha256', 'session:'.$this->session, (string) config('app.key')),
            'idempotency_key' => $key, 'ip_hash' => ListingStudioVisitorIp::hash('203.0.113.10'),
            'verified_at' => now(), 'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now()]);

        return $this->bridge(['Idempotency-Key' => $key])->post(self::BASE.'/trials', [...$this->payload($service, $image), 'admissionToken' => $token]);
    }

    private function complete(string $id): ListingStudioGuestTrial
    {
        $trial = ListingStudioGuestTrial::findOrFail($id);
        $output = 'studio/workspaces/'.$trial->workspace_id.'/edited.jpg';
        $file = UploadedFile::fake()->image('edited.jpg', 1600, 1000);
        Storage::disk('listing_studio_trials')->put($output, file_get_contents($file->getRealPath()));
        $workspace = $trial->workspace;
        $workspace->update(['status' => 'completed', 'outputs' => [['id' => 'output', 'kind' => 'image', 'status' => 'completed', 'path' => $output]]]);
        app(ListingStudioGuestTrialService::class)->syncWorkspace($workspace);

        return $trial->fresh();
    }

    private function client(array $attributes = []): User
    {
        return User::factory()->create(['role' => 'client', 'email_verified_at' => null, ...$attributes]);
    }

    public function test_dedicated_bridge_credential_and_valid_session_are_required(): void
    {
        $this->getJson(self::BASE.'/capabilities')->assertUnauthorized();
        $this->bridge(['X-Studio-Key' => 'the-booking-key'])->getJson(self::BASE.'/capabilities')->assertUnauthorized();
        $this->bridge(['X-Studio-Session' => 'chosen-user-id'])->getJson(self::BASE.'/capabilities')->assertUnprocessable();
        $this->bridge(['X-Studio-IP' => 'not-an-ip'])->getJson(self::BASE.'/capabilities')->assertOk();
        $this->bridge()->getJson(self::BASE.'/capabilities')->assertOk()->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.limits.globalDaily', 100)->assertJsonPath('data.allowances.grass', true);
    }

    public function test_missing_challenge_configuration_disables_live_submission(): void
    {
        config(['listing-studio-trials.turnstile_secret' => null]);
        $this->bridge()->getJson(self::BASE.'/capabilities')->assertOk()->assertJsonPath('data.enabled', false);
        $this->start()->assertStatus(503)->assertJsonPath('code', 'trial_unavailable');
        $this->assertDatabaseCount('listing_studio_guest_trials', 0);
        Http::assertNothingSent();
    }

    public function test_http_upload_restores_group_write_for_the_separate_preview_worker(): void
    {
        $previous = umask(0022);
        try {
            $response = $this->start('grass')->assertStatus(202);
            $id = $response->json('data.id');
            $disk = Storage::disk('listing_studio_trials');
            clearstatcache();
            $this->assertSame(02770, fileperms($disk->path('trials/'.$id)) & 07777);
            $this->assertSame(0660, fileperms($disk->path('trials/'.$id.'/original-preview.jpg')) & 07777);
            $this->assertSame('completed', $this->complete($id)->status);
            $disk->assertExists('trials/'.$id.'/preview.jpg');
        } finally {
            umask($previous);
        }
    }

    public function test_unvalidated_services_are_unavailable_without_consuming_admission_or_allowance(): void
    {
        foreach (['staging', 'decluttering', 'twilight'] as $service) {
            config(['listing-studio-trials.services.'.$service.'.enabled' => false]);
            $response = $this->bridge()->getJson(self::BASE.'/capabilities')->assertOk();
            $entry = collect($response->json('data.services'))->firstWhere('id', $service);
            $this->assertFalse($entry['ready']);
            $this->assertFalse($entry['available']);
            $this->start($service)->assertStatus(503)->assertJsonPath('code', 'trial_service_unavailable');
            $this->assertDatabaseCount('listing_studio_guest_trials', 0);
            $this->assertSame(0, DB::table('listing_studio_trial_admissions')->whereNotNull('consumed_at')->count());
        }
        $this->start('grass')->assertStatus(202);
        Queue::assertNothingPushed(); // The mocked pipeline proves only the allowed service was accepted.
    }

    public function test_upload_is_private_and_exact_retry_is_idempotent_before_challenge_reuse(): void
    {
        $image = UploadedFile::fake()->image('property.png', 100, 100);
        $key = (string) Str::uuid();
        $id = $this->start('grass', $key, $image)->assertStatus(202)->assertJsonPath('data.status', 'processing')->json('data.id');
        $this->start('grass', $key, $image)->assertStatus(202)->assertJsonPath('data.id', $id);
        $this->start('twilight', $key, $image)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        Http::assertNothingSent();
        $this->assertDatabaseCount('listing_studio_guest_trials', 1);
        $this->assertDatabaseCount('studio_workspaces', 1);
        $this->assertCount(0, Storage::disk('public')->allFiles());
        $trial = ListingStudioGuestTrial::findOrFail($id);
        Storage::disk('listing_studio_trials')->assertExists($trial->source_path);
        $this->bridge()->getJson(self::BASE.'/trials/'.$id)->assertJsonMissing(['source_path' => $trial->source_path]);
    }

    public function test_only_one_active_trial_is_allowed_and_confirmed_failure_releases_service_slot(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $this->start('twilight')->assertConflict()->assertJsonPath('code', 'trial_already_processing');
        $trial = ListingStudioGuestTrial::findOrFail($id);
        $trial->workspace->update(['status' => 'failed', 'operation' => ['trialFailureAmbiguous' => false]]);
        app(ListingStudioGuestTrialService::class)->syncWorkspace($trial->workspace->fresh());
        $this->start('grass')->assertStatus(202);
        $this->assertDatabaseCount('listing_studio_guest_trials', 2);
    }

    public function test_ambiguous_provider_submission_remains_reserved(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $trial = ListingStudioGuestTrial::findOrFail($id);
        $trial->workspace->update(['status' => 'failed', 'operation' => ['trialFailureAmbiguous' => true]]);
        app(ListingStudioGuestTrialService::class)->syncWorkspace($trial->workspace->fresh());
        $this->bridge()->getJson(self::BASE.'/trials/'.$id)->assertJsonPath('data.status', 'needs_attention');
        $this->start()->assertConflict();
        $this->assertDatabaseCount('listing_studio_guest_trials', 1);
    }

    public function test_four_distinct_successes_are_allowed_and_completed_service_cannot_repeat(): void
    {
        foreach (array_keys(ListingStudioGuestTrialService::SERVICES) as $service) {
            $id = $this->start($service)->assertStatus(202)->json('data.id');
            $this->complete($id);
        }
        $this->bridge()->getJson(self::BASE.'/trials')->assertOk()->assertJsonCount(4, 'data.trials')
            ->assertJsonPath('data.allowances.grass', false)->assertJsonPath('data.allowances.staging', false);
        $this->start('grass')->assertConflict()->assertJsonPath('code', 'trial_allowance_used');
        $this->assertDatabaseCount('listing_studio_credit_entries', 0);
        $this->assertDatabaseCount('listing_studio_trial_claims', 0);
    }

    public function test_global_and_ip_limits_include_failed_starts_and_expire_on_rolling_window(): void
    {
        config(['listing-studio-trials.global_daily_limit' => 1]);
        $id = $this->start()->assertStatus(202)->json('data.id');
        ListingStudioGuestTrial::whereKey($id)->update(['status' => 'failed']);
        $this->session = str_repeat('b', 64);
        $this->start()->assertStatus(429)->assertJsonPath('code', 'trial_daily_limit');
        config(['listing-studio-trials.global_daily_limit' => 100, 'listing-studio-trials.ip_daily_limit' => 1]);
        $this->start()->assertStatus(429)->assertJsonPath('code', 'trial_ip_limit');
        $this->travel(25)->hours();
        $this->start()->assertStatus(202);
    }

    public function test_preview_is_reencoded_resized_and_session_isolated_with_no_guest_download(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $trial = $this->complete($id);
        $preview = $this->bridge()->get(self::BASE.'/trials/'.$id.'/preview')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $info = getimagesizefromstring($preview->getContent());
        $this->assertLessThanOrEqual(1024, max($info[0], $info[1]));
        $this->assertNotSame(Storage::disk('listing_studio_trials')->get($trial->output_path), $preview->getContent());
        $this->bridge()->get(self::BASE.'/trials/'.$id.'/original-preview')->assertOk();
        $this->bridge()->get(self::BASE.'/trials/'.$id.'/download')->assertNotFound();
        $this->session = str_repeat('b', 64);
        $this->bridge()->getJson(self::BASE.'/trials/'.$id)->assertNotFound();
        $this->bridge()->get(self::BASE.'/trials/'.$id.'/preview')->assertNotFound();
    }

    public function test_unverified_active_client_claims_session_and_downloads_but_other_account_cannot(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $trial = $this->complete($id);
        $token = $this->bridge()->postJson(self::BASE.'/trials/'.$id.'/claim-token')->assertOk()->json('data.token');
        $this->assertDatabaseHas('listing_studio_guest_claim_tokens', ['token_hash' => hash('sha256', $token)]);
        DB::table('auth_security_rollouts')->updateOrInsert(['name' => 'email-verification'], ['started_at' => now()->subDays(30)]);
        $client = $this->client(['email_verification_required_at' => now()->subDays(30)]);
        $this->actingAs($client, 'sanctum')->postJson('/api/listing-studio/trials/claim', ['token' => $token])->assertOk()
            ->assertJsonPath('data.trials.0.downloadAvailable', true);
        $this->postJson('/api/listing-studio/trials/claim', ['token' => $token])->assertOk();
        $this->getJson('/api/listing-studio/trials')->assertOk()->assertJsonCount(1, 'data.trials');
        $this->get('/api/listing-studio/trials/'.$id.'/download')->assertOk();
        $this->assertSame($client->id, $trial->fresh()->client_id);
        $this->assertNull($trial->workspace->fresh()->created_by);
        $this->assertNull($client->fresh()->email_verified_at);
        $this->getJson('/api/listing-studio/free-edits')->assertForbidden();
        $other = $this->client();
        $this->actingAs($other, 'sanctum')->postJson('/api/listing-studio/trials/claim', ['token' => $token])->assertConflict()->assertJsonPath('code', 'claim_conflict');
        $this->get('/api/listing-studio/trials/'.$id.'/download')->assertNotFound();
        Mail::assertNothingSent();
    }

    public function test_claim_requires_client_account_and_rejects_expired_token(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $this->complete($id);
        $token = $this->bridge()->postJson(self::BASE.'/trials/'.$id.'/claim-token')->json('data.token');
        $this->postJson('/api/listing-studio/trials/claim', ['token' => $token])->assertUnauthorized();
        $this->actingAs($this->client(['role' => 'admin']), 'sanctum')->postJson('/api/listing-studio/trials/claim', ['token' => $token])->assertForbidden();
        $this->travel(31)->minutes();
        $this->actingAs($this->client(), 'sanctum')->postJson('/api/listing-studio/trials/claim', ['token' => $token])->assertStatus(410)->assertJsonPath('code', 'claim_expired');
    }

    public function test_client_allowance_reconciliation_rejects_duplicate_service_across_guest_sessions(): void
    {
        $client = $this->client();
        foreach (['a', 'b'] as $index => $character) {
            $this->session = str_repeat($character, 64);
            $id = $this->start()->assertStatus(202)->json('data.id');
            $this->complete($id);
            $token = $this->bridge()->postJson(self::BASE.'/trials/'.$id.'/claim-token')->json('data.token');
            $response = $this->actingAs($client, 'sanctum')->postJson('/api/listing-studio/trials/claim', ['token' => $token]);
            if ($index === 0) {
                $response->assertOk();
            } else {
                $response->assertConflict()->assertJsonPath('code', 'trial_allowance_used');
            }
        }
        $this->assertSame(1, ListingStudioGuestTrial::where('client_id', $client->id)->count());
    }

    public function test_cleanup_removes_only_unclaimed_media_and_preserves_service_consumption(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $trial = $this->complete($id);
        $this->travel(8)->days();
        $this->artisan('listing-studio:prune-guest-trials')->assertSuccessful();
        $this->assertSame('expired', $trial->fresh()->status);
        Storage::disk('listing_studio_trials')->assertMissing($trial->source_path);
        Storage::disk('listing_studio_trials')->assertMissing($trial->output_path);
        $this->bridge()->getJson(self::BASE.'/capabilities')->assertJsonPath('data.allowances.grass', false);
        $this->bridge()->get(self::BASE.'/trials/'.$id.'/preview')->assertStatus(410);
        $this->assertSame(0, app(ListingStudioGuestTrialService::class)->expireUnclaimed());
    }

    public function test_unsupported_files_and_invalid_options_are_rejected_before_processing(): void
    {
        $this->start('grass', image: UploadedFile::fake()->create('not-image.svg', 10, 'image/svg+xml'))->assertUnprocessable();
        $this->bridge(['Idempotency-Key' => (string) Str::uuid()])->post(self::BASE.'/trials', [...$this->payload('staging'), 'roomType' => 'unknown'])->assertUnprocessable();
        $this->assertDatabaseCount('listing_studio_guest_trials', 0);
        Http::assertNothingSent();
    }

    public function test_old_expired_attempt_does_not_block_fresh_service_claim_or_restore_expired_download(): void
    {
        $oldId = $this->start('grass')->assertStatus(202)->json('data.id');
        $this->complete($oldId);
        $this->travel(8)->days();
        $newId = $this->start('twilight')->assertStatus(202)->json('data.id');
        $this->complete($newId);
        $token = $this->bridge()->postJson(self::BASE.'/trials/'.$newId.'/claim-token')->json('data.token');
        $client = $this->client();
        $this->actingAs($client, 'sanctum')->postJson('/api/listing-studio/trials/claim', ['token' => $token])->assertOk();
        $this->assertSame('expired', ListingStudioGuestTrial::findOrFail($oldId)->status);
        $this->get('/api/listing-studio/trials/'.$oldId.'/download')->assertConflict();
        $this->get('/api/listing-studio/trials/'.$newId.'/download')->assertOk();
        $this->bridge()->getJson(self::BASE.'/capabilities')->assertJsonPath('data.allowances.grass', false);
    }

    public function test_interrupted_start_without_workspace_is_released_without_provider_submission(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $trial = ListingStudioGuestTrial::findOrFail($id);
        $trial->update(['workspace_id' => null, 'status' => 'queued', 'updated_at' => now()->subMinutes(11)]);
        Http::assertNothingSent();
        $this->assertSame(1, app(ListingStudioGuestTrialService::class)->reconcileStale());
        $this->assertSame('failed', $trial->fresh()->status);
        Http::assertNothingSent();
        $this->bridge()->getJson(self::BASE.'/capabilities')->assertJsonPath('data.allowances.grass', true);
    }

    public function test_claim_rechecks_account_status_in_transaction_instead_of_trusting_stale_authenticated_user(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $this->complete($id);
        $token = $this->bridge()->postJson(self::BASE.'/trials/'.$id.'/claim-token')->json('data.token');
        $staleClient = $this->client();
        User::whereKey($staleClient->id)->update(['locked_at' => now()]);
        try {
            app(ListingStudioGuestTrialService::class)->claim($staleClient, $token);
            $this->fail('An account locked after authentication must not claim a trial.');
        } catch (\App\Exceptions\PublicApiException $exception) {
            $this->assertSame('account_ineligible', $exception->errorCode);
            $this->assertSame(403, $exception->httpStatus);
        }
        $this->assertNull(ListingStudioGuestTrial::findOrFail($id)->client_id);
        $this->assertDatabaseHas('listing_studio_guest_claim_tokens', ['token_hash' => hash('sha256', $token), 'claimed_at' => null]);
    }
}
