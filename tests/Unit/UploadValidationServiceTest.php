<?php

namespace Tests\Unit;

use App\Services\UploadValidationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UploadValidationServiceTest extends TestCase
{
    private UploadValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin config so the test is independent of environment overrides.
        config([
            'uploads.max_bytes' => 1048576 * 1024, // 1 GiB
            'uploads.allowed_types' => ['jpg', 'jpeg', 'png', 'mp4', 'zip'],
        ]);

        $this->service = new UploadValidationService();
    }

    /**
     * Build a fake uploaded file with a controllable extension and size.
     */
    private function upload(string $name, int $sizeBytes): UploadedFile
    {
        // UploadedFile::fake()->create lets us set the reported size in KB.
        return UploadedFile::fake()->create($name, (int) ($sizeBytes / 1024));
    }

    #[Test]
    public function it_accepts_a_valid_file(): void
    {
        $file = $this->upload('photo.jpg', 2 * 1024 * 1024); // 2 MB jpg

        $this->service->validate($file);

        // No exception thrown => valid.
        $this->assertTrue(true);
    }

    #[Test]
    public function it_rejects_a_file_that_exceeds_the_maximum_size(): void
    {
        config(['uploads.max_bytes' => 1024 * 1024]); // 1 MB cap for this case
        $file = $this->upload('big.jpg', 5 * 1024 * 1024); // 5 MB

        $this->expectException(ValidationException::class);

        $this->service->validate($file);
    }

    #[Test]
    public function it_rejects_a_disallowed_file_type(): void
    {
        $file = $this->upload('malware.exe', 1024);

        $this->expectException(ValidationException::class);

        $this->service->validate($file);
    }

    #[Test]
    public function it_matches_allowed_extensions_case_insensitively(): void
    {
        $file = $this->upload('PHOTO.JPG', 1024);

        $this->service->validate($file);

        $this->assertTrue($this->service->isAllowedType($file));
    }

    #[Test]
    public function it_reports_a_422_status_on_the_thrown_exception(): void
    {
        $file = $this->upload('notes.txt', 1024);

        try {
            $this->service->validate($file);
            $this->fail('Expected a ValidationException to be thrown.');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->status);
            $this->assertArrayHasKey('file', $e->errors());
        }
    }

    #[Test]
    public function it_validates_many_files_and_rejects_on_first_failure(): void
    {
        $valid = $this->upload('a.png', 1024);
        $invalid = $this->upload('b.exe', 1024);

        $this->expectException(ValidationException::class);

        $this->service->validateMany([$valid, $invalid]);
    }

    #[Test]
    public function it_rejects_an_archive_upload_from_a_client_role(): void
    {
        $file = $this->upload('deliverables.zip', 2 * 1024 * 1024);

        $this->expectException(ValidationException::class);

        // The extension is in the allow-list, so this is specifically the
        // staff-role gate (Req 5.9) rejecting a client-uploaded archive.
        $this->service->validate($file, 'file', 'client');
    }

    #[Test]
    public function it_rejects_an_archive_upload_from_an_unauthenticated_caller(): void
    {
        $file = $this->upload('deliverables.zip', 2 * 1024 * 1024);

        $this->expectException(ValidationException::class);

        $this->service->validate($file, 'file', null);
    }

    #[Test]
    public function it_accepts_an_archive_upload_from_a_staff_role(): void
    {
        $file = $this->upload('deliverables.zip', 2 * 1024 * 1024);

        $this->service->validate($file, 'file', 'editor');

        // No exception => accepted for staff.
        $this->assertTrue($this->service->isArchiveUpload($file));
    }

    #[Test]
    public function it_identifies_staff_roles(): void
    {
        $this->assertTrue($this->service->isStaffRole('admin'));
        $this->assertTrue($this->service->isStaffRole('editing_manager'));
        $this->assertTrue($this->service->isStaffRole('photographer'));
        $this->assertFalse($this->service->isStaffRole('client'));
        $this->assertFalse($this->service->isStaffRole(''));
        $this->assertFalse($this->service->isStaffRole(null));
    }
}
