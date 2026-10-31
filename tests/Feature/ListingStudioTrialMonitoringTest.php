<?php

namespace Tests\Feature;

use App\Jobs\ProcessStudioWorkspace;
use App\Models\ListingStudioGuestTrial;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\ListingStudio\ListingStudioGuestTrialService;
use App\Services\ListingStudio\ListingStudioTrialAdmission;
use App\Services\ListingStudio\ListingStudioTrialMonitoring;
use App\Services\Studio\StudioProviderSettings;
use App\Services\Studio\WorkspaceProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ListingStudioTrialMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(12, 0));
        Http::preventStrayRequests();
        config(['listing-studio-trials.global_daily_limit' => 10, 'listing-studio-trials.ip_daily_limit' => 3]);
    }

    public function test_health_is_read_only_and_counts_qa_in_health_and_quota_but_not_conversion(): void
    {
        $qa = $this->trial(['id' => config('listing-studio-trials.monitoring_qa_trial_ids')[0], 'status' => 'needs_attention']);
        $workspace = StudioWorkspace::create(['team_id' => null, 'created_by' => null, 'listing_studio_trial_id' => $qa->id,
            'name' => 'Private QA', 'preset_id' => 'green-grass', 'status' => 'completed', 'media' => [], 'config' => []]);
        $qa->update(['workspace_id' => $workspace->id]);
        $client = User::factory()->create(['role' => 'client', 'created_at' => now()->subMinutes(40)]);
        $this->trial(['status' => 'completed', 'client_id' => $client->id, 'claimed_at' => now()->subMinutes(30),
            'completed_at' => now()->subMinutes(45), 'worker_started_at' => now()->subMinutes(58),
            'first_download_requested_at' => now()->subMinutes(29), 'download_request_count' => 2]);
        $this->trial(['status' => 'queued']);
        $this->trial(['status' => 'processing', 'worker_started_at' => now()->subMinutes(45)]);
        $this->trial(['status' => 'expired', 'completed_at' => now()->subMinutes(40)]);
        $before = DB::table('listing_studio_guest_trials')->orderBy('id')->get()->toJson();
        $snapshot = app(ListingStudioTrialMonitoring::class)->snapshot();
        $this->assertSame(5, $snapshot['quota']['starts']);
        $this->assertSame(5, $snapshot['quota']['max_starts_from_one_connection']);
        $this->assertSame(2, $snapshot['services']['grass']['completed']);
        $this->assertSame(2, $snapshot['services']['grass']['queue_delay_samples']);
        $this->assertSame(900, $snapshot['services']['grass']['queue_delay_seconds_p95']);
        $this->assertSame(1, $snapshot['active']['needs_attention']);
        $this->assertSame(1, $snapshot['active']['finalization_gap']);
        $this->assertSame(1, $snapshot['active']['queued_over_5_minutes']);
        $this->assertSame(1, $snapshot['active']['processing_over_15_minutes']);
        $this->assertSame(1, $snapshot['conversion']['qa_trials_excluded']);
        $this->assertSame(4, $snapshot['conversion']['starts']);
        $this->assertSame(1, $snapshot['conversion']['new_accounts_with_download_request']);
        $this->assertSame(1, $snapshot['conversion']['trials_with_download_request']);
        $this->assertSame(2, $snapshot['conversion']['download_requests']);
        $this->assertSame($before, DB::table('listing_studio_guest_trials')->orderBy('id')->get()->toJson());
        $this->assertSame(1, Artisan::call('listing-studio:trial-health'));
        $output = Artisan::output();
        foreach ([$qa->id, $qa->session_hash, $qa->ip_hash, $qa->source_path, $client->email, 'idempotency_key'] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
        Http::assertNothingSent();
    }

    public function test_worker_start_is_recorded_once_across_recovery_and_does_not_run_a_provider(): void
    {
        $trial = $this->trial();
        $workspace = StudioWorkspace::create(['team_id' => null, 'created_by' => null, 'listing_studio_trial_id' => $trial->id,
            'name' => 'Private trial', 'preset_id' => 'green-grass', 'status' => 'generating', 'media' => [], 'config' => [],
            'operation' => ['id' => 'operation']]);
        $trial->update(['workspace_id' => $workspace->id]);
        $processor = $this->mock(WorkspaceProcessor::class)->shouldReceive('process')->twice()->getMock();
        (new ProcessStudioWorkspace($workspace->id, 'operation'))->handle($processor);
        $first = $trial->fresh()->worker_started_at->toIso8601String();
        $this->travel(10)->minutes();
        $workspace->update(['operation' => ['id' => 'operation']]);
        (new ProcessStudioWorkspace($workspace->id, 'operation'))->handle($processor);
        $this->assertSame($first, $trial->fresh()->worker_started_at->toIso8601String());
        Http::assertNothingSent();
    }

    public function test_replay_and_conflict_count_without_new_job_and_denial_survives_reservation_rollback(): void
    {
        $image = UploadedFile::fake()->image('private-photo.jpg', 20, 20);
        $hash = hash('sha256', json_encode(['grass', [], hash_file('sha256', $image->getRealPath())], JSON_THROW_ON_ERROR));
        $trial = $this->trial(['request_hash' => $hash]);
        $service = app(ListingStudioGuestTrialService::class);
        $this->assertSame($trial->id, $service->start($trial->session_hash, $trial->idempotency_key, $image, ['service' => 'grass'])->id);
        try {
            $service->start($trial->session_hash, $trial->idempotency_key, $image, ['service' => 'twilight']);
            $this->fail('Expected idempotency conflict.');
        } catch (\App\Exceptions\PublicApiException $exception) { $this->assertSame('idempotency_conflict', $exception->errorCode); }
        config(['listing-studio-trials.enabled' => true, 'listing-studio-trials.bridge_key' => str_repeat('k', 40),
            'listing-studio-trials.turnstile_secret' => 'secret', 'listing-studio-trials.turnstile_site_key' => 'site',
            'listing-studio-trials.turnstile_hosts' => ['reprophotos.com'], 'listing-studio-trials.global_daily_limit' => 1]);
        $this->mock(StudioProviderSettings::class, function ($mock) {
            $mock->shouldReceive('route')->andReturn(['provider' => 'test']);
            $mock->shouldReceive('readiness')->andReturn(['ready' => true]);
        });
        $token = str_repeat('c', 64);
        $session = str_repeat('d', 64);
        DB::table('listing_studio_trial_admissions')->insert(['token_hash' => hash('sha256', $token), 'session_hash' => $session,
            'idempotency_key' => 'new-request', 'ip_hash' => str_repeat('e', 64), 'verified_at' => now(), 'expires_at' => now()->addMinutes(5),
            'created_at' => now(), 'updated_at' => now()]);
        try {
            $service->start($session, 'new-request', $image, ['service' => 'grass', 'admissionToken' => $token]);
            $this->fail('Expected daily limit.');
        } catch (\App\Exceptions\PublicApiException $exception) { $this->assertSame('trial_daily_limit', $exception->errorCode); }
        $this->assertNull(DB::table('listing_studio_trial_admissions')->value('consumed_at'));
        foreach (['idempotency_replay', 'idempotency_conflict', 'trial_daily_limit'] as $event) {
            $this->assertDatabaseHas('listing_studio_trial_counters', ['day' => '2026-10-31', 'event' => $event, 'count' => 1]);
        }
        $this->assertDatabaseCount('listing_studio_guest_trials', 1);
        Schema::drop('listing_studio_trial_counters');
        $this->assertSame($trial->id, $service->start($trial->session_hash, $trial->idempotency_key, $image, ['service' => 'grass'])->id);
        try {
            $service->start($trial->session_hash, $trial->idempotency_key, $image, ['service' => 'twilight']);
            $this->fail('A telemetry outage must not replace the original conflict.');
        } catch (\App\Exceptions\PublicApiException $exception) { $this->assertSame('idempotency_conflict', $exception->errorCode); }
        Http::assertNothingSent();
    }

    public function test_admission_limits_are_counted_without_storing_any_request_identifier(): void
    {
        config(['listing-studio-trials.enabled' => true, 'listing-studio-trials.bridge_key' => str_repeat('k', 40),
            'listing-studio-trials.turnstile_secret' => 'secret', 'listing-studio-trials.turnstile_site_key' => 'site',
            'listing-studio-trials.turnstile_hosts' => ['reprophotos.com'], 'listing-studio-trials.admission_global_limit' => 0]);
        try {
            app(ListingStudioTrialAdmission::class)->mint(str_repeat('a', 64), 'private-request-id');
            $this->fail('Expected admission limit.');
        } catch (\App\Exceptions\PublicApiException $exception) { $this->assertSame('trial_admission_limit', $exception->errorCode); }
        $this->assertSame(['day', 'event', 'service', 'count'], Schema::getColumnListing('listing_studio_trial_counters'));
        $this->assertDatabaseHas('listing_studio_trial_counters', ['event' => 'trial_admission_limit', 'service' => 'all', 'count' => 1]);
        $this->assertDatabaseCount('listing_studio_trial_admissions', 0);
    }

    public function test_only_authorized_ready_download_requests_are_counted_and_telemetry_failure_does_not_block_download(): void
    {
        $private = Storage::fake('listing_studio_trials', config('filesystems.disks.listing_studio_trials'));
        $private->makeDirectory('');
        $private->setVisibility('', 'private');
        $client = User::factory()->create(['role' => 'client']);
        $trial = $this->trial(['status' => 'completed', 'client_id' => $client->id, 'completed_at' => now(),
            'workspace_id' => (string) Str::uuid()]);
        $trial->update(['output_path' => 'studio/workspaces/'.$trial->workspace_id.'/output.jpg']);
        app(\App\Services\ListingStudio\ListingStudioPrivateStorage::class)->prepareWorkspaceDirectory($trial->workspace_id);
        Storage::disk('listing_studio_trials')->put($trial->output_path, 'private-output');
        $url = '/api/listing-studio/trials/'.$trial->id.'/download';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($client, 'sanctum')->get($url)->assertOk();
        $first = $trial->fresh()->first_download_requested_at->toIso8601String();
        $this->travel(1)->minute();
        $this->get($url)->assertOk();
        $this->assertSame($first, $trial->fresh()->first_download_requested_at->toIso8601String());
        $this->assertSame(2, $trial->fresh()->download_request_count);
        $this->actingAs(User::factory()->create(['role' => 'client']), 'sanctum')->get($url)->assertNotFound();
        $this->assertSame(2, $trial->fresh()->download_request_count);
        // A counter write failure must never replace the successful business response.
        Schema::table('listing_studio_guest_trials', fn ($table) => $table->dropColumn('download_request_count'));
        $this->actingAs($client, 'sanctum')->get($url)->assertOk();
    }

    public function test_counter_vocabulary_is_bounded_and_hourly_pruning_keeps_90_utc_days(): void
    {
        $originalTimeout = (int) DB::connection()->getPdo()->query('PRAGMA busy_timeout')->fetchColumn();
        $monitor = app(ListingStudioTrialMonitoring::class);
        $monitor->count('private-arbitrary-event', 'private-service');
        $this->assertDatabaseCount('listing_studio_trial_counters', 0);
        $monitor->count('trial_ip_limit', 'private-service');
        $monitor->count('trial_ip_limit', 'private-service');
        $this->assertDatabaseHas('listing_studio_trial_counters', ['service' => 'all', 'count' => 2]);
        DB::table('listing_studio_trial_counters')->insert([
            ['day' => now('UTC')->subDays(90)->toDateString(), 'event' => 'trial_ip_limit', 'service' => 'grass', 'count' => 3],
            ['day' => now('UTC')->subDays(89)->toDateString(), 'event' => 'trial_ip_limit', 'service' => 'grass', 'count' => 4],
        ]);
        $monitor->prune();
        $this->assertDatabaseCount('listing_studio_trial_counters', 2);
        Schema::drop('listing_studio_trial_counters');
        $monitor->count('trial_ip_limit', 'grass'); // Must not throw when telemetry is unavailable.
        $this->assertSame($originalTimeout, (int) DB::connection()->getPdo()->query('PRAGMA busy_timeout')->fetchColumn());
        Http::assertNothingSent();
    }

    public function test_real_sqlite_contention_cannot_delay_or_fail_an_authorized_download_or_log_private_sql(): void
    {
        $private = Storage::fake('listing_studio_trials', config('filesystems.disks.listing_studio_trials'));
        $private->makeDirectory('');
        $private->setVisibility('', 'private');
        $client = User::factory()->create(['role' => 'client']);
        $workspaceId = (string) Str::uuid();
        app(\App\Services\ListingStudio\ListingStudioPrivateStorage::class)->prepareWorkspaceDirectory($workspaceId);
        $outputPath = 'studio/workspaces/'.$workspaceId.'/edited.jpg';
        $private->put($outputPath, 'private completed output');
        $trialId = (string) Str::uuid();
        $file = tempnam(sys_get_temp_dir(), 'studio-telemetry-');
        $previousDefault = DB::getDefaultConnection();
        $connectionName = 'studio-telemetry-contention-test';
        $primary = DB::build(['name' => $connectionName, 'driver' => 'sqlite', 'database' => $file, 'prefix' => '',
            'busy_timeout' => 5000, 'journal_mode' => 'WAL']);
        $writer = null;
        try {
            $primary->statement('CREATE TABLE listing_studio_guest_trials (id TEXT PRIMARY KEY, client_id INTEGER, status TEXT, service TEXT, workspace_id TEXT, output_path TEXT, worker_started_at TEXT, first_download_requested_at TEXT, download_request_count INTEGER NOT NULL DEFAULT 0)');
            $primary->statement('CREATE TABLE listing_studio_trial_counters (day TEXT, event TEXT, service TEXT, count INTEGER, PRIMARY KEY (day,event,service))');
            $primary->table('listing_studio_guest_trials')->insert(['id' => $trialId, 'client_id' => $client->id,
                'status' => 'completed', 'service' => 'grass', 'workspace_id' => $workspaceId, 'output_path' => $outputPath]);
            DB::setDefaultConnection($connectionName);
            $request = Request::create('/api/listing-studio/trials/'.$trialId.'/download');
            $request->setUserResolver(fn () => $client);
            $controller = app(\App\Http\Controllers\API\ListingStudioGuestTrialController::class);
            $writer = new \PDO('sqlite:'.$file);
            $writer->exec('BEGIN IMMEDIATE');
            Log::spy();
            $started = microtime(true);
            $response = $controller->download($request, $trialId);
            $elapsed = microtime(true) - $started;
            $this->assertSame(200, $response->getStatusCode());
            $this->assertLessThan(1.0, $elapsed, 'Optional telemetry must not use the primary 5-second timeout or repeated retries.');
            $this->assertSame(5000, (int) $primary->getPdo()->query('PRAGMA busy_timeout')->fetchColumn());
            $this->assertSame(0, (int) $primary->table('listing_studio_guest_trials')->value('download_request_count'));
            $this->assertNull($primary->table('listing_studio_guest_trials')->value('first_download_requested_at'));
            Log::shouldHaveReceived('error')->once()->with('Listing Studio operational telemetry could not be stored.');
            Log::shouldNotHaveReceived('warning');
            $writer->exec('ROLLBACK');
            $writer = null;
            $this->assertSame(200, $controller->download($request, $trialId)->getStatusCode());
            $this->assertSame(1, (int) $primary->table('listing_studio_guest_trials')->value('download_request_count'));
            $this->assertNotNull($primary->table('listing_studio_guest_trials')->value('first_download_requested_at'));
            app(ListingStudioTrialMonitoring::class)->workerStarted($trialId);
            $this->assertNotNull($primary->table('listing_studio_guest_trials')->value('worker_started_at'));
            app(ListingStudioTrialMonitoring::class)->count('idempotency_replay', 'grass');
            $this->assertSame(1, (int) $primary->table('listing_studio_trial_counters')->value('count'));
            $this->assertSame(5000, (int) $primary->getPdo()->query('PRAGMA busy_timeout')->fetchColumn());
            // A missing expected database must never be recreated by optional telemetry.
            unlink($file);
            clearstatcache(true, $file);
            app(ListingStudioTrialMonitoring::class)->count('idempotency_replay', 'grass');
            $this->assertFileDoesNotExist($file);
            Log::shouldHaveReceived('error')->twice()->with('Listing Studio operational telemetry could not be stored.');
        } finally {
            if ($writer) { try { $writer->exec('ROLLBACK'); } catch (\Throwable) {} }
            $writer = null;
            DB::setDefaultConnection($previousDefault);
            DB::purge($connectionName);
            foreach ([$file, $file.'-wal', $file.'-shm'] as $path) { if (is_file($path)) { unlink($path); } }
        }
    }

    private function trial(array $overrides = []): ListingStudioGuestTrial
    {
        $id = $overrides['id'] ?? (string) Str::uuid();

        return ListingStudioGuestTrial::create(['id' => $id, 'session_hash' => str_repeat('a', 64), 'ip_hash' => str_repeat('b', 64),
            'service' => 'grass', 'status' => 'queued', 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('f', 64),
            'source_path' => 'trials/'.$id.'/private-source.jpg', 'source_mime' => 'image/jpeg', 'source_name' => 'Private photo.jpg',
            'options' => [], 'started_at' => now()->subHour(), 'expires_at' => now()->addDays(7), ...$overrides]);
    }
}
