<?php

namespace Tests\Feature;

use App\Models\ListingStudioGuestTrial;
use App\Models\StudioWorkspace;
use App\Services\ListingStudio\ListingStudioTrialAdmission;
use App\Services\ListingStudio\ListingStudioTrialPipeline;
use App\Services\ListingStudio\ListingStudioVisitorIp;
use App\Services\Studio\StudioProviderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ListingStudioTrialAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private const BRIDGE = '/api/external/listing-studio';

    private const VERIFY = '/api/listing-studio/trial-admissions/verify';

    private const KEY = 'a-test-bridge-key-of-more-than-thirty-two-characters';

    private const ORIGIN = 'https://reprophotos.com';

    private const IP = '203.0.113.44';

    private string $session;

    private array $reply = ['success' => true, 'hostname' => 'reprophotos.com', 'action' => 'listing_studio_trial'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = str_repeat('a', 64);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'listing-studio-trials.enabled' => true, 'listing-studio-trials.bridge_key' => self::KEY,
            'listing-studio-trials.services.staging.enabled' => true, 'listing-studio-trials.services.decluttering.enabled' => true,
            'listing-studio-trials.services.twilight.enabled' => true,
            'listing-studio-trials.turnstile_secret' => 'test-secret', 'listing-studio-trials.turnstile_site_key' => 'test-site-key',
            'listing-studio-trials.turnstile_hosts' => ['reprophotos.com', 'www.reprophotos.com']]);
        Storage::fake('listing_studio_trials');
        Storage::fake('public');
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['challenges.cloudflare.com/*' => fn () => Http::response($this->reply)]);
        $this->mock(StudioProviderSettings::class, function ($mock) {
            $mock->shouldReceive('route')->andReturn(['provider' => 'test', 'model' => 'test']);
            $mock->shouldReceive('readiness')->andReturn(['ready' => true]);
        });
        $this->mock(ListingStudioTrialPipeline::class, function ($mock) {
            $mock->shouldReceive('start')->andReturnUsing(function (ListingStudioGuestTrial $trial) {
                $workspace = StudioWorkspace::create(['listing_studio_trial_id' => $trial->id, 'team_id' => null, 'created_by' => null,
                    'name' => 'Trial', 'preset_id' => 'green-grass', 'media' => [], 'config' => [], 'status' => 'generating']);
                $trial->update(['workspace_id' => $workspace->id, 'status' => 'processing']);

                return $workspace;
            });
        });
    }

    private function bridgeHeaders(string $key): array
    {
        return ['X-Studio-Key' => self::KEY, 'X-Studio-Session' => $this->session, 'Idempotency-Key' => $key];
    }

    private function mint(string $key): string
    {
        $response = $this->postJson(self::BRIDGE.'/admissions', [], $this->bridgeHeaders($key))->assertOk();
        $response->assertJsonPath('data.verifyUrl', 'https://reprodashboard.com'.self::VERIFY);

        return $response->json('data.token');
    }

    private function verify(string $token, array $headers = [], string $peer = self::IP, string $challenge = 'test-challenge')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $peer])->postJson(self::VERIFY,
            ['token' => $token, 'challengeToken' => $challenge], ['Origin' => self::ORIGIN, ...$headers]);
    }

    private function upload(string $key, string $token, string $service = 'grass', ?UploadedFile $image = null, array $headers = [])
    {
        return $this->post(self::BRIDGE.'/trials', ['service' => $service, 'admissionToken' => $token,
            'image' => $image ?? UploadedFile::fake()->image('photo.jpg', 800, 600)],
            ['Accept' => 'application/json', ...$this->bridgeHeaders($key), ...$headers]);
    }

    private function row(string $token): object
    {
        return DB::table(ListingStudioTrialAdmission::TABLE)->where('token_hash', hash('sha256', $token))->first();
    }

    public function test_direct_browser_verification_admits_private_upload_without_exposing_bridge_key_or_trusting_bff_ip(): void
    {
        $key = (string) Str::uuid();
        $token = $this->mint($key);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token);
        $this->assertNull($this->row($token)->verified_at);
        $this->verify($token)->assertOk()->assertJsonPath('data.verified', true)->assertHeader('Access-Control-Allow-Origin', self::ORIGIN)
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
        Http::assertSent(fn ($request) => $request['remoteip'] === self::IP && Str::isUuid($request['idempotency_key']));
        $trialId = $this->upload($key, $token, headers: ['X-Studio-IP' => '198.51.100.99', 'X-Forwarded-For' => '198.51.100.2'])
            ->assertStatus(202)->json('data.id');
        $this->assertDatabaseHas('listing_studio_guest_trials', ['id' => $trialId, 'ip_hash' => ListingStudioVisitorIp::hash(self::IP)]);
        $this->assertSame($trialId, $this->row($token)->trial_id);
        $this->assertNotNull($this->row($token)->consumed_at);
        $this->assertStringNotContainsString($token, json_encode((array) $this->row($token)));
        $this->assertStringNotContainsString(self::IP, json_encode((array) $this->row($token)));
        Http::assertSentCount(1);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_only_actual_cf_peer_can_supply_connecting_ip_and_forwarding_chains_are_ignored(): void
    {
        $resolver = app(ListingStudioVisitorIp::class);
        $direct = Request::create(self::VERIFY, 'POST', server: ['REMOTE_ADDR' => self::IP,
            'HTTP_CF_CONNECTING_IP' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.8', 'HTTP_X_REAL_IP' => '198.51.100.7']);
        $this->assertSame(self::IP, $resolver->resolve($direct));
        $cloudflare = Request::create(self::VERIFY, 'POST', server: ['REMOTE_ADDR' => '173.245.48.12',
            'HTTP_CF_CONNECTING_IP' => '2001:0db8:0000:0000:0000:0000:0000:0001', 'HTTP_X_FORWARDED_FOR' => '198.51.100.8']);
        $this->assertSame('2001:db8::1', $resolver->resolve($cloudflare));
        $token = $this->mint((string) Str::uuid());
        $this->verify($token, ['CF-Connecting-IP' => '198.51.100.44', 'X-Forwarded-For' => self::IP], '173.245.48.12')->assertOk();
        Http::assertSent(fn ($request) => $request['remoteip'] === '198.51.100.44');
    }

    public function test_worker_subrequests_missing_cf_identity_and_shared_worker_identity_fail_closed(): void
    {
        $token = $this->mint((string) Str::uuid());
        foreach ([[], ['CF-Connecting-IP' => 'not-an-ip'], ['CF-Connecting-IP' => self::IP, 'CF-Worker' => 'lovable.app'],
            ['CF-Connecting-IP' => self::IP, 'CF-Worker' => ''], ['CF-Connecting-IP' => '2a06:98c0:3600:0000:0000:0000:0000:0103']] as $headers) {
            $this->verify($token, $headers, '173.245.48.12')->assertForbidden()->assertJsonPath('code', 'trial_ip_unavailable');
        }
        $this->assertNull($this->row($token)->verified_at);
        Http::assertNothingSent();
    }

    public function test_mint_requires_bridge_auth_and_upload_requires_verified_matching_session_and_idempotency(): void
    {
        $this->postJson(self::BRIDGE.'/admissions')->assertUnauthorized();
        $key = (string) Str::uuid();
        $token = $this->mint($key);
        $this->upload($key, $token)->assertUnprocessable()->assertJsonPath('code', 'trial_admission_invalid');
        $this->verify($token)->assertOk();
        $this->session = str_repeat('b', 64);
        $this->upload($key, $token)->assertUnprocessable();
        $this->session = str_repeat('a', 64);
        $this->upload((string) Str::uuid(), $token)->assertUnprocessable();
        $this->assertNull($this->row($token)->consumed_at);
        $this->assertDatabaseCount('listing_studio_guest_trials', 0);
        $this->upload($key, $token)->assertStatus(202);
        $this->assertDatabaseCount('listing_studio_guest_trials', 1);
    }

    public function test_challenge_failure_wrong_action_wrong_host_or_origin_mismatch_never_reserve_a_service(): void
    {
        $token = $this->mint((string) Str::uuid());
        foreach ([['success' => false], ['success' => true, 'hostname' => 'attacker.test', 'action' => 'listing_studio_trial'],
            ['success' => true, 'hostname' => 'reprophotos.com', 'action' => 'other'],
            ['success' => true, 'hostname' => 'www.reprophotos.com', 'action' => 'listing_studio_trial']] as $reply) {
            $this->reply = $reply;
            $this->verify($token)->assertUnprocessable()->assertJsonPath('code', 'trial_challenge_failed');
        }
        $this->assertNull($this->row($token)->verified_at);
        $this->assertNull($this->row($token)->consumed_at);
        $this->assertDatabaseCount('listing_studio_guest_trials', 0);
        Queue::assertNothingPushed();
    }

    public function test_verified_admission_replay_is_same_ip_only_and_does_not_reuse_turnstile(): void
    {
        $token = $this->mint((string) Str::uuid());
        $this->verify($token)->assertOk();
        $this->verify($token)->assertOk();
        $this->verify($token, peer: '198.51.100.66')->assertUnprocessable();
        Http::assertSentCount(1);
    }

    public function test_lost_challenge_validation_response_reuses_same_cloudflare_idempotency_key(): void
    {
        $token = $this->mint((string) Str::uuid());
        $attempts = [];
        Http::fake(['challenges.cloudflare.com/*' => function ($request) use (&$attempts) {
            $attempts[] = $request['idempotency_key'];
            if (count($attempts) === 1) {
                throw new \Illuminate\Http\Client\ConnectionException('lost response');
            }

            return Http::response($this->reply);
        }]);
        $this->verify($token)->assertStatus(503)->assertJsonPath('code', 'trial_challenge_unavailable');
        $this->verify($token)->assertOk();
        $this->assertCount(2, $attempts);
        $this->assertSame($attempts[0], $attempts[1]);
    }

    public function test_late_response_from_superseded_challenge_cannot_verify_the_new_attempt(): void
    {
        $token = $this->mint((string) Str::uuid());
        Http::fake(['challenges.cloudflare.com/*' => function () use ($token) {
            DB::table(ListingStudioTrialAdmission::TABLE)->where('token_hash', hash('sha256', $token))->update(['verification_id' => (string) Str::uuid()]);

            return Http::response($this->reply);
        }]);
        $this->verify($token)->assertUnprocessable();
        $this->assertNull($this->row($token)->verified_at);
    }

    public function test_expiry_blocks_new_start_but_exact_existing_retry_survives_expiry_consumption_and_pause(): void
    {
        $key = (string) Str::uuid();
        $token = $this->mint($key);
        $this->verify($token)->assertOk();
        $image = UploadedFile::fake()->image('photo.jpg', 800, 600);
        $id = $this->upload($key, $token, image: $image)->assertStatus(202)->json('data.id');
        $otherKey = (string) Str::uuid();
        $other = $this->mint($otherKey);
        $this->verify($other)->assertOk();
        $this->travel(301)->seconds();
        $this->upload($otherKey, $other)->assertStatus(410)->assertJsonPath('code', 'trial_admission_expired');
        $this->verify($other)->assertStatus(410);
        config(['listing-studio-trials.enabled' => false]);
        $this->upload($key, $token, image: $image)->assertStatus(202)->assertJsonPath('data.id', $id);
        $this->upload($key, $token, 'twilight', $image)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->assertDatabaseCount('listing_studio_guest_trials', 1);
        Http::assertSentCount(2);
    }

    public function test_admission_consumption_rolls_back_with_quota_or_active_job_rejection(): void
    {
        $key = (string) Str::uuid();
        $token = $this->mint($key);
        $this->verify($token)->assertOk();
        config(['listing-studio-trials.global_daily_limit' => 0]);
        $this->upload($key, $token)->assertStatus(429)->assertJsonPath('code', 'trial_daily_limit');
        $this->assertNull($this->row($token)->consumed_at);
        config(['listing-studio-trials.global_daily_limit' => 100]);
        $id = $this->upload($key, $token)->assertStatus(202)->json('data.id');
        $secondKey = (string) Str::uuid();
        $second = $this->mint($secondKey);
        $this->verify($second)->assertOk();
        $this->upload($secondKey, $second, 'twilight')->assertConflict()->assertJsonPath('code', 'trial_already_processing');
        $this->assertNull($this->row($second)->consumed_at);
        ListingStudioGuestTrial::whereKey($id)->update(['status' => 'failed']);
        config(['listing-studio-trials.ip_daily_limit' => 1]);
        $this->upload($secondKey, $second, 'twilight')->assertStatus(429)->assertJsonPath('code', 'trial_ip_limit');
        $this->assertNull($this->row($second)->consumed_at);
        $this->assertDatabaseCount('listing_studio_guest_trials', 1);
    }

    public function test_pause_blocks_mint_verify_and_new_upload_without_consumption(): void
    {
        $key = (string) Str::uuid();
        $token = $this->mint($key);
        $this->verify($token)->assertOk();
        config(['listing-studio-trials.enabled' => false]);
        $this->postJson(self::BRIDGE.'/admissions', [], $this->bridgeHeaders((string) Str::uuid()))->assertStatus(503);
        $this->verify($token)->assertStatus(503);
        $this->upload($key, $token)->assertStatus(503);
        $this->assertNull($this->row($token)->consumed_at);
        $this->assertDatabaseCount('listing_studio_guest_trials', 0);
        Http::assertSentCount(1);
    }

    public function test_unused_nonce_limits_are_global_and_per_session_and_expire(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->mint((string) Str::uuid());
        }
        $this->postJson(self::BRIDGE.'/admissions', [], $this->bridgeHeaders((string) Str::uuid()))->assertStatus(429);
        config(['listing-studio-trials.admission_global_limit' => 3]);
        $this->session = str_repeat('b', 64);
        $this->postJson(self::BRIDGE.'/admissions', [], $this->bridgeHeaders((string) Str::uuid()))->assertStatus(429);
        $this->travel(301)->seconds();
        $this->mint((string) Str::uuid());
        $this->assertSame(0, app(ListingStudioTrialAdmission::class)->prune(100));
        $this->assertDatabaseCount(ListingStudioTrialAdmission::TABLE, 1);
        Http::assertNothingSent();
    }

    public function test_mint_prunes_more_expired_rows_than_it_adds_in_a_bounded_batch(): void
    {
        for ($i = 0; $i < 100; $i++) {
            DB::table(ListingStudioTrialAdmission::TABLE)->insert(['token_hash' => hash('sha256', 'expired-'.$i),
                'session_hash' => str_repeat('f', 64), 'idempotency_key' => (string) Str::uuid(), 'expires_at' => now()->subMinute(),
                'created_at' => now()->subMinutes(6), 'updated_at' => now()->subMinutes(6)]);
        }
        $this->mint((string) Str::uuid());
        $this->assertDatabaseCount(ListingStudioTrialAdmission::TABLE, 76);
    }

    public function test_kill_switch_is_rechecked_after_acquiring_trial_reservation_guard(): void
    {
        $key = (string) Str::uuid();
        $token = $this->mint($key);
        $this->verify($token)->assertOk();
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'update "listing_studio_trial_guard"')) {
                config(['listing-studio-trials.enabled' => false]);
            }
        });
        $this->upload($key, $token)->assertStatus(503)->assertJsonPath('code', 'trial_unavailable');
        $this->assertNull($this->row($token)->consumed_at);
        $this->assertDatabaseCount('listing_studio_guest_trials', 0);
    }

    public function test_cors_is_exact_origin_and_route_with_no_credentials_on_preflight_success_or_errors(): void
    {
        $this->options(self::VERIFY, [], ['Origin' => self::ORIGIN, 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'content-type'])
            ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', self::ORIGIN)->assertHeader('Access-Control-Allow-Methods', 'POST')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
        $this->options(self::VERIFY, [], ['Origin' => self::ORIGIN, 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'Authorization'])
            ->assertForbidden()->assertHeaderMissing('Access-Control-Allow-Credentials');
        foreach (['http://reprophotos.com', 'https://fake.reprophotos.com', 'https://reprophotos.com.attacker.test', 'null', ''] as $origin) {
            $this->postJson(self::VERIFY, [], ['Origin' => $origin])->assertForbidden()->assertHeaderMissing('Access-Control-Allow-Origin');
        }
        $this->postJson(self::VERIFY, [], ['Origin' => self::ORIGIN])->assertUnprocessable()
            ->assertHeader('Access-Control-Allow-Origin', self::ORIGIN)->assertHeaderMissing('Access-Control-Allow-Credentials');
        $neighbor = $this->options(self::BRIDGE.'/trials', [], ['Origin' => self::ORIGIN, 'Access-Control-Request-Method' => 'POST']);
        $this->assertNotSame(self::ORIGIN, $neighbor->headers->get('Access-Control-Allow-Origin'));
        Http::assertNothingSent();
    }

    public function test_verification_bounds_actual_json_body_before_validation_and_rate_limits_ip(): void
    {
        $this->postJson(self::VERIFY, ['token' => str_repeat('a', 5000)], ['Origin' => self::ORIGIN])->assertStatus(413);
        $token = $this->mint((string) Str::uuid());
        $this->reply = ['success' => false];
        for ($i = 0; $i < 20; $i++) {
            $this->verify($token)->assertUnprocessable();
        }
        $this->verify($token)->assertStatus(429)->assertJsonPath('code', 'trial_rate_limit');
        $this->assertDatabaseCount('listing_studio_guest_trials', 0);
    }
}
