<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserTaxDocument;
use App\Services\TaxDocumentLegacyMigration;
use App\Services\TaxDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TaxDocumentMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake(TaxDocumentService::DISK, config('filesystems.disks.'.TaxDocumentService::DISK));
        if (PHP_OS_FAMILY !== 'Windows') { chmod(Storage::disk(TaxDocumentService::DISK)->path(''), 02770); }
        Http::preventStrayRequests();
    }

    public function test_dry_run_is_read_only_and_apply_verifies_copies_cleans_public_source_and_is_idempotent(): void
    {
        [$owner, $source] = $this->legacy();
        $before = $owner->fresh()->getAttributes();
        $this->artisan('tax-documents:privatize')->expectsOutput('Dry run: no files or records changed.')->assertSuccessful();
        $this->assertSame($before, $owner->fresh()->getAttributes());
        $this->assertSame(0, UserTaxDocument::count());
        Storage::disk('public')->assertExists($source);
        $this->assertSame([], Storage::disk(TaxDocumentService::DISK)->allFiles());
        $report = app(TaxDocumentLegacyMigration::class)->run(true);
        $this->assertSame(1, $report['migrated']);
        $this->assertSame(1, $report['public_copies_removed']);
        $document = UserTaxDocument::firstOrFail();
        Storage::disk('public')->assertMissing($source);
        Storage::disk(TaxDocumentService::DISK)->assertExists($document->path);
        $this->assertSame(hash('sha256', $this->pdf()), $document->sha256);
        $this->assertSame('2026-01-01', $document->submitted_at->format('Y-m-d'));
        $this->assertSame('Legacy note', $document->notes);
        $this->assertNull($document->legacy_public_path);
        $this->assertSame(['preferences' => ['weeklyInvoice' => true]], $owner->fresh()->metadata);
        $second = app(TaxDocumentLegacyMigration::class)->run(true);
        $this->assertSame(0, $second['migrated']);
        $this->assertSame(1, $second['already_private']);
        $this->assertSame(1, UserTaxDocument::count());
        Http::assertNothingSent();
    }

    public function test_missing_orphan_and_wrong_owner_paths_are_reported_without_copying_or_deleting(): void
    {
        [$owner, $source] = $this->legacy();
        Storage::disk('public')->delete($source);
        $other = User::factory()->create(['metadata' => ['tax_document_path' => '../outside.pdf']]);
        User::factory()->create(['metadata' => ['tax_document_path' => 'tax-documents/user-'.$owner->id.'-tax-document-20260101.pdf']]);
        Storage::disk('public')->put('tax-documents/unowned.pdf', $this->pdf());
        Storage::disk('public')->put('outside.pdf', 'must remain');
        $report = app(TaxDocumentLegacyMigration::class)->run(true);
        $this->assertSame(1, $report['missing']);
        $this->assertSame(2, $report['invalid_paths']);
        $this->assertSame(1, $report['orphan_files']);
        $this->assertSame(0, UserTaxDocument::count());
        Storage::disk('public')->assertExists('outside.pdf');
        $this->assertSame('../outside.pdf', $other->fresh()->metadata['tax_document_path']);
    }

    public function test_failed_copy_verification_preserves_legacy_file_and_reference(): void
    {
        [$owner, $source] = $this->legacy();
        $service = new class extends TaxDocumentService {
            public function checksum(string $disk, string $path): string {
                return $disk === self::DISK ? str_repeat('0', 64) : parent::checksum($disk, $path);
            }
        };
        $report = (new TaxDocumentLegacyMigration($service))->run(true);
        $this->assertSame(1, $report['failures']);
        $this->assertSame(0, UserTaxDocument::count());
        $this->assertSame($source, $owner->fresh()->metadata['tax_document_path']);
        Storage::disk('public')->assertExists($source);
        $this->assertSame([], Storage::disk(TaxDocumentService::DISK)->allFiles());
    }

    public function test_retry_finishes_committed_copy_cleanup_without_creating_a_second_document(): void
    {
        [$owner, $source] = $this->legacy();
        $path = $owner->id.'/11111111-1111-4111-8111-111111111111.pdf';
        Storage::disk(TaxDocumentService::DISK)->put($path, $this->pdf());
        UserTaxDocument::create(['user_id' => $owner->id, 'path' => $path, 'legacy_public_path' => $source,
            'original_name' => 'legacy.pdf', 'mime_type' => 'application/pdf', 'size' => strlen($this->pdf()),
            'sha256' => hash('sha256', $this->pdf()), 'submitted_at' => now()]);
        $report = app(TaxDocumentLegacyMigration::class)->run(true);
        $this->assertSame(1, $report['already_private']);
        $this->assertSame(1, $report['public_copies_removed']);
        $this->assertSame(1, UserTaxDocument::count());
        Storage::disk('public')->assertMissing($source);
        Storage::disk(TaxDocumentService::DISK)->assertExists($path);
    }

    public function test_cleanup_does_not_delete_changed_public_source_or_a_source_without_verified_private_copy(): void
    {
        [$owner, $source] = $this->legacy();
        $path = $owner->id.'/11111111-1111-4111-8111-111111111111.pdf';
        $document = UserTaxDocument::create(['user_id' => $owner->id, 'path' => $path, 'legacy_public_path' => $source,
            'original_name' => 'legacy.pdf', 'mime_type' => 'application/pdf', 'size' => strlen($this->pdf()),
            'sha256' => hash('sha256', $this->pdf()), 'submitted_at' => now()]);
        $missingPrivate = app(TaxDocumentLegacyMigration::class)->run(true);
        $this->assertSame(1, $missingPrivate['conflicts']);
        Storage::disk('public')->assertExists($source);
        Storage::disk(TaxDocumentService::DISK)->put($path, $this->pdf());
        Storage::disk('public')->put($source, $this->pdf().'changed');
        $changedSource = app(TaxDocumentLegacyMigration::class)->run(true);
        $this->assertSame(1, $changedSource['failures']);
        Storage::disk('public')->assertExists($source);
        $this->assertSame($source, $document->fresh()->legacy_public_path);
        $this->assertSame($source, $owner->fresh()->metadata['tax_document_path']);
    }

    public function test_soft_deleted_owners_are_included_in_private_migration(): void
    {
        [$owner, $source] = $this->legacy();
        $owner->delete();
        $report = app(TaxDocumentLegacyMigration::class)->run(true);
        $this->assertSame(1, $report['migrated']);
        $this->assertNotNull(User::withTrashed()->findOrFail($owner->id)->deleted_at);
        Storage::disk('public')->assertMissing($source);
        Storage::disk(TaxDocumentService::DISK)->assertExists(UserTaxDocument::firstOrFail()->path);
    }

    private function legacy(): array
    {
        $user = User::factory()->create(['role' => 'photographer']);
        $path = 'tax-documents/user-'.$user->id.'-tax-document-20260101.pdf';
        Storage::disk('public')->put($path, $this->pdf());
        $user->metadata = ['tax_document_path' => $path, 'tax_document_url' => 'https://example.test/storage/'.$path,
            'tax_document_name' => 'legacy.pdf', 'tax_document_notes' => 'Legacy note', 'tax_document_submitted_at' => '2026-01-01T00:00:00Z',
            'preferences' => ['weeklyInvoice' => true]];
        $user->save();
        return [$user, $path];
    }

    private function pdf(): string { return "%PDF-1.4\nSynthetic legacy test document\n%%EOF\n"; }
}
