<?php

namespace Tests\Feature;

use App\Models\IguideLabCase;
use App\Models\IguideLabProject;
use App\Services\IguideLab\LabCaseCapacity;
use App\Services\IguideLab\LabSections;
use App\Services\IguideLab\LabService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class IguideLabCapacityTest extends TestCase
{
    use RefreshDatabase;
    private IguideLabProject $project;
    private string $token;
    private string $pixels;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $disk = Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK)); $disk->makeDirectory('');
        Queue::fake(); Http::preventStrayRequests();
        $this->token = str_repeat('a', 64);
        $this->project = IguideLabProject::create(['name' => 'Capacity', 'token_hash' => hash('sha256', $this->token), 'expires_at' => now()->addDay(), 'max_runs' => 120, 'used_runs' => 7]);
        $this->pixels = $this->png(128, 64);
    }

    public function test_default_limit_and_exact_upload_replay_remain_80(): void
    {
        $first = $this->upload('Original')->assertCreated()->json('data');
        $this->fillTo($this->project, 80); $files = Storage::disk(LabService::DISK)->allFiles();
        $this->upload('Original')->assertCreated()->assertJsonPath('data.id', $first['id']);
        $this->upload('New source', ['maxCases' => 160])->assertStatus(429);
        $this->assertSame(80, IguideLabCase::count()); $this->assertSame($files, Storage::disk(LabService::DISK)->allFiles());
        $this->assertSame(80, $this->project->fresh()->max_cases); $this->assertSame(7, $this->project->fresh()->used_runs);
        Http::assertNothingSent(); Queue::assertNothingPushed();
    }

    public function test_operator_extension_is_scoped_and_admits_exactly_160_records(): void
    {
        $protected = $this->project->only(['token_hash', 'expires_at', 'revoked_at', 'max_runs', 'used_runs']);
        $other = IguideLabProject::create(['name' => 'Other', 'token_hash' => str_repeat('b', 64), 'expires_at' => now()->addDay()]);
        $this->artisan('iguide-lab:set-case-limit', ['project' => $this->project->id, 'limit' => '160'])->assertSuccessful();
        $this->assertSame(160, $this->project->fresh()->max_cases); $this->assertSame(80, $other->fresh()->max_cases);
        $this->assertEquals($protected, $this->project->fresh()->only(array_keys($protected)));
        $this->fillTo($this->project, 159);
        $last = $this->upload('Last permitted')->assertCreated()->json('data');
        $this->upload('Last permitted')->assertCreated()->assertJsonPath('data.id', $last['id']);
        $files = Storage::disk(LabService::DISK)->allFiles(); $this->upload('Over limit')->assertStatus(429);
        $this->assertSame(160, IguideLabCase::where('project_id', $this->project->id)->count());
        $this->assertSame($files, Storage::disk(LabService::DISK)->allFiles()); Http::assertNothingSent();
    }

    public function test_operator_rejects_invalid_inactive_and_destructive_limit_reductions(): void
    {
        foreach (['79', '161', '160.5', 'unlimited', '-1'] as $limit)
            $this->artisan('iguide-lab:set-case-limit', ['project' => $this->project->id, 'limit' => $limit])->assertFailed();
        app(LabCaseCapacity::class)->set($this->project->id, 160); $this->fillTo($this->project, 81);
        $this->artisan('iguide-lab:set-case-limit', ['project' => $this->project->id, 'limit' => '80'])->assertFailed();
        $this->assertSame(160, $this->project->fresh()->max_cases); $this->assertSame(81, IguideLabCase::count());
        $this->project->update(['revoked_at' => now()]);
        $this->artisan('iguide-lab:set-case-limit', ['project' => $this->project->id, 'limit' => '150'])->assertFailed();
        $this->assertSame(160, $this->project->fresh()->max_cases);
    }

    public function test_native_attachments_share_capacity_and_replays_do_not_consume_more(): void
    {
        $native = $this->png(256, 128); $parents = [];
        foreach (['A', 'B', 'C'] as $name) {
            $parent = app(LabService::class)->addCase($this->project, $this->file(), ['label' => $name, 'projection' => 'equirectangular']);
            $parent->update(['metadata' => ['originalSha256' => hash('sha256', $native), 'originalDimensions' => [256, 128]]]); $parents[] = $parent;
        }
        $this->fillTo($this->project, 78); $ids = [];
        foreach (array_slice($parents, 0, 2) as $parent) {
            $request = (string) Str::uuid(); $ids[$parent->id] = $request;
            app(LabSections::class)->attachNative($this->project, $parent, UploadedFile::fake()->createWithContent('native.png', $native), $request);
        }
        $this->assertSame(80, IguideLabCase::count()); $files = Storage::disk(LabService::DISK)->allFiles();
        app(LabSections::class)->attachNative($this->project, $parents[0]->fresh(), UploadedFile::fake()->createWithContent('native.png', $native), $ids[$parents[0]->id]);
        $this->withToken($this->token)->post('/api/iguide-lab/cases/'.$parents[2]->id.'/native-source', ['requestId' => (string) Str::uuid(), 'image' => UploadedFile::fake()->createWithContent('native.png', $native)], ['Accept' => 'application/json'])->assertStatus(429);
        $this->assertNull($parents[2]->fresh()->native_source); $this->assertSame(80, IguideLabCase::count());
        $this->assertEqualsCanonicalizing($files, Storage::disk(LabService::DISK)->allFiles()); Http::assertNothingSent();
    }

    public function test_failed_case_transaction_removes_staged_source_without_consuming_capacity(): void
    {
        $this->upload('Kept')->assertCreated(); $files = Storage::disk(LabService::DISK)->allFiles();
        Event::listen('eloquent.creating: '.IguideLabCase::class, function ($case) { if ($case->label === 'Reject') throw new RuntimeException('Injected database write failure'); });
        try {
            try { app(LabService::class)->addCase($this->project, $this->file(), ['label' => 'Reject', 'projection' => 'equirectangular']); $this->fail('Expected failed transaction'); }
            catch (RuntimeException $e) { $this->assertSame('Injected database write failure', $e->getMessage()); }
        } finally { Event::forget('eloquent.creating: '.IguideLabCase::class); }
        $this->assertSame(1, IguideLabCase::count()); $this->assertSame($files, Storage::disk(LabService::DISK)->allFiles());
        $this->assertSame(7, $this->project->fresh()->used_runs);
    }

    public function test_invalid_capacity_fails_closed_and_auth_size_guards_are_unchanged(): void
    {
        $this->project->update(['max_cases' => 161]); $this->upload('Invalid configuration')->assertStatus(503);
        $this->project->update(['max_cases' => 160]);
        $this->withHeader('Authorization', '')->post('/api/iguide-lab/cases', ['label' => 'No auth', 'projection' => 'equirectangular', 'image' => $this->file()], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->withToken($this->token)->post('/api/iguide-lab/cases', ['label' => 'Too large', 'projection' => 'equirectangular', 'image' => $this->file()->size(30 * 1024 + 1)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->project->update(['expires_at' => now()->subSecond()]); $this->upload('Expired')->assertUnauthorized();
        $this->assertSame(0, IguideLabCase::count()); Http::assertNothingSent();
    }

    public function test_capacity_migration_rollback_preserves_existing_records_and_legacy_default(): void
    {
        $case = $this->upload('Preserved')->assertCreated()->json('data');
        app(LabCaseCapacity::class)->set($this->project->id, 160);
        $migration = require database_path('migrations/2026_11_14_070000_add_case_limit_to_iguide_lab_projects.php');
        $migration->down();
        $this->assertSame(80, app(LabCaseCapacity::class)->limit($this->project->fresh()));
        $this->assertSame($case['sourceSha256'], IguideLabCase::findOrFail($case['id'])->source_sha256);
        $this->assertSame(7, $this->project->fresh()->used_runs);
        $migration->up(); $this->assertSame(80, $this->project->fresh()->max_cases);
    }

    private function upload(string $label, array $extra = [])
    {
        return $this->withToken($this->token)->post('/api/iguide-lab/cases', ['label' => $label, 'projection' => 'equirectangular', 'image' => $this->file()] + $extra, ['Accept' => 'application/json']);
    }
    private function file(): UploadedFile { return UploadedFile::fake()->createWithContent('source.png', $this->pixels); }
    private function fillTo(IguideLabProject $project, int $count): void
    {
        $rows = [];
        for ($i = IguideLabCase::where('project_id', $project->id)->count(); $i < $count; $i++) $rows[] = ['id' => (string) Str::uuid(), 'project_id' => $project->id, 'label' => 'Existing '.$i, 'projection' => 'equirectangular', 'width' => 128, 'height' => 64, 'source_sha256' => hash('sha256', $this->pixels), 'source_path' => 'fixture/existing.png', 'source_mime' => 'image/png', 'created_at' => now(), 'updated_at' => now()];
        if ($rows) IguideLabCase::insert($rows);
    }
    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height); imagefill($image, 0, 0, imagecolorallocate($image, 110, 130, 150)); ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image); return $bytes;
    }
}
