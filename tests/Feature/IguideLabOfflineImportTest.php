<?php

namespace Tests\Feature;

use App\Jobs\ProcessIguideLabImport;
use App\Models\IguideLabCase;
use App\Models\IguideLabImport;
use App\Models\IguideLabProject;
use App\Services\IguideLab\LabImportArchive;
use App\Services\IguideLab\LabImportNetwork;
use App\Services\IguideLab\LabImports;
use App\Services\IguideLab\LabService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

/** iGUIDE offline packages (offline_en.zip): <alias>/index.html viewerParams.viewObject + <alias>/assets/p<id>-s_4096x.jpg. */
class IguideLabOfflineImportTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ0b3VyIn0.secret-tok_en';
    private const LINK = 'https://youriguide.com/sample/doc/offline_en.zip?authtoken='.self::TOKEN;
    private static array $images = [];
    private IguideLabProject $project;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp(); config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $disk = Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK)); $disk->makeDirectory(''); $disk->setVisibility('', 'private');
        Queue::fake(); Http::preventStrayRequests(); $this->token = str_repeat('a', 64);
        $this->project = IguideLabProject::create(['name' => 'Offline', 'token_hash' => hash('sha256', $this->token), 'expires_at' => now()->addDay(), 'max_runs' => 20]);
    }

    public function test_offline_package_inventory_uses_the_package_equirects_with_room_and_floor_names(): void
    {
        $path = $this->package(); $inventory = app(LabImportArchive::class)->inventory($path);
        $this->assertSame('iguide_viewObject', $inventory['format']); $this->assertSame('ig1', $inventory['tourId']); $this->assertSame('sample', $inventory['alias']);
        $this->assertSame(hash('sha256', $this->index()), $inventory['metadataSha256']);
        $this->assertSame(['sample/assets/p1-s_4096x.jpg', 'sample/assets/p2-s_4096x.jpg'], array_column($inventory['sources'], 'entry'));
        $this->assertSame(['1', '2'], array_column($inventory['sources'], 'panoId'));
        $this->assertSame(['Kitchen', 'Primary Bedroom'], array_column($inventory['sources'], 'roomName'));
        $this->assertSame(['Main Floor', 'Upper Floor'], array_column($inventory['sources'], 'floorName'));
        $this->assertSame([[4096, 2048], [4096, 2048]], array_map(fn ($s) => [$s['width'], $s['height']], $inventory['sources']));
        $this->assertSame([], $inventory['warnings']); unlink($path);
    }

    public function test_offline_package_uploaded_as_zip_imports_both_sources(): void
    {
        $archive = UploadedFile::fake()->createWithContent('offline_en.zip', file_get_contents($path = $this->package())); unlink($path);
        $record = IguideLabImport::findOrFail($this->withToken($this->token)->post('/api/iguide-lab/imports', ['requestId' => (string) Str::uuid(), 'archive' => $archive], ['Accept' => 'application/json'])->assertAccepted()->json('data.id'));
        app(LabImports::class)->process($record); $record->refresh();
        $this->assertSame('completed', $record->status, (string) $record->error); $this->assertSame('iguide_viewObject', $record->audit['format']);
        $this->assertSame(['Kitchen', 'Primary Bedroom'], array_column($record->sources, 'roomName'));
        $this->assertSame(2, IguideLabCase::count()); Http::assertNothingSent();
    }

    public function test_offline_link_downloads_only_the_package_and_keeps_its_token_private(): void
    {
        $bytes = file_get_contents($path = $this->package()); unlink($path); $this->fakeDownload($bytes);
        $requestId = (string) Str::uuid();
        $first = $this->withToken($this->token)->postJson('/api/iguide-lab/imports', ['requestId' => $requestId, 'url' => self::LINK])->assertAccepted()->json('data');
        $this->assertSame('link', $first['sourceKind']); $this->assertSame('https://youriguide.com/sample/', $first['audit']['sourceUrl']);
        $this->assertSame($first['id'], $this->withToken($this->token)->postJson('/api/iguide-lab/imports', ['requestId' => $requestId, 'url' => self::LINK])->assertAccepted()->json('data.id'));
        $this->withToken($this->token)->postJson('/api/iguide-lab/imports', ['requestId' => $requestId, 'url' => self::LINK.'x'])->assertConflict();
        $this->withToken($this->token)->postJson('/api/iguide-lab/imports', ['requestId' => (string) Str::uuid(), 'url' => self::LINK.'&pano=1'])->assertUnprocessable();
        Queue::assertPushed(ProcessIguideLabImport::class, 1);
        $record = IguideLabImport::findOrFail($first['id']); app(LabImports::class)->process($record); $record->refresh();
        $this->assertSame('completed', $record->status, (string) $record->error);
        $this->assertSame(['Kitchen', 'Primary Bedroom'], array_column($record->sources, 'roomName'));
        $this->assertSame(['Main Floor', 'Upper Floor'], array_column($record->sources, 'floorName'));
        $this->assertSame('https://youriguide.com/sample/', IguideLabCase::first()->tour_url);
        $this->assertNull($record->private_input['metadataPath']);
        $dto = app(LabImports::class)->dto($record);
        $this->assertSame(['id', 'requestId', 'sourceKind', 'status', 'error', 'audit', 'sources', 'createdAt', 'completedAt'], array_keys($dto));
        $this->assertStringNotContainsString(self::TOKEN, json_encode($dto));
        $this->assertStringNotContainsString(self::TOKEN, DB::table('iguide_lab_imports')->where('id', $first['id'])->value('private_input'));
        $this->assertFalse(Storage::disk(LabService::DISK)->exists($record->private_input['archivePath']));
    }

    public function test_offline_link_rejects_a_package_or_archive_for_another_source(): void
    {
        $other = $this->package('other'); $spheres = $this->zip(['sample/spheres/p1-s.jpg' => $this->image(1), 'sample/index.html' => $this->index()]);
        foreach ([$other, $spheres] as $path) {
            $this->fakeDownload(file_get_contents($path)); unlink($path);
            $record = IguideLabImport::findOrFail($this->withToken($this->token)->postJson('/api/iguide-lab/imports', ['requestId' => (string) Str::uuid(), 'url' => self::LINK])->assertAccepted()->json('data.id'));
            app(LabImports::class)->process($record); $record->refresh();
            $this->assertSame('needs_attention', $record->status); $this->assertSame('The downloaded iGUIDE package does not match the requested tour.', $record->error);
        }
        $this->assertSame(0, IguideLabCase::count());
    }

    public function test_package_folder_must_match_its_tour_metadata(): void
    {
        $path = $this->package('sample', $this->index('other'));
        $this->assertRejected($path, 'The iGUIDE package folder does not match its tour metadata.');
    }

    public function test_offline_package_without_panorama_assets_is_rejected_with_a_clear_message(): void
    {
        $path = $this->zip(['sample/index.html' => $this->index(), 'sample/assets/p1-0.jpg' => $this->image(1, 64, 64), 'sample/html_assets/index.js' => 'void 0;']);
        $this->assertRejected($path, 'This iGUIDE offline package has no full panorama images (assets/p<id>-s_4096x.jpg). Download the complete offline package again.');
    }

    public function test_offline_package_asset_without_tour_metadata_is_rejected(): void
    {
        $path = $this->package('sample', null, ['sample/assets/p3-s_4096x.jpg' => $this->image(3)]);
        $this->assertRejected($path, 'An exported sphere cannot be matched to its iGUIDE metadata.');
    }

    public function test_expired_tour_package_imports_its_equirects_by_pano_id_without_room_data(): void
    {
        // An expired or access-denied tour still ships its equirects, but iGUIDE strips dataV2 to {formatVersion} (no buildings).
        $path = $this->package('sample', $this->expiredIndex()); $inventory = app(LabImportArchive::class)->inventory($path, null, 'sample'); unlink($path);
        $this->assertSame('iguide_spheres', $inventory['format']); $this->assertSame('ig1', $inventory['tourId']); $this->assertSame('sample', $inventory['alias']);
        $this->assertSame('1 Main St Town', $inventory['propertyName']); $this->assertSame(hash('sha256', $this->expiredIndex()), $inventory['metadataSha256']);
        $this->assertSame(['sample/assets/p1-s_4096x.jpg', 'sample/assets/p2-s_4096x.jpg'], array_column($inventory['sources'], 'entry'));
        $this->assertSame(['1', '2'], array_column($inventory['sources'], 'panoId'));
        $this->assertSame([null, null], array_column($inventory['sources'], 'roomName')); $this->assertSame([null, null], array_column($inventory['sources'], 'floorName'));
        $this->assertSame(['iguide_export_filename', 'iguide_export_filename'], array_column($inventory['sources'], 'authority'));
        $this->assertSame([[4096, 2048], [4096, 2048]], array_map(fn ($s) => [$s['width'], $s['height']], $inventory['sources']));
        $this->assertSame(['Some panoramas have no authoritative room assignment.', 'This iGUIDE tour is expired or restricted, so its room and floor names are unavailable.'], $inventory['warnings']);
    }

    public function test_expired_tour_package_imports_by_link(): void
    {
        $bytes = file_get_contents($path = $this->package('sample', $this->expiredIndex())); unlink($path); $this->fakeDownload($bytes);
        $record = IguideLabImport::findOrFail($this->withToken($this->token)->postJson('/api/iguide-lab/imports', ['requestId' => (string) Str::uuid(), 'url' => self::LINK])->assertAccepted()->json('data.id'));
        app(LabImports::class)->process($record); $record->refresh();
        $this->assertSame('completed', $record->status, (string) $record->error); $this->assertSame('iguide_spheres', $record->audit['format']);
        $this->assertSame(['1', '2'], array_column($record->sources, 'panoId')); $this->assertSame([null, null], array_column($record->sources, 'roomName'));
        $this->assertSame(['iguide_export_filename', 'iguide_export_filename'], array_column($record->sources, 'metadataAuthority'));
        $dto = app(LabImports::class)->dto($record);
        $this->assertSame(['id', 'requestId', 'sourceKind', 'status', 'error', 'audit', 'sources', 'createdAt', 'completedAt'], array_keys($dto));
        $this->assertSame('https://youriguide.com/sample/', $dto['audit']['sourceUrl']); $this->assertStringNotContainsString(self::TOKEN, json_encode($dto));
        $this->assertSame(2, IguideLabCase::count());
    }

    public function test_expired_tour_package_must_still_match_its_folder_and_the_link(): void
    {
        $this->assertRejected($this->package('sample', $this->expiredIndex('other')), 'The iGUIDE package folder does not match its tour metadata.');
        $path = $this->package('other', $this->expiredIndex('other'));
        try { app(LabImportArchive::class)->inventory($path, null, 'sample'); $this->fail('Inventory accepted the package.'); }
        catch (ValidationException $error) { $this->assertSame('The downloaded iGUIDE package does not match the requested tour.', $error->errors()['source'][0]); }
        finally { unlink($path); }
    }

    public function test_viewer_without_buildings_is_still_rejected_unless_it_is_an_expired_offline_package(): void
    {
        $message = 'Unsupported iGUIDE metadata: dataV2 buildings are required.';
        // A shell viewer that does not say the tour expired.
        $shell = $this->index('sample', ['id' => 'view1', 'iguide' => ['id' => 'ig1', 'alias' => 'sample'], 'dataV2' => ['formatVersion' => 51], 'attrs' => []]);
        $this->assertRejected($this->package('sample', $shell), $message);
        // Expired viewers outside the offline package shape keep the previous behaviour.
        $this->assertRejected($this->zip(['sample/index.html' => $this->expiredIndex(), 'sample/spheres/p1-s.jpg' => $this->image(1)]), $message);
        $this->assertRejected($this->zip(['index.html' => $this->expiredIndex(), 'assets/p1-s_4096x.jpg' => $this->image(1)]), $message);
        $this->assertRejected($this->zip(['sample/index.html' => $this->expiredIndex(), 'sample/assets/p1-0.jpg' => $this->image(1, 64, 64)]), $message);
        try { app(\App\Services\IguideLab\LabImportMetadata::class)->parse($this->expiredIndex()); $this->fail('Parsed an expired viewer as metadata.'); }
        catch (ValidationException $error) { $this->assertSame($message, $error->errors()['source'][0]); }
    }

    private function assertRejected(string $path, string $message): void
    {
        try { app(LabImportArchive::class)->inventory($path); $this->fail('Inventory accepted the package.'); }
        catch (ValidationException $error) { $this->assertSame($message, $error->errors()['source'][0]); }
        finally { unlink($path); }
    }

    private function fakeDownload(string $bytes): void
    {
        $this->mock(LabImportNetwork::class, function ($mock) use ($bytes) {
            $mock->makePartial(); $mock->shouldReceive('download')->once()->andReturnUsing(function ($url, $target, $limit) use ($bytes) {
                $this->assertSame(self::LINK, $url); $this->assertSame(1024 * 1024 * 1024, $limit); file_put_contents($target, $bytes);
                return ['sha256' => hash('sha256', $bytes), 'sizeBytes' => strlen($bytes)];
            });
        });
    }

    private function package(string $alias = 'sample', ?string $index = null, array $extra = []): string
    {
        return $this->zip([$alias.'/index.html' => $index ?? $this->index($alias), $alias.'/html_assets/index.js' => 'window.viewer = {viewObject: null};',
            $alias.'/assets/p1-s_4096x.jpg' => $this->image(1), $alias.'/assets/p1-0.jpg' => $this->image(1, 64, 64),
            $alias.'/assets/p2-s_4096x.jpg' => $this->image(2), $alias.'/doc/floor_imperial_en_1.svg' => '<svg viewBox="0 0 10 10"></svg>'] + $extra);
    }

    private function expiredIndex(string $alias = 'sample'): string
    {
        return $this->index($alias, ['id' => 'view1', 'iguide' => ['id' => 'ig1', 'alias' => $alias, 'property' => ['house' => '1', 'street' => 'Main St', 'city' => 'Town']],
            'updated_at' => '2024-02-21T00:00:00Z', 'data' => new \stdClass, 'dataV2' => ['formatVersion' => 51], 'attrs' => ['accessDenied' => true, 'expired' => true]]);
    }

    private function index(string $alias = 'sample', ?array $view = null): string
    {
        $view ??= ['id' => 'view1', 'iguide' => ['id' => 'ig1', 'alias' => $alias, 'property' => ['house' => '1', 'street' => 'Main St', 'city' => 'Town']],
            'dataV2' => ['buildings' => [['id' => 1, 'floors' => [
                ['id' => 1, 'name' => 'Main Floor', 'layout' => ['rooms' => [['id' => 7, 'name' => 'Kitchen']], 'panos' => [['id' => 1, 'roomId' => 7, 'pos' => [1.5, 2], 'rot' => 0.5]]]],
                ['id' => 2, 'name' => 'Upper Floor', 'layout' => ['rooms' => [['id' => 8, 'name' => 'Primary Bedroom']], 'panos' => [['id' => 2, 'roomId' => 8, 'pos' => [3, 4], 'rot' => 1]]]]]]]]];
        return '<!doctype html><html><body><script>window.PlanitarHasGyro=!1</script> <script src="./html_assets/index.js"></script> <script> const viewerParams = {'
            ."\n    bannerObject: null,\n    viewObject: ".json_encode($view).",\n    tags: [],\n};\n new Viewer(viewerParams);</script></body></html>";
    }

    private function image(int $seed, int $width = 4096, int $height = 2048): string
    {
        return self::$images[$seed.'-'.$width] ??= (function () use ($seed, $width, $height) {
            $image = imagecreatetruecolor($width, $height); imagefill($image, 0, 0, imagecolorallocate($image, 30 * $seed, 80, 120));
            ob_start(); imagejpeg($image, null, 80); $bytes = ob_get_clean(); imagedestroy($image); return $bytes;
        })();
    }

    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'offline-'); $zip = new ZipArchive; $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $bytes) { $zip->addFromString($name, $bytes); $zip->setCompressionName($name, ZipArchive::CM_STORE); }
        $zip->close(); return $path;
    }
}
