<?php

namespace Tests\Unit;

use App\Services\IguideOfflinePackageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

class IguideOfflinePackageServiceTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function it_accepts_a_bounded_package_with_one_optional_wrapper(): void
    {
        $upload = $this->zip([
            '9137 Lakeland Valley/Index.HTML' => '<!doctype html><title>Tour</title>',
            '9137 Lakeland Valley/assets/app.js' => 'console.log("tour")',
            '9137 Lakeland Valley/assets/app.css' => 'body { color: #123; }',
        ], 'tour.zip');

        $result = app(IguideOfflinePackageService::class)->inspect($upload);

        $this->assertSame('tour.zip', $result['original_filename']);
        $this->assertSame(3, $result['entry_count']);
        $this->assertSame('9137 Lakeland Valley', $result['wrapper_directory']);
        $this->assertSame('9137 Lakeland Valley/Index.HTML', $result['index_entry_path']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['sha256']);
    }

    #[Test]
    public function it_rejects_traversal_case_collisions_and_nested_archives(): void
    {
        $cases = [
            [
                'tour/index.html' => '<html></html>',
                '../outside.txt' => 'escape',
            ],
            [
                'tour/index.html' => '<html></html>',
                'tour/Assets/app.js' => 'one',
                'tour/assets/app.js' => 'two',
            ],
            [
                'tour/index.html' => '<html></html>',
                'tour/assets/vendor.zip' => 'not another archive',
            ],
            [
                'tour/index.html' => '<html></html>',
                'tour/assets/renamed.bin' => "PK\x03\x04nested archive bytes",
            ],
        ];

        foreach ($cases as $entries) {
            try {
                app(IguideOfflinePackageService::class)->inspect($this->zip($entries));
                $this->fail('Unsafe package was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('package', $exception->errors());
            }
        }
    }

    #[Test]
    public function it_requires_exactly_one_index_at_the_effective_root(): void
    {
        foreach ([
            ['tour/pages/index.html' => '<html></html>', 'tour/app.js' => 'x'],
            ['index.html' => '<html></html>', 'nested/index.html' => '<html></html>'],
            ['tour/app.js' => 'x'],
        ] as $entries) {
            $this->expectPackageValidationFailure($this->zip($entries));
        }
    }

    #[Test]
    public function it_rejects_server_executables_symlinks_and_compression_bombs(): void
    {
        $this->expectPackageValidationFailure($this->zip([
            'tour/index.html' => '<html></html>',
            'tour/shell.php' => '<?php echo 1;',
        ]));

        $symlink = $this->zip([
            'tour/index.html' => '<html></html>',
            'tour/link' => 'index.html',
        ]);
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($symlink->getRealPath()) === true);
        $this->assertTrue($archive->setExternalAttributesName('tour/link', ZipArchive::OPSYS_UNIX, 0120777 << 16));
        $archive->close();
        $this->expectPackageValidationFailure($symlink);

        $this->expectPackageValidationFailure($this->zip([
            'tour/index.html' => '<html></html>',
            'tour/zeros.bin' => str_repeat("\0", 1024 * 1024),
        ]));
    }

    /** @param array<string,string> $entries */
    private function zip(array $entries, string $name = 'package.zip'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'iguide-test-');
        if ($path === false) {
            $this->fail('Could not allocate a temporary ZIP.');
        }
        $this->temporaryFiles[] = $path;

        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($entries as $entry => $contents) {
            $this->assertTrue($archive->addFromString($entry, $contents));
        }
        $archive->close();

        return new UploadedFile($path, $name, 'application/zip', null, true);
    }

    private function expectPackageValidationFailure(UploadedFile $upload): void
    {
        try {
            app(IguideOfflinePackageService::class)->inspect($upload);
            $this->fail('Invalid package was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('package', $exception->errors());
        }
    }
}
