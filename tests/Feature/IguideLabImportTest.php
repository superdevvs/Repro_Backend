<?php

namespace Tests\Feature;

use App\Jobs\ProcessIguideLabImport;
use App\Jobs\AssembleIguideLabImport;
use App\Jobs\ProcessIguideLabRun;
use App\Models\IguideLabCase;
use App\Models\IguideLabImport;
use App\Models\IguideLabImportUpload;
use App\Models\IguideLabProject;
use App\Services\IguideLab\LabImportNetwork;
use App\Services\IguideLab\LabImports;
use App\Services\IguideLab\LabImportUploads;
use App\Services\IguideLab\LabImportUploadLock;
use App\Services\IguideLab\LabStorage;
use App\Services\IguideLab\LabService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class IguideLabImportTest extends TestCase
{
    use RefreshDatabase;
    private IguideLabProject $project;
    private string $token;
    private string $image;

    protected function setUp(): void
    {
        parent::setUp(); config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $disk = Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK)); $disk->makeDirectory(''); $disk->setVisibility('', 'private');
        Queue::fake(); Http::preventStrayRequests(); $this->token = str_repeat('a', 64);
        $this->project = IguideLabProject::create(['name' => 'Imports', 'token_hash' => hash('sha256', $this->token), 'expires_at' => now()->addDay(), 'max_runs' => 20]);
        $image = imagecreatetruecolor(2048, 1024); imagefill($image, 0, 0, imagecolorallocate($image, 40, 80, 120)); ob_start(); imagepng($image); $this->image = ob_get_clean(); imagedestroy($image);
    }

    public function test_private_generic_import_replay_and_source_dedup_are_atomic_and_free(): void
    {
        $archive = $this->zip(['room.png' => $this->image]); $requestId = (string) Str::uuid();
        $first = $this->reserve($archive, $requestId); $record = IguideLabImport::findOrFail($first['id']);
        $this->assertSame('queued', $first['status']); $this->assertSame([], $first['sources']);
        $this->assertSame($first['id'], $this->reserve($archive, $requestId)['id']);
        $this->process($record); $source = $record->fresh()->sources[0]; $case = IguideLabCase::findOrFail($source['caseId']);
        $this->assertSame('completed', $record->fresh()->status); $this->assertNull($source['roomId']); $this->assertNull($source['panoId']);
        $this->assertSame(hash('sha256', $this->image), $source['sourceSha256']); $this->assertSame($this->image, Storage::disk(LabService::DISK)->get($case->source_path));
        $this->assertSame('scan-import', $case->metadata['serverNativeAuthority']['kind']);
        // This disk intentionally maps both Flysystem labels to0660; check the
        // actual private Unix permissions instead of the ambiguous label.
        $this->assertSame(0660, fileperms(Storage::disk(LabService::DISK)->path($case->source_path)) & 0777);
        $this->process($record); $second = IguideLabImport::findOrFail($this->reserve($archive)['id']); $this->process($second);
        $this->assertSame($case->id, $second->fresh()->sources[0]['caseId']); $this->assertTrue($second->fresh()->sources[0]['reused']); $this->assertSame(1, IguideLabCase::count());
        $this->assertSame(0, $this->project->fresh()->used_runs); Queue::assertPushed(ProcessIguideLabImport::class, 2); Queue::assertNotPushed(ProcessIguideLabRun::class); Http::assertNothingSent();
        $this->assertFalse(Storage::disk(LabService::DISK)->exists($record->private_input['archivePath']));
    }

    public function test_room_keys_come_from_iguide_ids_and_unknown_room_is_not_guessed(): void
    {
        $data = $this->viewerFixture(); $data['dataV2']['buildings'][0]['floors'][0]['panos'][] = ['id' => 2, 'roomId' => null];
        $archive = $this->zip(['tour/spheres/p1-s.jpg' => $this->image, 'tour/spheres/p2-s.jpg' => $this->image,
            'tour/index.html' => '<script>throw new Error("do not execute"); const viewerParams={viewObject:'.json_encode($data).'};</script>']);
        $record = IguideLabImport::find($this->reserve($archive)['id']); $this->process($record); $sources = $record->fresh()->sources;
        $this->assertCount(2, $sources); $this->assertSame('Kitchen', $sources[0]['roomName']); $this->assertSame('7', $sources[0]['originalRoomId']);
        $this->assertStringStartsWith('scan:', $sources[0]['roomId']); $this->assertNull($sources[1]['roomId']); $this->assertNull($sources[1]['roomName']);
        $this->assertSame('iguide_viewObject', $sources[0]['metadataAuthority']);
        $this->assertEquals(['pos' => [1.5, 2.0]], IguideLabCase::find($sources[0]['caseId'])->metadata['camera']);
    }

    public function test_conflicting_request_and_cross_project_access_are_rejected(): void
    {
        $id = (string) Str::uuid(); $first = $this->reserve($this->zip(['a.png' => $this->image]), $id);
        $this->withToken($this->token)->post('/api/iguide-lab/imports', ['requestId' => $id, 'archive' => $this->zip(['b.png' => $this->image])], ['Accept' => 'application/json'])->assertConflict();
        $other = str_repeat('b', 64); IguideLabProject::create(['name' => 'Other', 'token_hash' => hash('sha256', $other), 'expires_at' => now()->addDay(), 'max_runs' => 10]);
        $this->withToken($other)->getJson('/api/iguide-lab/imports/'.$first['id'])->assertNotFound();
        $this->withToken($other)->getJson('/api/iguide-lab/imports')->assertJsonPath('data', []);
    }

    public function test_two_queued_imports_cannot_overfill_capacity_and_failed_staging_is_removed(): void
    {
        $this->fillCases(79);
        $one = IguideLabImport::find($this->reserve($this->zip(['one.png' => $this->image]))['id']);
        $two = IguideLabImport::find($this->reserve($this->zip(['two.png' => $this->image]))['id']);
        $this->process($one); $this->process($two);
        $this->assertSame('completed', $one->fresh()->status); $this->assertSame('needs_attention', $two->fresh()->status); $this->assertSame(80, IguideLabCase::count());
        $this->assertSame([], Storage::disk(LabService::DISK)->files('iguide-lab/'.$this->project->id.'/imports/'.$two->id));
    }

    public function test_invalid_member_prevents_any_partial_case_publication(): void
    {
        foreach ([['ok.png' => $this->image, '../escape.png' => $this->image], ['ok.png' => $this->image, 'bad.jpg' => 'not an image'],
            ['a.png' => $this->image, 'A.png' => $this->image], ['ok.png' => $this->image, 'execute.php' => '<?php die();'],
            ['index.html' => '<script>viewObject: getData()</script>', 'spheres/p1-s.jpg' => $this->image]] as $entries) {
            $record = IguideLabImport::find($this->reserve($this->zip($entries))['id']); $this->process($record);
            $this->assertSame('needs_attention', $record->fresh()->status); $this->assertSame(0, IguideLabCase::count());
            $this->assertFalse(Storage::disk(LabService::DISK)->exists($record->private_input['archivePath']));
        }
    }

    public function test_atomic_import_uses_shared_project_capacity_without_changing_paid_quota(): void
    {
        $this->project->update(['max_cases' => 81]); $this->fillCases(79);
        $record = IguideLabImport::findOrFail($this->reserve($this->zip(['one.png' => $this->image, 'two.png' => $this->image]))['id']);
        $this->process($record);
        $this->assertSame('completed', $record->fresh()->status); $this->assertCount(2, $record->fresh()->sources);
        $this->assertSame(81, IguideLabCase::count()); $this->assertSame(0, $this->project->fresh()->used_runs);
        $this->assertSame(20, $this->project->fresh()->max_runs); Http::assertNothingSent();
    }

    public function test_changed_private_archive_is_not_imported(): void
    {
        $record = IguideLabImport::find($this->reserve($this->zip(['room.png' => $this->image]))['id']);
        Storage::disk(LabService::DISK)->put($record->private_input['archivePath'], 'changed'); $this->process($record);
        $this->assertSame('needs_attention', $record->fresh()->status); $this->assertSame(0, IguideLabCase::count());
    }

    public function test_link_keeps_token_private_and_uses_only_fixed_tour_and_sphere_routes(): void
    {
        $fixture = $this->zip(['sample/spheres/p1-s.jpg' => $this->image]); $archive = file_get_contents($fixture->getRealPath());
        $view = '<script>const viewerParams={viewObject:'.json_encode($this->viewerFixture()).'};</script>';
        $this->mock(LabImportNetwork::class, function ($mock) use ($archive, $view) {
            $mock->makePartial(); $mock->shouldReceive('download')->twice()->andReturnUsing(function ($url, $target, $limit) use ($archive, $view) {
                $this->assertContains($url, ['https://youriguide.com/sample/?accessToken=secret', 'https://youriguide.com/sample/doc/spheres.zip?accessToken=secret']);
                $bytes = str_contains($url, 'spheres.zip') ? $archive : $view; file_put_contents($target, $bytes);
                return ['sha256' => hash('sha256', $bytes), 'sizeBytes' => strlen($bytes)];
            });
        });
        $first = $this->withToken($this->token)->postJson('/api/iguide-lab/imports', ['requestId' => (string) Str::uuid(), 'url' => 'https://youriguide.com/sample/?accessToken=secret'])->assertAccepted()->json('data');
        $this->assertStringNotContainsString('secret', json_encode($first));
        $this->assertStringNotContainsString('secret', \Illuminate\Support\Facades\DB::table('iguide_lab_imports')->where('id', $first['id'])->value('private_input'));
        $record = IguideLabImport::find($first['id']); $this->process($record);
        $this->assertSame('completed', $record->fresh()->status); $this->assertSame('https://youriguide.com/sample/', IguideLabCase::first()->tour_url);
        $this->assertStringNotContainsString('secret', json_encode(app(LabImports::class)->dto($record->fresh())));
    }

    public function test_chunked_archive_resumes_verifies_assembly_and_queues_exactly_one_import(): void
    {
        $file = $this->zip(['sphere.png' => $this->image, 'inert.dat' => str_repeat('a', LabImportUploads::CHUNK_BYTES + 100)]); $bytes = file_get_contents($file->getRealPath());
        $body = ['requestId' => (string) Str::uuid(), 'importRequestId' => (string) Str::uuid(), 'filename' => 'scan.zip', 'sizeBytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        $first = $this->withToken($this->token)->postJson('/api/iguide-lab/import-uploads', $body)->assertCreated()->json('data');
        $this->withToken($this->token)->postJson('/api/iguide-lab/import-uploads', $body)->assertCreated()->assertJsonPath('data.id', $first['id']);
        $this->withToken($this->token)->postJson('/api/iguide-lab/import-uploads/'.$first['id'].'/finalize')->assertUnprocessable();
        foreach (str_split($bytes, LabImportUploads::CHUNK_BYTES) as $index => $part) {
            $range = 'bytes '.($index * LabImportUploads::CHUNK_BYTES).'-'.($index * LabImportUploads::CHUNK_BYTES + strlen($part) - 1).'/'.strlen($bytes);
            for ($repeat = 0; $repeat < 2; $repeat++) $this->call('PUT', '/api/iguide-lab/import-uploads/'.$first['id'].'/chunks/'.$index, [], [], [],
                ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/octet-stream',
                    'HTTP_CONTENT_RANGE' => $range, 'HTTP_X_CHUNK_SHA256' => hash('sha256', $part)], $part)->assertOk();
        }
        $this->withToken($this->token)->getJson('/api/iguide-lab/import-uploads/'.$first['id'])->assertOk()->assertJsonPath('data.receivedBytes', strlen($bytes))->assertJsonPath('data.receivedChunks', [0, 1]);
        for ($i = 0; $i < 2; $i++) $this->withToken($this->token)->postJson('/api/iguide-lab/import-uploads/'.$first['id'].'/finalize')->assertAccepted();
        Queue::assertPushed(AssembleIguideLabImport::class, 1);
        $upload = IguideLabImportUpload::findOrFail($first['id']); app(LabImportUploads::class)->assemble($upload); app(LabImportUploads::class)->assemble($upload->fresh());
        $this->assertSame('completed', $upload->fresh()->status); $this->assertSame(1, IguideLabImport::count()); Queue::assertPushed(ProcessIguideLabImport::class, 1);
        $record = IguideLabImport::findOrFail($upload->fresh()->import_id); $this->assertSame($body['sha256'], $record->private_input['archiveSha256']);
        $this->process($record); $this->assertSame('completed', $record->fresh()->status); $this->assertSame(1, IguideLabCase::count());
        app(LabImportUploads::class)->fail($upload->fresh(), 'Late duplicate worker failure');
        $this->assertSame('completed', $upload->fresh()->status); $this->assertSame($record->id, $upload->fresh()->import_id);
        $this->assertSame([], Storage::disk(LabService::DISK)->files('iguide-lab/'.$this->project->id.'/import-uploads/'.$upload->id)); Http::assertNothingSent();
    }

    public function test_chunk_hash_replacement_corruption_and_expiry_do_not_publish_sources(): void
    {
        $service = app(LabImportUploads::class); $bytes = 'short fixture';
        $upload = $service->start($this->project, ['requestId' => (string) Str::uuid(), 'importRequestId' => (string) Str::uuid(), 'filename' => 'source.zip', 'sizeBytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
        $stream = fopen('php://temp', 'w+b'); fwrite($stream, $bytes); rewind($stream);
        $service->chunk($upload, 0, 'bytes 0-12/13', hash('sha256', $bytes), $stream); fclose($stream);
        $stream = fopen('php://temp', 'w+b'); fwrite($stream, str_repeat('x', 13)); rewind($stream);
        try { $service->chunk($upload->fresh(), 0, 'bytes 0-12/13', hash('sha256', str_repeat('x', 13)), $stream); $this->fail('A chunk was replaced'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(409, $e->getStatusCode()); } finally { fclose($stream); }
        $service->finalize($upload->fresh(), null);
        $path = 'iguide-lab/'.$this->project->id.'/import-uploads/'.$upload->id.'/0-'.hash('sha256', $bytes).'.part'; Storage::disk(LabService::DISK)->put($path, 'corrupt');
        $service->assemble($upload->fresh()); $this->assertSame('needs_attention', $upload->fresh()->status); $this->assertSame(0, IguideLabImport::count());
        $second = $service->start($this->project, ['requestId' => (string) Str::uuid(), 'importRequestId' => (string) Str::uuid(), 'filename' => 'source.zip', 'sizeBytes' => 1, 'sha256' => hash('sha256', 'a')]);
        $second->update(['expires_at' => now()->subMinute()]); $this->assertSame(1, $service->prune($this->project->id)); $this->assertSame('expired', $second->fresh()->status);
    }

    public function test_reused_case_tampering_is_detected_before_publication(): void
    {
        $archive = $this->zip(['source.png' => $this->image]); $first = IguideLabImport::find($this->reserve($archive)['id']); $this->process($first);
        $case = IguideLabCase::first(); Storage::disk(LabService::DISK)->put($case->source_path, 'changed');
        $second = IguideLabImport::find($this->reserve($archive)['id']); $this->process($second);
        $this->assertSame('needs_attention', $second->fresh()->status); $this->assertSame(1, IguideLabCase::count());
    }

    public function test_concurrent_retry_cannot_enter_publication_window_or_delete_canonical_chunk(): void
    {
        if (! function_exists('pcntl_fork')) $this->markTestSkipped('The Linux worker lock test requires pcntl.');
        $service = app(LabImportUploads::class); $bytes = 'short fixture'; $sha = hash('sha256', $bytes);
        $upload = $service->start($this->project, ['requestId' => (string) Str::uuid(), 'importRequestId' => (string) Str::uuid(), 'filename' => 'source.zip', 'sizeBytes' => 13, 'sha256' => $sha]);
        app(LabStorage::class)->prepareImportUpload($upload->project_id, $upload->id); $disk = Storage::disk(LabService::DISK);
        $path = 'iguide-lab/'.$this->project->id.'/import-uploads/'.$upload->id.'/0-'.$sha.'.part'; $signal = tempnam(sys_get_temp_dir(), 'lock-proof-'); chmod($signal, 0660);
        // Exact former race window: canonical bytes exist but no chunk row committed.
        app(LabImportUploadLock::class)->run($upload, function () use ($upload, $disk, $path, $bytes, $signal) {
            $disk->put($path, $bytes, ['visibility' => 'private']); $this->assertSame([], $upload->fresh()->chunks);
            $pid = pcntl_fork(); $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                // A distinct Unix owner sharing the private group must open the
                // same inode without attempting chmod on the original owner.
                if (function_exists('posix_geteuid') && posix_geteuid() === 0 && ! posix_seteuid(33)) exit(2);
                $entered = app(LabImportUploadLock::class)->run($upload, function () use ($disk, $path) { $disk->delete($path); return true; }, false);
                file_put_contents($signal, $entered === null ? 'blocked' : 'entered'); exit(0);
            }
            pcntl_waitpid($pid, $status); $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertSame('blocked', file_get_contents($signal)); $this->assertSame($bytes, $disk->get($path));
        });
        unlink($signal);
        // The canonical file then commits and exact retry converges on the same row.
        for ($i = 0; $i < 2; $i++) { $stream = fopen('php://temp', 'w+b'); fwrite($stream, $bytes); rewind($stream); $service->chunk($upload->fresh(), 0, 'bytes 0-12/13', $sha, $stream); fclose($stream); }
        $this->assertCount(1, $upload->fresh()->chunks); $this->assertSame($sha, hash_file('sha256', $disk->path($path)));
        $upload->update(['expires_at' => now()->subMinute()]);
        app(LabImportUploadLock::class)->run($upload, function () use ($service, $disk, $path, $upload) {
            $this->assertSame(0, $service->prune($upload->project_id)); $this->assertTrue($disk->exists($path)); $this->assertSame('uploading', $upload->fresh()->status);
        });
        $this->assertSame(1, $service->prune($upload->project_id)); $this->assertFalse($disk->exists($path));
        $this->assertTrue($disk->exists('iguide-lab/'.$this->project->id.'/import-upload-locks/'.$upload->id.'.lock'));
    }

    public function test_import_worker_timeout_lock_and_queue_retry_budget_are_ordered(): void
    {
        foreach ([new ProcessIguideLabImport((string) Str::uuid()), new AssembleIguideLabImport((string) Str::uuid())] as $job) {
            $this->assertSame(600, $job->timeout); $lock = $job->middleware()[0];
            $this->assertSame(660, $lock->expiresAfter); $this->assertGreaterThan($job->timeout, $lock->expiresAfter);
            $this->assertGreaterThan($lock->expiresAfter, config('queue.connections.studio.retry_after'));
        }
    }

    private function process(IguideLabImport $record): void { app(LabImports::class)->process($record); }
    private function reserve(UploadedFile $archive, ?string $id = null): array
    {
        return $this->withToken($this->token)->post('/api/iguide-lab/imports', ['requestId' => $id ?? (string) Str::uuid(), 'archive' => $archive], ['Accept' => 'application/json'])->assertAccepted()->json('data');
    }
    private function zip(array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'scan-'); $zip = new ZipArchive; $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $bytes) { $zip->addFromString($name, $bytes); $zip->setCompressionName($name, ZipArchive::CM_STORE); }
        $zip->close(); $file = UploadedFile::fake()->createWithContent('scan.zip', file_get_contents($path)); unlink($path); return $file;
    }
    private function viewerFixture(): array
    {
        return ['id' => 'view1', 'iguide' => ['id' => 'ig1', 'alias' => 'sample'], 'dataV2' => ['buildings' => [['id' => 1, 'floors' => [['id' => 1, 'name' => 'Ground',
            'layout' => ['rooms' => [['id' => 7, 'name' => 'Kitchen']]], 'panos' => [['id' => 1, 'roomId' => 7, 'pos' => [1.5, 2]]]]]]]]];
    }
    private function fillCases(int $count): void
    {
        for ($i = 0; $i < $count; $i++) IguideLabCase::create(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'label' => 'Existing '.$i,
            'projection' => 'equirectangular', 'width' => 2048, 'height' => 1024, 'source_sha256' => hash('sha256', 'existing'.$i), 'source_path' => 'existing/'.$i, 'source_mime' => 'image/png']);
    }
}

