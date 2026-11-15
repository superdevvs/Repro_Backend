<?php

namespace Tests\Feature;

use App\Jobs\ProcessIguideLabImport;
use App\Models\IguideLabImport;
use App\Models\IguideLabProject;
use App\Services\IguideLab\LabService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class IguideLabImportCapacityTest extends TestCase
{
    use RefreshDatabase;

    private IguideLabProject $project;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $disk = Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK));
        $disk->makeDirectory('');
        $disk->setVisibility('', 'private');
        Queue::fake();
        Http::preventStrayRequests();
        $this->token = str_repeat('a', 64);
        $this->project = IguideLabProject::create(['name' => 'Import limit', 'token_hash' => hash('sha256', $this->token),
            'expires_at' => now()->addDay(), 'max_runs' => 120, 'used_runs' => 87, 'max_cases' => 160]);
    }

    public function test_default_eight_counts_failed_and_completed_records_without_mutating_history(): void
    {
        $this->assertSame(8, config('iguide-lab.import_daily_limit'));
        $this->seedImports(7);
        $first = $this->start()->assertAccepted()->json('data');
        IguideLabImport::findOrFail($first['id'])->update(['status' => 'needs_attention', 'error' => 'Retained failure']);
        $before = IguideLabImport::orderBy('id')->get()->toArray();
        $this->start()->assertStatus(429)->assertJsonPath('code', 'scan_import_daily_limit')
            ->assertJsonPath('message', 'This lab has reached its limit of 8 scan imports in 24 hours. Please try again later.')
            ->assertJsonPath('limit', 8)->assertJsonPath('windowHours', 24);
        $this->assertSame($before, IguideLabImport::orderBy('id')->get()->toArray());
        $this->assertSame(87, $this->project->fresh()->used_runs);
        $this->assertSame(120, $this->project->fresh()->max_runs);
        $this->assertSame(160, $this->project->fresh()->max_cases);
        Queue::assertPushed(ProcessIguideLabImport::class, 1);
        Http::assertNothingSent();
    }

    public function test_operator_override_is_per_project_and_preserves_two_active_queue_limit(): void
    {
        config(['iguide-lab.import_daily_limit' => '16']);
        $this->seedImports(8);
        $other = IguideLabProject::create(['name' => 'Other', 'token_hash' => hash('sha256', str_repeat('b', 64)), 'expires_at' => now()->addDay(), 'max_runs' => 10]);
        $this->seedImports(16, $other);
        $this->start()->assertAccepted();
        $this->start()->assertAccepted();
        $this->start()->assertStatus(429)->assertJsonPath('code', 'scan_import_active_limit')
            ->assertJsonPath('message', 'Two scan imports are already processing. Wait for one to finish before adding another scan.')
            ->assertJsonPath('limit', 2);
        $this->assertSame(10, IguideLabImport::where('project_id', $this->project->id)->count());
        Queue::assertPushed(ProcessIguideLabImport::class, 2);
        Http::assertNothingSent();
    }

    public function test_maximum_limit_is_finite_and_rolling_window_boundary_is_inclusive(): void
    {
        $this->travelTo(now()->startOfSecond());
        config(['iguide-lab.import_daily_limit' => 64]);
        $this->seedImports(62);
        $this->seedImports(1, at: now()->subDay());
        $this->seedImports(1, at: now()->subDay()->subSecond());
        $first = $this->start()->assertAccepted()->json('data');
        IguideLabImport::findOrFail($first['id'])->update(['status' => 'completed']);
        $this->start()->assertStatus(429)->assertJsonPath('code', 'scan_import_daily_limit')->assertJsonPath('limit', 64)->assertJsonPath('windowHours', 24);
        $this->assertSame(65, IguideLabImport::count());
        $this->travelBack();
    }

    public function test_explicit_zero_removes_daily_count_but_preserves_active_limit_replay_and_history(): void
    {
        config(['iguide-lab.import_daily_limit' => 0]);
        $firstBody = null;
        $firstId = null;
        for ($i = 0; $i < 10; $i++) {
            $body = ['requestId' => (string) Str::uuid(), 'url' => 'https://youriguide.com/sample/'];
            $id = $this->withToken($this->token)->postJson('/api/iguide-lab/imports', $body)->assertAccepted()->json('data.id');
            $firstBody ??= $body;
            $firstId ??= $id;
            IguideLabImport::findOrFail($id)->update(['status' => 'completed']);
        }
        config(['iguide-lab.import_daily_limit' => '0']);
        $this->start()->assertAccepted();
        $this->start()->assertAccepted();
        $before = IguideLabImport::orderBy('id')->get()->toArray();
        $this->start()->assertStatus(429)->assertJsonPath('code', 'scan_import_active_limit')->assertJsonPath('limit', 2);
        $this->withToken($this->token)->postJson('/api/iguide-lab/imports', $firstBody)->assertAccepted()->assertJsonPath('data.id', $firstId);
        $this->assertSame($before, IguideLabImport::orderBy('id')->get()->toArray());
        $this->assertSame(12, IguideLabImport::count());
        $this->assertSame(87, $this->project->fresh()->used_runs);
        $this->assertSame(120, $this->project->fresh()->max_runs);
        $this->assertSame(160, $this->project->fresh()->max_cases);
        Queue::assertPushed(ProcessIguideLabImport::class, 12);
        Http::assertNothingSent();
    }

    public function test_exact_replay_survives_lowered_or_invalid_operator_limit_but_changed_identity_does_not(): void
    {
        config(['iguide-lab.import_daily_limit' => 16]);
        $body = ['requestId' => (string) Str::uuid(), 'url' => 'https://youriguide.com/sample/'];
        $id = $this->withToken($this->token)->postJson('/api/iguide-lab/imports', $body)->assertAccepted()->json('data.id');
        foreach ([1, null, 'invalid'] as $limit) {
            config(['iguide-lab.import_daily_limit' => $limit]);
            $this->withToken($this->token)->postJson('/api/iguide-lab/imports', $body)->assertAccepted()->assertJsonPath('data.id', $id);
            $this->withToken($this->token)->postJson('/api/iguide-lab/imports', array_replace($body, ['url' => 'https://youriguide.com/different/']))->assertConflict();
        }
        $this->assertSame(1, IguideLabImport::count());
        Queue::assertPushed(ProcessIguideLabImport::class, 1);
        Http::assertNothingSent();
    }

    public function test_invalid_operator_values_fail_closed_and_public_fields_cannot_override_limit(): void
    {
        foreach ([65, -1, 0.0, 8.5, true, false, null, '00', '-1', '08', '8.0', '8foo', ' 8', '1e1', '999999999999999999999'] as $limit) {
            config(['iguide-lab.import_daily_limit' => $limit]);
            $this->start()->assertStatus(503)->assertJsonPath('message', 'This service is temporarily unavailable. Please try again.');
        }
        config(['iguide-lab.import_daily_limit' => 8]);
        $this->withToken($this->token)->postJson('/api/iguide-lab/imports', ['requestId' => (string) Str::uuid(),
            'url' => 'https://youriguide.com/sample/', 'dailyLimit' => 64])->assertUnprocessable();
        $this->withToken('not-a-capability')->postJson('/api/iguide-lab/imports', ['requestId' => (string) Str::uuid(), 'url' => 'https://youriguide.com/sample/'])->assertUnauthorized();
        $this->assertSame(0, IguideLabImport::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    private function start()
    {
        return $this->withToken($this->token)->postJson('/api/iguide-lab/imports', ['requestId' => (string) Str::uuid(), 'url' => 'https://youriguide.com/sample/']);
    }

    private function seedImports(int $count, ?IguideLabProject $project = null, $at = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            IguideLabImport::create(['project_id' => ($project ?? $this->project)->id, 'request_id' => (string) Str::uuid(),
                'request_hash' => hash('sha256', (string) Str::uuid()), 'source_kind' => 'iguide_link', 'private_input' => [],
                'status' => $i % 2 ? 'needs_attention' : 'completed', 'sources' => [], 'audit' => [], 'created_at' => $at ?? now(), 'updated_at' => $at ?? now()]);
        }
    }
}
