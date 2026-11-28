<?php

namespace Tests\Feature;

use App\Models\{IguideLabProject, IguideLabRun, IguideLabTourBudget};
use App\Services\IguideLab\{LabRunCapacity, LabService, LabTourProcessingBudget};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Http, Queue, Schema, Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class IguideLabRunCapacityTest extends TestCase
{
    use RefreshDatabase;

    private IguideLabProject $project;
    private string $caseId;
    private string $key;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = tempnam(sys_get_temp_dir(), 'run-cap-key-');
        file_put_contents($this->key, 'synthetic-only');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'iguide-lab.openai_primary_key_path' => $this->key, 'services.openai.api_key' => 'synthetic-only']);
        Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        Queue::fake(); Http::preventStrayRequests();
        $this->token = str_repeat('a', 64);
        $this->project = IguideLabProject::create(['name' => 'Run capacity', 'token_hash' => hash('sha256', $this->token),
            'expires_at' => now()->addDay(), 'max_runs' => 120, 'max_cases' => 160]);
        $png = (string) \Intervention\Image\ImageManager::gd()->create(128, 64)->fill('888888')->toPng();
        $this->caseId = app(LabService::class)->addCase($this->project, UploadedFile::fake()->createWithContent('source.png', $png),
            ['label' => 'Retained original', 'projection' => 'equirectangular'])->id;
    }

    protected function tearDown(): void
    {
        unlink($this->key);
        $this->travelBack();
        parent::tearDown();
    }

    private function input(): array
    {
        return ['caseId' => $this->caseId, 'requestId' => (string) Str::uuid(), 'modelId' => 'gpt-image-2.6-sunburst', 'preset' => 'grass'];
    }

    private function rejects(int $status, callable $call): void
    {
        try { $call(); $this->fail('Expected a capacity guard.'); }
        catch (HttpException $error) { $this->assertSame($status, $error->getStatusCode()); }
    }

    private function retainedTables(): array
    {
        $rows = [];
        $schema = DB::getDriverName() === 'mysql' ? DB::getDatabaseName() : null;
        $tables = array_values(array_filter(Schema::getTableListing($schema, schemaQualified: false), fn ($name) => str_starts_with($name, 'iguide_lab_')));
        $this->assertContains('iguide_lab_runs', $tables, 'The retention check must inspect the actual lab tables.');
        sort($tables);
        foreach ($tables as $table) {
            if ($table !== 'iguide_lab_projects') $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        return $rows;
    }

    public function test_extension_preserves_auth_usage_history_output_and_grant_authority(): void
    {
        $run = app(LabService::class)->start($this->project, $this->input());
        Storage::disk(LabService::DISK)->put('synthetic-retained-output', 'unchanged output bytes');
        $run->update(['status' => 'needs_attention', 'error' => 'Retained synthetic unresolved provider attempt']);
        $grant = app(LabTourProcessingBudget::class)->create($this->project->id, 2,
            ['analysis' => 10, 'section' => 4, 'image' => 4, 'segmentation' => 30, 'composition' => 2, 'evaluation' => 2], now()->addHours(2)->timestamp);
        $other = IguideLabProject::create(['name' => 'Unrelated', 'token_hash' => str_repeat('b', 64), 'expires_at' => now()->addDay()]);
        $before = $this->project->fresh()->getRawOriginal();
        $otherBefore = $other->fresh()->getRawOriginal();
        $tables = $this->retainedTables();
        $files = Storage::disk(LabService::DISK)->allFiles();
        $this->artisan('iguide-lab:set-run-limit', ['project' => $this->project->id, 'limit' => '320', '--expected-current' => '120'])->assertSuccessful();
        $after = $this->project->fresh()->getRawOriginal();
        $this->assertSame(320, $after['max_runs']);
        foreach (array_diff(array_keys($before), ['max_runs', 'updated_at']) as $field) $this->assertSame($before[$field], $after[$field], $field);
        $this->assertSame($otherBefore, $other->fresh()->getRawOriginal());
        $this->assertSame($tables, $this->retainedTables());
        $this->assertSame($grant['authoritySha256'], IguideLabTourBudget::sole()->authority_sha256);
        $this->assertSame($files, Storage::disk(LabService::DISK)->allFiles());
        $this->assertSame('unchanged output bytes', Storage::disk(LabService::DISK)->get('synthetic-retained-output'));
        $this->withToken($this->token)->getJson('/api/iguide-lab')->assertOk()->assertJsonPath('data.project.maxRuns', 320)->assertJsonPath('data.project.usedRuns', 1);
        $this->withHeader('Authorization', '')->getJson('/api/iguide-lab')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_required_expected_limit_strict_input_stale_and_decrease_fail_without_writes(): void
    {
        $before = $this->project->fresh()->getRawOriginal();
        $this->artisan('iguide-lab:set-run-limit', ['project' => $this->project->id, 'limit' => '320'])->assertFailed();
        foreach (['0', '-1', '1.5', '1025', '999999999999999999999', 'unlimited', ' 320', '0320'] as $value) {
            $this->artisan('iguide-lab:set-run-limit', ['project' => $this->project->id, 'limit' => $value, '--expected-current' => '120'])->assertFailed();
            $this->artisan('iguide-lab:set-run-limit', ['project' => $this->project->id, 'limit' => '320', '--expected-current' => $value])->assertFailed();
        }
        $service = app(LabRunCapacity::class);
        $this->rejects(409, fn () => $service->extend($this->project->id, 320, 119));
        $this->rejects(422, fn () => $service->extend($this->project->id, 119, 120));
        $this->assertSame($before, $this->project->fresh()->getRawOriginal());
        $receipt = $service->extend($this->project->id, 1024, 120);
        $this->assertSame(120, $receipt['previousMaxRuns']);
        $this->assertSame(1024, $receipt['maxRuns']);
        $this->assertTrue($receipt['changed']);
        $this->assertFalse($receipt['capabilityChanged']);
        $this->assertFalse($receipt['expiryChanged']);
        $this->assertSame(0, $receipt['providerCalls']);
        $atLimit = $this->project->fresh()->getRawOriginal();
        $this->travel(1)->minutes();
        $this->assertFalse($service->extend($this->project->id, 1024, 1024)['changed']);
        $this->rejects(409, fn () => $service->extend($this->project->id, 1024, 120));
        $this->assertSame($atLimit, $this->project->fresh()->getRawOriginal());
        Http::assertNothingSent(); Queue::assertNothingPushed();
    }

    public function test_inactive_missing_and_corrupt_usage_fail_closed(): void
    {
        foreach ([['revoked_at' => now()], ['revoked_at' => null, 'expires_at' => now()->subSecond()]] as $state) {
            $this->project->update($state); $before = $this->project->fresh()->getRawOriginal();
            $this->rejects(422, fn () => app(LabRunCapacity::class)->extend($this->project->id, 320, 120));
            $this->assertSame($before, $this->project->fresh()->getRawOriginal());
        }
        $this->project->update(['expires_at' => now()->addDay(), 'used_runs' => 121]);
        $before = $this->project->fresh()->getRawOriginal();
        $this->rejects(503, fn () => app(LabRunCapacity::class)->extend($this->project->id, 320, 120));
        $this->assertSame($before, $this->project->fresh()->getRawOriginal());
        $this->artisan('iguide-lab:set-run-limit', ['project' => (string) Str::uuid(), 'limit' => '320', '--expected-current' => '120'])->assertFailed();
        $this->assertSame(1, IguideLabProject::count());
    }

    public function test_existing_image_boundary_and_exact_replay_still_charge_once(): void
    {
        $this->project->update(['max_runs' => 1]);
        $input = $this->input();
        $first = app(LabService::class)->start($this->project, $input);
        $secondInput = $this->input();
        $this->rejects(429, fn () => app(LabService::class)->start($this->project, $secondInput));
        app(LabRunCapacity::class)->extend($this->project->id, 2, 1);
        $second = app(LabService::class)->start($this->project, $secondInput);
        $this->assertSame($first->id, app(LabService::class)->start($this->project, $input)->id);
        $this->assertSame($second->id, app(LabService::class)->start($this->project, $secondInput)->id);
        $this->rejects(429, fn () => app(LabService::class)->start($this->project, $this->input()));
        $this->assertSame(2, $this->project->fresh()->used_runs);
        $this->assertSame(2, IguideLabRun::count());
        Queue::assertPushed(\App\Jobs\ProcessIguideLabRun::class, 2); Http::assertNothingSent();
    }

    public function test_extension_does_not_bypass_legacy_daily_image_limit(): void
    {
        $first = app(LabService::class)->start($this->project, $this->input());
        for ($i = 1; $i < 120; $i++) {
            $copy = $first->replicate(); $copy->request_id = (string) Str::uuid(); $copy->save();
        }
        $this->project->update(['used_runs' => 120]);
        app(LabRunCapacity::class)->extend($this->project->id, 320, 120);
        $this->rejects(429, fn () => app(LabService::class)->start($this->project, $this->input()));
        $this->assertSame(120, $this->project->fresh()->used_runs);
        $this->assertSame(120, IguideLabRun::count()); Http::assertNothingSent();
    }

    public function test_competing_sqlite_writers_cannot_both_extend_from_the_same_expected_limit(): void
    {
        if (DB::getDriverName() !== 'sqlite') $this->markTestSkipped('SQLite WAL mechanics; native MySQL contention is covered by IguideLabConcurrencyTest.');
        $directory = sys_get_temp_dir().'/iguide-run-cap-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory.'/isolated.sqlite';
        $pdo = new \PDO('sqlite:'.$database);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec(DB::selectOne("SELECT sql FROM sqlite_master WHERE name='iguide_lab_projects'")->sql);
        $row = (array) DB::table('iguide_lab_projects')->where('id', $this->project->id)->first();
        $statement = $pdo->prepare('INSERT INTO iguide_lab_projects ('.implode(',', array_keys($row)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
        $statement->execute(array_values($row)); $statement = null;
        $workers = [];
        try {
            foreach ([320, 480] as $limit) {
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/iguide-run-capacity-worker.php'),
                    $database, $this->project->id, (string) $limit, $directory.'/barrier'], base_path());
                $worker->setTimeout(40); $worker->start(); $workers[] = $worker;
            }
            $deadline = microtime(true) + 30;
            while (!is_file($directory.'/barrier.320') || !is_file($directory.'/barrier.480')) {
                if (microtime(true) >= $deadline) $this->fail('Isolated capacity workers did not initialize.');
                foreach ($workers as $worker) if (!$worker->isRunning()) $this->fail($worker->getErrorOutput().$worker->getOutput());
                usleep(10000);
            }
            // Both real child processes contend on this writer lock before either reads usage.
            $pdo->exec('BEGIN IMMEDIATE');
            file_put_contents($directory.'/barrier.go', 'go'); usleep(100000); $pdo->exec('COMMIT');
            $statuses = []; $winner = null;
            foreach ($workers as $worker) {
                $worker->wait(); $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
                $result = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $statuses[] = $result['status']; if ($result['status'] === 200) $winner = $result['receipt']['maxRuns'];
            }
            sort($statuses); $this->assertSame([200, 409], $statuses);
            $after = $pdo->query('SELECT * FROM iguide_lab_projects')->fetch(\PDO::FETCH_ASSOC);
            $this->assertSame($winner, (int) $after['max_runs']);
            foreach (array_diff(array_keys($row), ['max_runs', 'updated_at']) as $field) $this->assertEquals($row[$field], $after[$field], $field);
            $this->assertSame(120, $this->project->fresh()->max_runs, 'Race uses only a separate temporary DB.');
        } finally {
            foreach ($workers as $worker) if ($worker->isRunning()) $worker->stop();
            $pdo = null;
            foreach (glob($directory.'/*') ?: [] as $path) unlink($path);
            rmdir($directory);
        }
    }
}
