<?php

namespace Tests\Feature;

use App\Models\IguideLabImport;
use App\Models\IguideLabImportUpload;
use App\Models\IguideLabProject;
use App\Services\IguideLab\LabService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class IguideLabImportUploadCapacityTest extends TestCase
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
        $this->project = IguideLabProject::create(['name' => 'ZIP upload limit', 'token_hash' => hash('sha256', $this->token),
            'expires_at' => now()->addDay(), 'max_runs' => 120, 'used_runs' => 87, 'max_cases' => 500]);
    }

    public function test_zero_accepts_more_than_eight_http_starts_without_changing_history_or_image_capacity(): void
    {
        config(['iguide-lab.import_daily_limit' => 0]);
        $firstBody = $this->body();
        $firstId = null;
        for ($i = 0; $i < 10; $i++) {
            // Model terminal history without uploading bytes or executing assembly.
            $id = $this->start($i === 0 ? $firstBody : null)->assertCreated()->json('data.id');
            $firstId ??= $id;
            IguideLabImportUpload::findOrFail($id)->update(['status' => $i % 2 ? 'needs_attention' : 'completed']);
        }
        config(['iguide-lab.import_daily_limit' => '0']);
        $this->start()->assertCreated();
        $before = IguideLabImportUpload::orderBy('id')->get()->toArray();
        $this->start()->assertStatus(429)->assertJsonPath('code', 'scan_upload_active_limit')
            ->assertJsonPath('message', 'A scan ZIP upload is already in progress. Wait for it to finish before adding another scan ZIP.')
            ->assertJsonPath('limit', 1);
        $this->start($firstBody)->assertCreated()->assertJsonPath('data.id', $firstId);
        $this->assertSame($before, IguideLabImportUpload::orderBy('id')->get()->toArray());
        $this->assertSame(11, IguideLabImportUpload::count());
        $project = $this->project->fresh();
        $this->assertSame(87, $project->used_runs);
        $this->assertSame(120, $project->max_runs);
        $this->assertSame(500, $project->max_cases);
        $this->assertSame(hash('sha256', $this->token), $project->token_hash);
        $this->assertSame(0, IguideLabImport::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_default_eight_counts_all_statuses_and_reports_a_safe_daily_error(): void
    {
        $this->assertSame(8, config('iguide-lab.import_daily_limit'));
        $this->seedUploads(7);
        $id = $this->start()->assertCreated()->json('data.id');
        IguideLabImportUpload::findOrFail($id)->update(['status' => 'completed']);
        $before = IguideLabImportUpload::orderBy('id')->get()->toArray();
        $this->start()->assertStatus(429)->assertJsonPath('code', 'scan_upload_daily_limit')
            ->assertJsonPath('message', 'This lab has reached its limit of 8 scan ZIP uploads in 24 hours. Please try again later.')
            ->assertJsonPath('limit', 8)->assertJsonPath('windowHours', 24);
        $this->assertSame($before, IguideLabImportUpload::orderBy('id')->get()->toArray());
    }

    public function test_positive_limit_is_per_project_with_an_inclusive_rolling_day_boundary(): void
    {
        $this->travelTo(now()->startOfSecond());
        config(['iguide-lab.import_daily_limit' => '16']);
        $other = IguideLabProject::create(['name' => 'Other', 'token_hash' => hash('sha256', str_repeat('b', 64)),
            'expires_at' => now()->addDay(), 'max_runs' => 10]);
        $this->seedUploads(16, $other);
        $this->seedUploads(14);
        $this->seedUploads(1, at: now()->subDay());
        $this->seedUploads(1, at: now()->subDay()->subSecond());
        $id = $this->start()->assertCreated()->json('data.id');
        IguideLabImportUpload::findOrFail($id)->update(['status' => 'completed']);
        $this->start()->assertStatus(429)->assertJsonPath('code', 'scan_upload_daily_limit')->assertJsonPath('limit', 16);
        $this->assertSame(17, IguideLabImportUpload::where('project_id', $this->project->id)->count());
        $this->travelBack();
    }

    public function test_single_active_slot_covers_uploading_and_assembling_and_replays_before_any_limit(): void
    {
        config(['iguide-lab.import_daily_limit' => 0]);
        $body = $this->body();
        $id = $this->start($body)->assertCreated()->json('data.id');
        foreach (['uploading', 'assembling'] as $status) {
            IguideLabImportUpload::findOrFail($id)->update(['status' => $status]);
            $this->start()->assertStatus(429)->assertJsonPath('code', 'scan_upload_active_limit')->assertJsonPath('limit', 1);
            foreach ([1, null, 'invalid'] as $limit) {
                config(['iguide-lab.import_daily_limit' => $limit]);
                $this->start($body)->assertCreated()->assertJsonPath('data.id', $id);
                $this->start(array_replace($body, ['sha256' => str_repeat('f', 64)]))->assertConflict();
            }
            config(['iguide-lab.import_daily_limit' => 0]);
        }
        $this->start(array_replace($body, ['requestId' => (string) Str::uuid()]))->assertConflict();
        $this->assertSame(1, IguideLabImportUpload::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_invalid_operator_values_and_public_limit_overrides_cannot_admit_uploads(): void
    {
        foreach ([65, -1, 0.0, 8.5, true, false, null, '00', '-1', '08', '8.0', '8foo', ' 8', '1e1'] as $limit) {
            config(['iguide-lab.import_daily_limit' => $limit]);
            $this->start()->assertStatus(503)->assertJsonPath('message', 'This service is temporarily unavailable. Please try again.');
        }
        config(['iguide-lab.import_daily_limit' => 0]);
        $this->start(array_replace($this->body(), ['dailyLimit' => 0]))->assertUnprocessable();
        $this->withToken('not-a-capability')->postJson('/api/iguide-lab/import-uploads', $this->body())->assertUnauthorized();
        $this->assertSame(0, IguideLabImportUpload::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    private function body(): array
    {
        return ['requestId' => (string) Str::uuid(), 'importRequestId' => (string) Str::uuid(),
            'filename' => 'scan.zip', 'sizeBytes' => 123456789, 'sha256' => str_repeat('a', 64), 'metadataSha256' => null];
    }

    private function start(?array $body = null)
    {
        return $this->withToken($this->token)->postJson('/api/iguide-lab/import-uploads', $body ?? $this->body());
    }

    private function seedUploads(int $count, ?IguideLabProject $project = null, $at = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            IguideLabImportUpload::create(['project_id' => ($project ?? $this->project)->id,
                'request_id' => (string) Str::uuid(), 'import_request_id' => (string) Str::uuid(),
                'request_hash' => hash('sha256', (string) Str::uuid()), 'filename' => 'retained.zip',
                'size_bytes' => 123456789, 'sha256' => str_repeat('b', 64), 'chunks' => [],
                'status' => $i % 2 ? 'needs_attention' : 'completed', 'expires_at' => now()->addDay(),
                'created_at' => $at ?? now(), 'updated_at' => $at ?? now()]);
        }
    }
}
