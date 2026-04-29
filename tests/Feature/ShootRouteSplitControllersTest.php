<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PublicPaymentAccessToken;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootShareLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootRouteSplitControllersTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $client;
    protected Service $service;
    protected Service $secondService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'route-split-admin@test.com',
        ]);

        $this->client = User::factory()->create([
            'role' => 'client',
            'email' => 'route-split-client@test.com',
            'name' => 'Route Split Client',
        ]);

        $this->service = Service::factory()->create([
            'name' => 'Route Split Service',
            'price' => 125,
        ]);

        $this->secondService = Service::factory()->create([
            'name' => 'Route Split Service Two',
            'price' => 150,
        ]);
    }

    /** @test */
    public function public_branded_route_still_returns_the_public_asset_contract(): void
    {
        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'address' => '100 Public Route St',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
        ]);

        $response = $this->getJson("/api/public/shoots/{$shoot->id}/branded");

        $response->assertOk()
            ->assertJsonPath('type', 'branded')
            ->assertJsonPath('shoot.id', $shoot->id)
            ->assertJsonPath('shoot.client_name', 'Route Split Client');
    }

    /** @test */
    public function public_video_links_are_hidden_until_the_shoot_is_delivered(): void
    {
        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        $shoot->tour_links = [
            'video_branded' => 'https://vimeo.com/1186135733',
            'video_mls' => 'https://vimeo.com/1186135734',
            'video_generic' => 'https://vimeo.com/1186135735',
            'video_link' => 'https://vimeo.com/1186135736',
            'matterport_branded' => 'https://example.test/matterport',
        ];
        $shoot->save();

        $response = $this->getJson("/api/public/shoots/{$shoot->id}/branded");

        $response->assertOk()
            ->assertJsonPath('video_link', null)
            ->assertJsonPath('video_thumbnail_url', null)
            ->assertJsonPath('video_access_restricted', true)
            ->assertJsonPath('tour_links.matterport_branded', 'https://example.test/matterport');

        $this->assertArrayNotHasKey('video_branded', $response->json('tour_links'));
        $this->assertArrayNotHasKey('video_link', $response->json('tour_links'));
    }

    /** @test */
    public function privileged_users_can_view_video_links_before_delivery(): void
    {
        $editingManager = User::factory()->create([
            'role' => 'editing_manager',
            'email' => 'route-split-editing-manager@test.com',
        ]);
        Sanctum::actingAs($editingManager);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_UPLOADED,
            'workflow_status' => Shoot::STATUS_UPLOADED,
        ]);
        $shoot->tour_links = [
            'video_branded' => 'https://youtu.be/dQw4w9WgXcQ',
        ];
        $shoot->save();

        $response = $this->getJson("/api/public/shoots/{$shoot->id}/branded");

        $response->assertOk()
            ->assertJsonPath('video_link', 'https://youtu.be/dQw4w9WgXcQ')
            ->assertJsonPath('tour_links.video_branded', 'https://youtu.be/dQw4w9WgXcQ');
    }

    /** @test */
    public function delivered_video_links_are_public(): void
    {
        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
        ]);
        $shoot->tour_links = [
            'video_mls' => 'https://vimeo.com/1186135733',
        ];
        $shoot->save();

        $response = $this->getJson("/api/public/shoots/{$shoot->id}/mls");

        $response->assertOk()
            ->assertJsonPath('video_link', 'https://vimeo.com/1186135733')
            ->assertJsonPath('tour_links.video_mls', 'https://vimeo.com/1186135733');
    }

    /** @test */
    public function notes_route_still_returns_note_payloads_after_the_route_split(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
        ]);

        $note = $shoot->notes()->create([
            'author_id' => $this->admin->id,
            'type' => 'shoot',
            'visibility' => 'internal',
            'content' => 'Route split note',
        ]);

        $response = $this->getJson("/api/shoots/{$shoot->id}/notes");

        $response->assertOk()
            ->assertJsonPath('data.0.id', $note->id)
            ->assertJsonPath('data.0.content', 'Route split note')
            ->assertJsonPath('data.0.author.id', $this->admin->id);
    }

    /** @test */
    public function public_payment_token_route_returns_the_sanitized_payment_payload(): void
    {
        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'address' => '200 Payment St',
            'city' => 'Washington',
            'state' => 'DC',
            'zip' => '20001',
            'total_quote' => 150,
            'base_quote' => 140,
            'tax_amount' => 10,
        ]);
        $shoot->services()->attach($this->service->id, [
            'price' => 125,
            'quantity' => 1,
        ]);

        Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'amount' => 50,
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $token = PublicPaymentAccessToken::create([
            'shoot_id' => $shoot->id,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson("/api/public/payments/{$token->token}");

        $response->assertOk()
            ->assertJsonPath('data.id', $shoot->id)
            ->assertJsonPath('data.client', null)
            ->assertJsonPath('data.payments.0.status', Payment::STATUS_COMPLETED);
    }

    /** @test */
    public function admin_client_requests_route_still_parses_legacy_issue_notes(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'address' => '300 Issue St',
            'admin_issue_notes' => '[Request from Route Split Client]: Please replace the front exterior',
            'is_flagged' => true,
        ]);

        $response = $this->getJson('/api/client-requests');

        $response->assertOk()
            ->assertJsonPath('data.0.shootId', (string) $shoot->id)
            ->assertJsonPath('data.0.raisedBy.name', 'Route Split Client')
            ->assertJsonPath('data.0.note', 'Please replace the front exterior');
    }

    /** @test */
    public function editor_client_requests_route_only_returns_requests_for_assigned_shoots(): void
    {
        $editor = User::factory()->create([
            'role' => 'editor',
            'email' => 'assigned-editor@test.com',
        ]);
        $otherEditor = User::factory()->create([
            'role' => 'editor',
            'email' => 'other-editor@test.com',
        ]);
        Sanctum::actingAs($editor);

        $assignedShoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'address' => '301 Assigned Issue St',
            'editor_id' => null,
            'admin_issue_notes' => '[Request from Route Split Client]: Please brighten the kitchen',
            'is_flagged' => true,
        ]);
        $assignedShoot->services()->attach($this->service->id, [
            'price' => 125,
            'quantity' => 1,
            'editor_id' => $editor->id,
        ]);

        $otherShoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->secondService->id,
            'address' => '302 Other Issue St',
            'editor_id' => null,
            'admin_issue_notes' => '[Request from Route Split Client]: Please replace the sky',
            'is_flagged' => true,
        ]);
        $otherShoot->services()->attach($this->secondService->id, [
            'price' => 150,
            'quantity' => 1,
            'editor_id' => $otherEditor->id,
        ]);

        $response = $this->getJson('/api/client-requests');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.shootId', (string) $assignedShoot->id)
            ->assertJsonPath('data.0.note', 'Please brighten the kitchen');
    }

    /** @test */
    public function legacy_single_service_photographer_assignment_route_is_compatible(): void
    {
        Sanctum::actingAs($this->admin);

        $photographer = User::factory()->create([
            'role' => 'photographer',
            'email' => 'route-split-photographer@test.com',
        ]);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
        ]);
        $shoot->services()->attach($this->service->id, [
            'price' => 125,
            'quantity' => 1,
        ]);

        $response = $this->postJson("/api/shoots/{$shoot->id}/assign-service-photographer", [
            'service_id' => $this->service->id,
            'photographer_id' => $photographer->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Service photographer assigned successfully')
            ->assertJsonFragment([
                'photographer_id' => (string) $photographer->id,
                'resolved_photographer_id' => (string) $photographer->id,
            ]);

        $this->assertDatabaseHas('shoot_service', [
            'shoot_id' => $shoot->id,
            'service_id' => $this->service->id,
            'photographer_id' => $photographer->id,
        ]);
    }

    /** @test */
    public function legacy_bulk_service_photographer_assignment_route_is_compatible(): void
    {
        Sanctum::actingAs($this->admin);

        $firstPhotographer = User::factory()->create(['role' => 'photographer']);
        $secondPhotographer = User::factory()->create(['role' => 'photographer']);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
        ]);
        $shoot->services()->attach($this->service->id, ['price' => 125, 'quantity' => 1]);
        $shoot->services()->attach($this->secondService->id, ['price' => 150, 'quantity' => 1]);

        $response = $this->postJson("/api/shoots/{$shoot->id}/assign-service-photographers", [
            'service_photographers' => [
                ['service_id' => $this->service->id, 'photographer_id' => $firstPhotographer->id],
                ['service_id' => $this->secondService->id, 'photographer_id' => $secondPhotographer->id],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Service photographers assigned successfully')
            ->assertJsonFragment([
                'resolved_photographer_id' => (string) $firstPhotographer->id,
            ])
            ->assertJsonFragment([
                'resolved_photographer_id' => (string) $secondPhotographer->id,
            ]);

        $this->assertDatabaseHas('shoot_service', [
            'shoot_id' => $shoot->id,
            'service_id' => $this->service->id,
            'photographer_id' => $firstPhotographer->id,
        ]);
        $this->assertDatabaseHas('shoot_service', [
            'shoot_id' => $shoot->id,
            'service_id' => $this->secondService->id,
            'photographer_id' => $secondPhotographer->id,
        ]);
    }

    /** @test */
    public function editor_download_raw_route_uses_local_zip_fallback_when_realtime_storage_is_unavailable(): void
    {
        Storage::disk('public')->put('shoots/123/todo/raw-test.jpg', 'raw-image');
        config()->set('services.dropbox.enabled', false);
        config()->set('services.dropbox.access_token', null);

        $editor = User::factory()->create([
            'role' => 'editor',
            'email' => 'route-split-editor@test.com',
        ]);
        Sanctum::actingAs($editor);

        $shoot = Shoot::factory()->create([
            'id' => 123,
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'editor_id' => $editor->id,
        ]);

        ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'raw-test.jpg',
            'stored_filename' => 'raw-test.jpg',
            'path' => 'shoots/123/todo/raw-test.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 9,
            'uploaded_by' => $editor->id,
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]);

        $response = $this
            ->withHeaders(['Origin' => 'https://reprodashboard.com'])
            ->get("/api/shoots/{$shoot->id}/editor-download-raw");

        $response->assertOk();
        $this->assertStringContainsString('shoot-123-raw-files.zip', $response->headers->get('content-disposition', ''));
        $response->assertHeader('Access-Control-Allow-Origin', 'https://reprodashboard.com');
    }

    /** @test */
    public function editor_generate_share_link_route_returns_cors_headers_when_no_raw_files_exist(): void
    {
        config()->set('services.dropbox.enabled', false);
        config()->set('services.dropbox.access_token', null);

        $editor = User::factory()->create([
            'role' => 'editor',
            'email' => 'route-split-share-link-editor@test.com',
        ]);
        Sanctum::actingAs($editor);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'editor_id' => $editor->id,
        ]);

        $response = $this
            ->withHeaders(['Origin' => 'https://reprodashboard.com'])
            ->postJson("/api/shoots/{$shoot->id}/generate-share-link", [
                'media_stage' => 'raw',
            ]);

        $response->assertStatus(404)
            ->assertHeader('Access-Control-Allow-Origin', 'https://reprodashboard.com');
    }

    /** @test */
    public function shoot_media_preflight_routes_return_cors_headers(): void
    {
        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
        ]);

        $downloadResponse = $this->call('OPTIONS', "/api/shoots/{$shoot->id}/editor-download-raw", [], [], [], [
            'HTTP_ORIGIN' => 'https://reprodashboard.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $downloadResponse->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://reprodashboard.com');

        $shareLinkResponse = $this->call('OPTIONS', "/api/shoots/{$shoot->id}/generate-share-link", [], [], [], [
            'HTTP_ORIGIN' => 'https://reprodashboard.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $shareLinkResponse->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://reprodashboard.com');
    }

    /** @test */
    public function public_client_profile_route_still_returns_the_profile_contract(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
        ]);

        $response = $this->getJson("/api/public/clients/{$this->client->id}/profile");

        $response->assertOk()
            ->assertJsonPath('client.id', $this->client->id)
            ->assertJsonPath('shoots.0.id', $shoot->id);
    }

    /** @test */
    public function album_routes_still_return_the_expected_payload_shapes(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
        ]);

        $createResponse = $this->postJson("/api/shoots/{$shoot->id}/albums", [
            'source' => 'local',
            'folder_path' => 'shoots/' . $shoot->id . '/albums/main',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('message', 'Album created successfully')
            ->assertJsonPath('data.source', 'local');

        $listResponse = $this->getJson("/api/shoots/{$shoot->id}/albums");

        $listResponse->assertOk()
            ->assertJsonPath('data.0.source', 'local')
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'source',
                    'folder_path',
                    'cover_image_path',
                    'is_watermarked',
                    'photographer',
                    'file_count',
                    'created_at',
                ]],
            ]);
    }

    /** @test */
    public function share_link_listing_route_still_returns_the_formatted_contract(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
        ]);

        $shareLink = ShootShareLink::create([
            'shoot_id' => $shoot->id,
            'created_by' => $this->admin->id,
            'share_url' => 'https://example.test/share/abc123',
            'download_count' => 2,
        ]);

        $response = $this->getJson("/api/shoots/{$shoot->id}/share-links");

        $listedShareUrl = data_get($response->json(), 'data.0.share_url');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $shareLink->id)
            ->assertJsonPath('data.0.public_token', $shareLink->public_token)
            ->assertJsonPath('data.0.media_stage', 'raw')
            ->assertJsonPath('data.0.created_by.id', $this->admin->id)
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'share_url',
                    'media_stage',
                    'download_count',
                    'created_at',
                    'expires_at',
                    'is_expired',
                    'is_revoked',
                    'is_active',
                    'created_by',
                ]],
            ]);

        $this->assertIsString($listedShareUrl);
        $this->assertStringEndsWith("/share/{$shareLink->public_token}", $listedShareUrl);
    }
}
