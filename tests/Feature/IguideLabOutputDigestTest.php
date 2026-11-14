<?php

namespace Tests\Feature;

use App\Models\IguideLabCase;
use App\Models\IguideLabProject;
use App\Models\IguideLabRun;
use App\Services\IguideLab\LabOutputDigests;
use App\Services\IguideLab\LabService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class IguideLabOutputDigestTest extends TestCase
{
    use RefreshDatabase;
    private IguideLabProject $project;
    private string $token;
    private string $png;

    protected function setUp(): void
    {
        parent::setUp();
        $disk = Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK)); $disk->makeDirectory('');
        Http::preventStrayRequests(); Queue::fake();
        $this->token = str_repeat('a', 64);
        $this->project = IguideLabProject::create(['name' => 'Digests', 'token_hash' => hash('sha256', $this->token), 'expires_at' => now()->addDay(), 'max_runs' => 120, 'used_runs' => 9]);
        $image = imagecreatetruecolor(128, 64); ob_start(); imagepng($image); $this->png = ob_get_clean(); imagedestroy($image);
    }

    public function test_backfill_uses_actual_owned_png_bytes_and_replays_without_changing_request_or_quota(): void
    {
        $run = $this->completedResult(); $before = $run->only(['request_id', 'request_hash', 'options', 'usage', 'status', 'output_path', 'completed_at']);
        $this->assertNull(app(LabService::class)->runDto($run)['output']['sha256']);
        $this->artisan('iguide-lab:verify-output-digests', ['project' => $this->project->id])->assertSuccessful();
        $hash = hash('sha256', $this->png);
        $this->assertSame($hash, $run->fresh()->output_sha256);
        $this->assertEquals($before, $run->fresh()->only(array_keys($before)));
        $again = app(LabOutputDigests::class)->backfill($this->project->id);
        $this->assertSame(0, $again['storedDigests']); $this->assertSame(1, $again['previouslyVerifiedDigests']);
        $this->assertSame(9, $this->project->fresh()->used_runs);
        $this->withToken($this->token)->getJson('/api/iguide-lab/runs/'.$run->id)->assertOk()->assertJsonPath('data.output.sha256', $hash);
        $this->withHeader('Authorization', '')->getJson('/api/iguide-lab/runs/'.$run->id)->assertUnauthorized();
        Http::assertNothingSent(); Queue::assertNothingPushed();
    }

    public function test_missing_corrupt_or_changed_outputs_fail_closed_without_trusting_import_metadata(): void
    {
        $run = $this->completedResult(); $disk = Storage::disk(LabService::DISK);
        foreach ([null, 'not a PNG'] as $content) {
            $content === null ? $disk->delete($run->output_path) : $disk->put($run->output_path, $content);
            $this->artisan('iguide-lab:verify-output-digests', ['project' => $this->project->id])->assertFailed();
            $this->assertNull($run->fresh()->output_sha256);
        }
        $disk->put($run->output_path, $this->png); $run->update(['output_sha256' => str_repeat('f', 64)]);
        $this->artisan('iguide-lab:verify-output-digests', ['project' => $this->project->id])->assertFailed();
        $this->assertSame(str_repeat('f', 64), $run->fresh()->output_sha256);
        $run->update(['output_sha256' => null, 'output_width' => 256]);
        $this->artisan('iguide-lab:verify-output-digests', ['project' => $this->project->id])->assertFailed();
        $this->assertNull($run->fresh()->output_sha256);
    }

    public function test_foreign_paths_and_symlinked_owned_directory_are_not_hash_authorities(): void
    {
        $run = $this->completedResult(); $disk = Storage::disk(LabService::DISK); $owned = $run->output_path;
        $foreign = IguideLabProject::create(['name' => 'Other', 'token_hash' => str_repeat('b', 64), 'expires_at' => now()->addDay()]);
        $path = 'iguide-lab/'.$foreign->id.'/runs/'.$run->id.'.png'; $disk->put($path, $this->png);
        $run->update(['output_path' => $path, 'output_sha256' => hash('sha256', $this->png)]);
        $this->assertNull(app(LabOutputDigests::class)->dto($run));
        $this->artisan('iguide-lab:verify-output-digests', ['project' => $this->project->id])->assertFailed();
        $run->update(['output_path' => $owned, 'output_sha256' => null]); $disk->delete($owned);
        $dir = dirname($disk->path($owned)); rmdir($dir); symlink(dirname($disk->path($path)), $dir);
        try {
            $this->artisan('iguide-lab:verify-output-digests', ['project' => $this->project->id])->assertFailed();
            $this->assertNull($run->fresh()->output_sha256);
        } finally { unlink($dir); }
    }

    public function test_backfill_rechecks_file_and_record_after_obtaining_write_lock(): void
    {
        $run = $this->completedResult(); $disk = Storage::disk(LabService::DISK); $changed = false;
        DB::listen(function ($query) use ($run, $disk, &$changed) {
            if (! $changed && str_contains($query->sql, 'update "iguide_lab_projects"')) {
                $changed = true; $disk->put($run->output_path, $this->png.'changed');
            }
        });
        $this->artisan('iguide-lab:verify-output-digests', ['project' => $this->project->id])->assertFailed();
        $this->assertTrue($changed); $this->assertNull($run->fresh()->output_sha256);
        $disk->put($run->output_path, $this->png); $associationChanged = false;
        DB::listen(function ($query) use ($run, &$associationChanged) {
            if (! $associationChanged && str_contains($query->sql, 'update "iguide_lab_projects"')) {
                $associationChanged = true; IguideLabRun::whereKey($run->id)->update(['output_width' => 256]);
            }
        });
        $this->artisan('iguide-lab:verify-output-digests', ['project' => $this->project->id])->assertFailed();
        $this->assertTrue($associationChanged); $this->assertNull($run->fresh()->output_sha256);
    }

    public function test_dto_reads_only_persisted_digest_and_legacy_migration_is_reversible(): void
    {
        $run = $this->completedResult();
        $run->update(['output_sha256' => hash('sha256', $this->png)]);
        Storage::shouldReceive('disk')->never();
        $this->assertSame(hash('sha256', $this->png), app(LabOutputDigests::class)->dto($run));
        $migration = require database_path('migrations/2026_11_14_070100_add_output_digest_to_iguide_lab_runs.php');
        $migration->down(); $this->assertNull(app(LabOutputDigests::class)->dto($run->fresh()));
        $this->assertSame('completed', $run->fresh()->status); $this->assertSame(9, $this->project->fresh()->used_runs);
        $migration->up(); $this->assertNull($run->fresh()->output_sha256);
    }

    private function completedResult(): IguideLabRun
    {
        $case = IguideLabCase::create(['project_id' => $this->project->id, 'label' => 'Original', 'projection' => 'equirectangular', 'width' => 128, 'height' => 64,
            'source_sha256' => hash('sha256', $this->png), 'source_path' => 'fixture/source.png', 'source_mime' => 'image/png']);
        $id = (string) Str::uuid(); $path = 'iguide-lab/'.$this->project->id.'/runs/'.$id.'.png';
        Storage::disk(LabService::DISK)->put($path, $this->png);
        return IguideLabRun::create(['id' => $id, 'project_id' => $this->project->id, 'case_id' => $case->id, 'request_id' => (string) Str::uuid(),
            'request_hash' => str_repeat('c', 64), 'model_id' => 'gpt-image-2.6-sunburst', 'provider' => 'openai', 'preset' => 'grass', 'prompt' => 'Fixture',
            'options' => ['width' => 128, 'height' => 64, 'importedOutputSha256' => str_repeat('f', 64)], 'status' => 'completed',
            'output_path' => $path, 'output_width' => 128, 'output_height' => 64, 'completed_at' => now()]);
    }
}
