<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserTaxDocument;
use App\Policies\TaxDocumentPolicy;
use App\Services\TaxDocumentService;
use App\Support\TaxDocumentMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaxDocumentPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake(TaxDocumentService::DISK, config('filesystems.disks.'.TaxDocumentService::DISK));
        if (PHP_OS_FAMILY !== 'Windows') { chmod(Storage::disk(TaxDocumentService::DISK)->path(''), 02770); }
        Http::preventStrayRequests();
        Notification::fake();
        Queue::fake();
    }

    public function test_upload_is_private_and_response_has_only_authorized_summary(): void
    {
        $owner = User::factory()->create(['role' => 'photographer', 'metadata' => ['preferences' => ['weeklyInvoice' => true]]]);
        Sanctum::actingAs($owner);
        $response = $this->postJson('/api/profile/tax-document', ['document' => $this->pdf(), 'notes' => 'Sensitive tax note']);
        $response->assertOk()->assertJsonPath('tax_document.original_name', 'test.pdf')->assertJsonPath('tax_document.can_download', true)
            ->assertJsonMissingPath('tax_document.path')->assertJsonMissingPath('tax_document.notes')
            ->assertJsonMissingPath('user.metadata.tax_document_url')->assertJsonPath('user.metadata.preferences.weeklyInvoice', true);
        $document = UserTaxDocument::firstOrFail();
        $this->assertSame($owner->id, $document->user_id);
        Storage::disk(TaxDocumentService::DISK)->assertExists($document->path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame('Sensitive tax note', $document->notes);
        $this->assertStringNotContainsString('Sensitive tax note', $document->getRawOriginal('notes'));
        $this->assertArrayNotHasKey('path', $document->toArray());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_owner_and_primary_administrators_can_download_but_other_roles_cannot(): void
    {
        $owner = User::factory()->create(['role' => 'photographer']);
        app(TaxDocumentService::class)->store($owner, $this->pdf(), null);
        Sanctum::actingAs($owner);
        $this->getJson('/api/profile/tax-document')->assertOk()->assertJsonPath('tax_document.can_download', true);
        $download = $this->get('/api/profile/tax-document/download')->assertOk();
        $this->assertStringContainsString('attachment;', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $download->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('%PDF', $download->streamedContent());
        foreach (['admin', 'superadmin'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/admin/users/'.$owner->id.'/tax-document')->assertOk();
            $this->get('/api/admin/users/'.$owner->id.'/tax-document/download')->assertOk();
        }
        foreach (['client', 'salesRep', 'sales_rep', 'editing_manager', 'editor', 'photographer', 'unknown'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'secondary_roles' => ['admin']]));
            $this->getJson('/api/admin/users/'.$owner->id.'/tax-document')->assertForbidden();
            $this->getJson('/api/admin/users/'.$owner->id.'/tax-document/download')->assertForbidden();
        }
    }

    public function test_guests_inactive_owners_and_impersonation_are_denied(): void
    {
        $owner = User::factory()->create(['role' => 'photographer']);
        $this->getJson('/api/profile/tax-document')->assertUnauthorized();
        $this->postJson('/api/profile/tax-document', ['document' => $this->pdf()])->assertUnauthorized();
        $policy = app(TaxDocumentPolicy::class);
        $owner->locked_at = now();
        $this->assertFalse($policy->view($owner, $owner));
        $this->assertFalse($policy->upload($owner, $owner));
        $owner->locked_at = null;
        request()->attributes->set('is_impersonating', true);
        $this->assertFalse($policy->view($owner, $owner));
        $this->assertFalse($policy->view(User::factory()->create(['role' => 'superadmin']), $owner));
    }

    public function test_upload_cannot_target_another_user_and_valid_replacement_removes_old_private_file(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service = app(TaxDocumentService::class);
        $old = $service->store($owner, $this->pdf('old.pdf'), null)->path;
        Sanctum::actingAs($owner);
        $this->postJson('/api/profile/tax-document', ['document' => $this->pdf('replacement.pdf'), 'user_id' => $other->id])->assertOk();
        $this->assertSame(1, UserTaxDocument::count());
        $this->assertNull($service->current($other));
        $current = $service->current($owner);
        $this->assertNotSame($old, $current->path);
        Storage::disk(TaxDocumentService::DISK)->assertMissing($old);
        Storage::disk(TaxDocumentService::DISK)->assertExists($current->path);
    }

    public function test_failed_verification_preserves_previous_file_and_database_record(): void
    {
        $owner = User::factory()->create();
        $previous = app(TaxDocumentService::class)->store($owner, $this->pdf(), null);
        $before = $previous->fresh()->getAttributes();
        $failing = new class extends TaxDocumentService {
            public function checksum(string $disk, string $path): string { return str_repeat('0', 64); }
        };
        try { $failing->store($owner, $this->pdf('new.pdf'), null); $this->fail('Expected copy verification failure.'); }
        catch (\RuntimeException $exception) { $this->assertStringContainsString('verification', $exception->getMessage()); }
        $this->assertSame($before, $previous->fresh()->getAttributes());
        $this->assertSame([$previous->path], Storage::disk(TaxDocumentService::DISK)->allFiles());
    }

    public function test_invalid_types_oversized_documents_and_invalid_storage_paths_cannot_be_served(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        foreach ([UploadedFile::fake()->create('document.doc', 1, 'application/msword'), UploadedFile::fake()->create('too-big.pdf', 10241, 'application/pdf'), UploadedFile::fake()->createWithContent('fake.pdf', '<?php echo 1;')] as $file) {
            $this->postJson('/api/profile/tax-document', ['document' => $file])->assertUnprocessable();
        }
        $document = app(TaxDocumentService::class)->store($owner, $this->pdf(), null);
        $document->update(['path' => '../outside.pdf']);
        $this->getJson('/api/profile/tax-document/download')->assertNotFound();
        $this->getJson('/api/profile/tax-document')->assertOk()->assertJsonPath('tax_document.can_download', false);
    }

    public function test_serialization_strips_tax_data_from_lists_and_preserves_ordinary_preferences(): void
    {
        $owner = User::factory()->create(['role' => 'photographer', 'metadata' => [
            'tax_document_path' => 'tax-documents/legacy.pdf', 'tax_document_url' => 'https://example.test/old',
            'tax_document_notes' => 'Private note', 'taxDocumentName' => 'private.pdf',
            'preferences' => ['weeklyInvoice' => true], 'nested' => ['tax_document_notes' => 'private', 'keep' => 'yes'],
        ]]);
        $serialized = $owner->toArray();
        $this->assertSame(['preferences' => ['weeklyInvoice' => true], 'nested' => ['keep' => 'yes']], $serialized['metadata']);
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $response = $this->getJson('/api/admin/photographers')->assertOk();
        $this->assertStringNotContainsString('tax_document', $response->getContent());
        $this->assertStringNotContainsString('private.pdf', $response->getContent());
        $this->assertSame('tax-documents/legacy.pdf', $owner->fresh()->metadata['tax_document_path']);
    }

    public function test_general_profile_and_admin_metadata_writes_cannot_change_tax_ownership_or_paths(): void
    {
        $owner = User::factory()->create(['role' => 'photographer']);
        Sanctum::actingAs($owner);
        $this->putJson('/api/profile', ['metadata' => ['tax_document_path' => 'tax-documents/other.pdf']])->assertUnprocessable();
        $this->putJson('/api/profile', ['tax_document_url' => 'https://example.test/old'])->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([['tax_document_path' => 'anything'], json_encode(['nested' => ['taxDocumentNotes' => 'private']])] as $metadata) {
            $this->putJson('/api/admin/users/'.$owner->id, ['metadata' => $metadata])->assertUnprocessable();
        }
        $this->assertNull(app(TaxDocumentService::class)->current($owner));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_unrelated_metadata_edits_retain_legacy_migration_pointers_without_serializing_them(): void
    {
        $legacy = ['tax_document_path' => 'tax-documents/old.pdf', 'preferences' => ['weeklyInvoice' => false]];
        $merged = TaxDocumentMetadata::preserveLegacy($legacy, ['preferences' => ['weeklyInvoice' => true]]);
        $this->assertSame('tax-documents/old.pdf', $merged['tax_document_path']);
        $this->assertTrue($merged['preferences']['weeklyInvoice']);
        $this->assertSame(['preferences' => ['weeklyInvoice' => true]], TaxDocumentMetadata::strip($merged));
    }

    public function test_admin_null_metadata_preserves_legacy_pointer_and_rejects_tax_fields_in_preferences(): void
    {
        $owner = User::factory()->create(['role' => 'client', 'metadata' => [
            'tax_document_path' => 'tax-documents/legacy.pdf', 'preferences' => ['weeklyInvoice' => false],
        ]]);
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']));
        $this->putJson('/api/admin/users/'.$owner->id, ['metadata' => null])->assertOk();
        $this->assertSame('tax-documents/legacy.pdf', $owner->fresh()->metadata['tax_document_path']);
        foreach (['invalid-json', 42, json_encode('scalar')] as $metadata) {
            $this->putJson('/api/admin/users/'.$owner->id, ['metadata' => $metadata])->assertUnprocessable();
        }
        $this->putJson('/api/admin/users/'.$owner->id, ['preferences' => ['tax_document_url' => 'https://example.test/private']])->assertUnprocessable();
    }

    public function test_upload_waits_for_legacy_cleanup_and_rechecks_a_locked_account(): void
    {
        $owner = User::factory()->create(['role' => 'photographer', 'metadata' => ['tax_document_path' => 'tax-documents/old.pdf']]);
        Sanctum::actingAs($owner);
        $this->postJson('/api/profile/tax-document', ['document' => $this->pdf()])->assertConflict();
        $this->assertSame(0, UserTaxDocument::count());
        $this->assertSame([], Storage::disk(TaxDocumentService::DISK)->allFiles());
        $owner->forceFill(['metadata' => [], 'locked_at' => now()])->save();
        try {
            app(TaxDocumentService::class)->store($owner, $this->pdf(), null);
            $this->fail('Expected inactive owner denial.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame([], Storage::disk(TaxDocumentService::DISK)->allFiles());
    }

    private function pdf(string $name = 'test.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nSynthetic test document\n%%EOF\n");
    }
}
