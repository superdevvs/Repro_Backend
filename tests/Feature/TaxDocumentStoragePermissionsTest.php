<?php

namespace Tests\Feature;

use App\Services\TaxDocumentService;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use Tests\TestCase;

class TaxDocumentStoragePermissionsTest extends TestCase
{
    private ?string $fixtureRoot = null;

    protected function tearDown(): void
    {
        if ($this->fixtureRoot) {
            Storage::disk(TaxDocumentService::DISK)->deleteDirectory('');
        }
        parent::tearDown();
    }

    public function test_private_permission_mapping_grants_only_owner_and_service_group(): void
    {
        $config = config('filesystems.disks.tax_documents');
        $converter = PortableVisibilityConverter::fromArray($config['permissions'], $config['directory_visibility']);
        $this->assertSame('private', $config['visibility']);
        $this->assertSame(0660, $converter->forFile('private'));
        $this->assertSame(02770, $converter->forDirectory('private'));
        $this->assertSame(02770, $converter->defaultForDirectories());
        $this->assertSame(0, $converter->forFile('public') & 0007);
        $this->assertSame(0, $converter->forDirectory('public') & 0007);
        $this->assertFalse($config['serve']);
    }

    public function test_new_directories_and_files_keep_group_access_with_restrictive_umask(): void
    {
        $this->unixFixture();
        $previousUmask = umask(0077);
        try {
            $service = app(TaxDocumentService::class);
            $service->prepareOwnerDirectory(7001);
            $disk = Storage::disk(TaxDocumentService::DISK);
            $disk->put('7001/synthetic.txt', 'Synthetic permission fixture');
            clearstatcache();
            $this->assertSame(02770, fileperms($disk->path('7001')) & 07777);
            $this->assertSame(0660, fileperms($disk->path('7001/synthetic.txt')) & 07777);
            $this->assertSame(filegroup($this->fixtureRoot), filegroup($disk->path('7001')));
            $this->assertSame(filegroup($this->fixtureRoot), filegroup($disk->path('7001/synthetic.txt')));
            $service->prepareOwnerDirectory(7001);
            $this->assertSame('Synthetic permission fixture', $disk->get('7001/synthetic.txt'));
        } finally { umask($previousUmask); }
    }

    public function test_existing_owner_directory_with_unsafe_permissions_requires_operator_review(): void
    {
        $this->unixFixture();
        mkdir($this->fixtureRoot.'/7001', 0700);
        chmod($this->fixtureRoot.'/7001', 0700);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Private tax document owner permissions need operator review.');
        app(TaxDocumentService::class)->prepareOwnerDirectory(7001);
    }

    public function test_root_without_shared_setgid_permissions_is_rejected_before_owner_directory_creation(): void
    {
        $this->unixFixture();
        chmod($this->fixtureRoot, 0700);
        try {
            app(TaxDocumentService::class)->prepareOwnerDirectory(7001);
            $this->fail('Expected unprovisioned root denial.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Private tax document storage permissions need operator review.', $exception->getMessage());
            $this->assertDirectoryDoesNotExist($this->fixtureRoot.'/7001');
        }
    }

    private function unixFixture(): void
    {
        if (PHP_OS_FAMILY === 'Windows') { $this->markTestSkipped('Unix modes and setgid require a Unix filesystem.'); }
        $this->fixtureRoot = sys_get_temp_dir().'/tax-private-permissions-'.bin2hex(random_bytes(12));
        mkdir($this->fixtureRoot, 0700);
        chmod($this->fixtureRoot, 02770);
        $config = array_replace(config('filesystems.disks.tax_documents'), ['root' => $this->fixtureRoot]);
        Storage::set(TaxDocumentService::DISK, Storage::build($config));
    }
}
