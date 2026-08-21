<?php

namespace Tests\Feature;

use App\Models\LegalAcceptance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegalDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_photographer_drafts_do_not_show_client_terms_or_block_access(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        Sanctum::actingAs($photographer);

        $this->getJson('/api/legal-documents/current')
            ->assertOk()
            ->assertJsonCount(0, 'documents')
            ->assertJsonPath('status.state', 'legal_pending')
            ->assertJsonPath('status.enforcement_active', false)
            ->assertJsonPath('status.activation_pending', true)
            ->assertJsonCount(0, 'status.pending');

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('legal_status.state', 'legal_pending');
    }

    public function test_active_documents_are_role_version_and_server_hash_bound(): void
    {
        $this->activatePhotographerDocuments();
        $photographer = User::factory()->create(['role' => 'photographer']);
        Sanctum::actingAs($photographer);

        $current = $this->getJson('/api/legal-documents/current')
            ->assertOk()
            ->assertJsonCount(2, 'documents')
            ->assertJsonCount(2, 'status.pending')
            ->json('documents');

        foreach ($current as $document) {
            $this->postJson('/api/legal-acceptances', [
                'document_key' => $document['document_key'],
                'version' => $document['version'],
                'content_hash' => str_repeat('0', 64),
                'role' => 'admin',
            ])->assertCreated();

            $acceptance = LegalAcceptance::query()
                ->where('user_id', $photographer->id)
                ->where('document_key', $document['document_key'])
                ->firstOrFail();

            $this->assertSame('photographer', $acceptance->role_at_acceptance);
            $this->assertSame($document['content_hash'], $acceptance->content_hash);
        }

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('legal_status.state', 'current')
            ->assertJsonCount(0, 'legal_status.pending');
    }

    public function test_stale_version_and_wrong_role_document_are_rejected(): void
    {
        $this->activatePhotographerDocuments();
        $photographer = User::factory()->create(['role' => 'photographer']);
        Sanctum::actingAs($photographer);

        $this->postJson('/api/legal-acceptances', [
            'document_key' => 'photographer_terms',
            'version' => 'stale-version',
        ])->assertUnprocessable();

        $client = User::factory()->create(['role' => 'client']);
        Sanctum::actingAs($client);
        $this->postJson('/api/legal-acceptances', [
            'document_key' => 'photographer_terms',
            'version' => 'draft-2026-08-20',
        ])->assertUnprocessable();
    }

    private function activatePhotographerDocuments(): void
    {
        $documents = config('legal_documents.roles.photographer');
        foreach ($documents as &$document) {
            $document['active'] = true;
            $document['effective_date'] = '2026-08-20';
        }

        config()->set('legal_documents.photographer_documents_active', true);
        config()->set('legal_documents.roles.photographer', $documents);
    }
}
