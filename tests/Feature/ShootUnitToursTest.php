<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\ShootUnit;
use App\Models\User;
use App\Services\IguideOfflinePackageService;
use App\Services\Shoots\ShootPublicAssetsService;
use App\Services\Shoots\ShootUnitTourScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class ShootUnitToursTest extends TestCase
{
    use RefreshDatabase;

    private function property(): array
    {
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create(['status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED, 'total_quote' => 0, 'payment_status' => 'paid', 'bypass_paywall' => true, 'tour_links' => ['tour_style' => 'homeify', 'property_mls' => 'BUILDING', 'video_branded' => 'https://video.test/building']]);
        $service = Service::factory()->create(['name' => 'Floor Plan']);
        $units = collect(['101', '102', 'Amenities'])->map(function ($label, $index) use ($shoot, $service) {
            $unit = $shoot->units()->create(['client_key' => (string) Str::uuid(), 'label' => $label, 'kind' => $index === 2 ? 'common_area' : 'unit', 'sqft' => 1000 + $index * 100, 'beds' => $index, 'baths' => 1]);
            $line = ShootService::create(['shoot_id' => $shoot->id, 'shoot_unit_id' => $unit->id, 'service_id' => $service->id, 'client_key' => (string) Str::uuid(), 'quantity' => 1, 'price' => 100, 'is_deliverable' => true, 'delivery_status' => ShootService::DELIVERY_READY]);
            return [$unit, $line];
        });
        return [$admin, $shoot, $units];
    }

    public function test_unit_public_variants_inherit_only_presentation_settings_and_reject_foreign_units(): void
    {
        [$admin, $shoot, $units] = $this->property();
        [$unit] = $units[0];
        $unit->update(['tour_links' => ['property_mls' => 'UNIT101', 'matterport_branded' => 'https://tour.test/101', 'matterport_mls' => 'https://tour.test/101-mls']]);
        foreach (['branded', 'mls', 'g-mls'] as $variant) {
            $response = $this->getJson("/api/public/shoots/{$shoot->id}/{$variant}?unitId={$unit->id}")->assertOk();
            $response->assertJsonPath('shoot.unit_id', $unit->id)->assertJsonPath('property_details.bedrooms', 0)->assertJsonPath('tour_style', 'homeify');
            $this->assertStringNotContainsString('BUILDING', $response->getContent());
            $this->assertStringNotContainsString('video.test/building', $response->getContent());
            $this->assertArrayNotHasKey('units', $response->json());
        }
        $other = Shoot::factory()->create();
        $this->getJson("/api/public/shoots/{$other->id}/branded?unitId={$unit->id}")->assertNotFound();
        $this->getJson("/api/public/shoots/{$shoot->id}/branded?unitId=999999")->assertNotFound();
        $this->getJson("/api/public/shoots/{$shoot->id}/branded?unitId[]=1")->assertNotFound();
        $this->getJson("/api/public/shoots/{$shoot->id}/branded")->assertNotFound();
    }

    public function test_common_area_media_is_opt_in_and_other_units_are_never_included(): void
    {
        [$admin, $shoot, $units] = $this->property();
        foreach ($units as [$unit, $line]) {
            ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $line->id, 'filename' => $unit->label.'.jpg', 'stored_filename' => $unit->label.'.jpg', 'path' => $unit->label.'.jpg', 'file_type' => 'image/jpeg', 'file_size' => 10, 'uploaded_by' => $admin->id, 'workflow_stage' => ShootFile::STAGE_COMPLETED]);
        }
        $scope = app(ShootUnitTourScope::class);
        $view = $scope->project($shoot, $units[0][0]);
        $this->assertSame(['101.jpg'], $view->files->pluck('filename')->all());
        $units[0][0]->update(['include_common_area_media' => true]);
        $view = $scope->project($shoot, $units[0][0]);
        $this->assertSame(['101.jpg', 'Amenities.jpg'], $view->files->pluck('filename')->all());
        $this->assertSame('BUILDING', $shoot->fresh()->tour_links['property_mls']);
    }

    public function test_public_unit_links_require_ready_unlocked_lines_and_hide_todo_media(): void
    {
        [$admin, $shoot, $units] = $this->property();
        [$unit, $line] = $units[0];
        $line->update(['delivery_status' => ShootService::DELIVERY_NOT_STARTED]);
        $this->getJson("/api/public/shoots/{$shoot->id}/branded?unitId={$unit->id}")->assertNotFound();
        $line->update(['delivery_status' => ShootService::DELIVERY_READY]);
        $shoot->update(['payment_status' => 'unpaid', 'bypass_paywall' => false, 'total_quote' => 100]);
        $this->getJson("/api/public/shoots/{$shoot->id}/branded?unitId={$unit->id}")->assertNotFound();
        $line->update(['force_unlock_delivery' => true]);
        ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $line->id, 'filename' => 'raw.jpg', 'stored_filename' => 'raw.jpg', 'path' => 'raw.jpg', 'file_type' => 'image/jpeg', 'file_size' => 10, 'uploaded_by' => $admin->id, 'workflow_stage' => ShootFile::STAGE_TODO]);
        $this->getJson("/api/public/shoots/{$shoot->id}/branded?unitId={$unit->id}")->assertOk()->assertJsonPath('photos', []);
        $this->assertTrue(app(ShootUnitTourScope::class)->projectPublic($shoot->fresh(), $unit)->files->isEmpty());
    }

    public function test_same_unit_provider_service_lines_keep_their_own_order_and_webhook_identity(): void
    {
        [$admin, $shoot, $units] = $this->property();
        [$unit, $first] = $units[0];
        $second = ShootService::create(['shoot_id' => $shoot->id, 'shoot_unit_id' => $unit->id, 'service_id' => Service::factory()->create(['name' => '3D Floor Plan'])->id, 'client_key' => (string) Str::uuid(), 'quantity' => 1, 'price' => 150, 'is_deliverable' => true]);
        config(['services.cubicasa.api_key' => 'test', 'services.cubicasa.owner_email' => 'orders@example.test']);
        Http::fake(['*/orders/draft' => fn ($request) => Http::response(['id' => 'order-'.$request['external_id'], 'info' => ['external_id' => $request['external_id'], 'status' => 'New']], 200)]);
        Sanctum::actingAs($admin);
        foreach ([$first, $second] as $line) {
            $this->postJson("/api/shoots/{$shoot->id}/units/{$unit->id}/cubicasa/order", ['shoot_service_id' => $line->id])->assertOk();
        }
        $scope = app(ShootUnitTourScope::class);
        foreach ([$first, $second] as $line) {
            $expected = "order-shoot-{$shoot->id}-unit-{$unit->id}-line-{$line->id}";
            $this->assertSame($expected, $scope->project($shoot, $unit->fresh(), $line)->cubicasa_order_id);
            $matched = $scope->matchProvider('cubicasa', ['cubicasa_order_id' => $expected]);
            $this->assertSame($line->id, $matched->getRelation('tourServiceLine')->id);
        }
        Http::assertSentCount(2);
    }

    public function test_unit_property_updates_are_scoped_and_client_cannot_edit_provider_or_branding(): void
    {
        [$admin, $shoot, $units] = $this->property();
        $unit = $units[0][0];
        Sanctum::actingAs($admin);
        $revision = $shoot->fresh()->units_revision;
        $this->patchJson("/api/shoots/{$shoot->id}/units/{$unit->id}/tour", ['tour_links' => ['property_mls' => '101-MLS'], 'bedrooms' => 4])->assertOk();
        $this->assertSame($revision + 1, $shoot->fresh()->units_revision);
        $this->patchJson("/api/shoots/{$shoot->id}/units/{$unit->id}/tour", ['tour_links' => ['property_mls' => '101-MLS', 'tour_style' => 'homeify']])->assertOk();
        $this->assertArrayNotHasKey('tour_style', $unit->fresh()->tour_links);
        $this->assertSame(4, $unit->fresh()->beds);
        $this->assertSame(1, $units[1][0]->fresh()->beds);
        $this->assertSame('BUILDING', $shoot->fresh()->tour_links['property_mls']);
        $client = User::factory()->create(['role' => 'client']);
        $shoot->update(['client_id' => $client->id]);
        Sanctum::actingAs($client);
        $this->patchJson("/api/shoots/{$shoot->id}/units/{$unit->id}/tour", ['tour_links' => ['tour_style' => 'landor']])->assertForbidden();
        $this->patchJson("/api/shoots/{$shoot->id}/units/{$unit->id}/tour", ['tour_links' => ['property_description' => 'Bright apartment']])->assertOk();
        $this->patchJson("/api/shoots/{$shoot->id}/units/{$unit->id}/tour", ['tour_links' => ['realtor_client_id' => $client->id]])->assertOk();
        $unrelated = User::factory()->create(['role' => 'client']);
        $this->patchJson("/api/shoots/{$shoot->id}/units/{$unit->id}/tour", ['tour_links' => ['realtor_client_id' => $unrelated->id]])->assertForbidden();
        $rep = User::factory()->create(['role' => 'salesRep']);
        $shoot->update(['rep_id' => $rep->id]);
        Sanctum::actingAs($rep);
        $this->patchJson("/api/shoots/{$shoot->id}/units/{$unit->id}/tour", ['tour_links' => ['realtor_client_id' => $unrelated->id]])->assertOk();
        $this->patchJson("/api/shoots/{$shoot->id}/units/{$unit->id}/tour", ['bedrooms' => 5])->assertForbidden();
    }

    public function test_provider_orders_have_distinct_unit_line_keys_and_never_update_building_provider_columns(): void
    {
        [$admin, $shoot, $units] = $this->property();
        Sanctum::actingAs($admin);
        config(['services.cubicasa.api_key' => 'test', 'services.cubicasa.owner_email' => 'orders@example.test']);
        Http::fake(['*/orders/draft' => fn ($request) => Http::response(['id' => 'order-'.$request['suite'], 'info' => ['external_id' => $request['external_id'], 'status' => 'New']], 200)]);
        foreach ($units->take(2) as [$unit, $line]) {
            $this->postJson("/api/shoots/{$shoot->id}/units/{$unit->id}/cubicasa/order", ['shoot_service_id' => $line->id])->assertOk();
            $this->assertSame('order-'.$unit->label, data_get($unit->fresh()->provider_data, 'cubicasa_order_id'));
            Http::assertSent(fn ($request) => $request['external_id'] === "shoot-{$shoot->id}-unit-{$unit->id}-line-{$line->id}" && $request['suite'] === $unit->label);
        }
        $this->assertNull($shoot->fresh()->cubicasa_order_id);
        $this->assertNotSame(data_get($units[0][0]->fresh()->provider_data, 'cubicasa_idempotency_key'), data_get($units[1][0]->fresh()->provider_data, 'cubicasa_idempotency_key'));
        $this->postJson("/api/shoots/{$shoot->id}/units/{$units[0][0]->id}/cubicasa/sync", ['shoot_service_id' => $units[1][1]->id])->assertUnprocessable();
    }

    public function test_offline_package_upload_scan_and_signed_viewer_remain_with_the_unit(): void
    {
        Storage::fake('local'); Storage::fake('public'); Queue::fake();
        [$admin, $shoot, $units] = $this->property();
        Sanctum::actingAs($admin);
        foreach ($units->take(2) as [$unit, $line]) {
            $path = tempnam(sys_get_temp_dir(), 'unit-tour');
            $zip = new ZipArchive; $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE); $zip->addFromString('index.html', '<html>Unit '.$unit->label.'</html>'); $zip->close();
            try {
                $this->post("/api/integrations/shoots/{$shoot->id}/units/{$unit->id}/iguide/offline-package", ['package' => new UploadedFile($path, 'tour.zip', 'application/zip', null, true)])->assertAccepted();
            } finally { if (is_file($path)) unlink($path); }
            $file = ShootFile::where('shoot_service_id', $line->id)->sole();
            $file->update(['scan_status' => ShootFile::SCAN_STATUS_CLEAN]);
            app(IguideOfflinePackageService::class)->markReady($file);
            $this->assertSame($file->id, data_get($unit->fresh()->provider_data, 'iguide_data.manual_offline_package.file_id'));
            $link = $this->postJson("/api/integrations/shoots/{$shoot->id}/units/{$unit->id}/iguide/offline-package/view-link")->assertOk()->json('viewer_url');
            $this->assertStringContainsString('Unit '.$unit->label, $this->get($link)->assertOk()->streamedContent());
            $public = app(ShootPublicAssetsService::class)->buildTypedPublicAssets($shoot, 'branded', false, $unit->fresh());
            $this->assertSame('published_offline_package', $public['iguide_viewer']['source']);
        }
        $this->assertNull(data_get($shoot->fresh()->iguide_data, 'manual_offline_package'));
        $client = User::factory()->create(['role' => 'client']);
        $shoot->update(['client_id' => $client->id]);
        Sanctum::actingAs($client);
        [$unit, $line] = $units[0];
        $viewerUrl = "/api/integrations/shoots/{$shoot->id}/units/{$unit->id}/iguide/offline-package/view-link";
        $this->postJson($viewerUrl)->assertOk();
        $line->update(['delivery_status' => ShootService::DELIVERY_NOT_STARTED]);
        $this->postJson($viewerUrl)->assertNotFound();
        $line->update(['delivery_status' => ShootService::DELIVERY_READY]);
        $shoot->update(['bypass_paywall' => false, 'payment_status' => 'unpaid', 'total_quote' => 100]);
        $this->postJson($viewerUrl)->assertNotFound();
        Sanctum::actingAs($admin);
        $this->postJson($viewerUrl)->assertOk();
    }

    public function test_resumable_sessions_are_independent_and_cannot_be_replayed_under_a_sibling_unit(): void
    {
        Queue::fake();
        [$admin, $shoot, $units] = $this->property(); Sanctum::actingAs($admin);
        $sessions = [];
        foreach ($units->take(2) as [$unit, $line]) {
            $sessions[] = $this->postJson("/api/integrations/shoots/{$shoot->id}/units/{$unit->id}/iguide/offline-package/uploads", ['filename' => 'tour.zip', 'size_bytes' => 100], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('upload.id');
        }
        $this->getJson("/api/integrations/shoots/{$shoot->id}/units/{$units[1][0]->id}/iguide/offline-package/uploads/{$sessions[0]}")->assertNotFound();
        $this->assertNotSame($sessions[0], $sessions[1]);
    }

    public function test_share_preview_canonical_image_and_fingerprint_keep_the_unit_and_recheck_release(): void
    {
        [$admin, $shoot, $units] = $this->property();
        config(['link_preview.enabled' => true]);
        $fingerprints = [];
        foreach ($units->take(2) as [$unit, $line]) {
            $metadata = $this->getJson("/api/public/link-previews/branded?shootId={$shoot->id}&unitId={$unit->id}")->assertOk()->json();
            $this->assertStringContainsString('unitId='.$unit->id, $metadata['url']);
            $this->assertStringContainsString('unitId='.$unit->id, $metadata['image']['url']);
            $fingerprints[] = $metadata['fingerprint'];
        }
        $this->assertNotSame($fingerprints[0], $fingerprints[1]);
        $this->getJson("/api/public/link-previews/branded?shootId={$shoot->id}")->assertNotFound();
        $units[0][1]->update(['delivery_status' => ShootService::DELIVERY_NOT_STARTED]);
        $this->getJson("/api/public/link-previews/branded?shootId={$shoot->id}&unitId={$units[0][0]->id}")->assertNotFound();
    }

    public function test_released_unit_video_is_available_before_other_units_and_unsafe_media_stays_private(): void
    {
        [$admin, $shoot, $units] = $this->property();
        [$unit, $line] = $units[0];
        $shoot->update(['status' => Shoot::STATUS_EDITING, 'workflow_status' => Shoot::STATUS_EDITING]);
        $unit->update(['tour_links' => ['video_branded' => 'https://video.test/unit101']]);
        $units[1][1]->update(['delivery_status' => ShootService::DELIVERY_NOT_STARTED]);
        foreach ([['scan_status' => ShootFile::SCAN_STATUS_INFECTED], ['is_hidden' => true]] as $attributes) {
            ShootFile::create(array_merge(['shoot_id' => $shoot->id, 'shoot_service_id' => $line->id, 'filename' => 'private.jpg', 'stored_filename' => 'private.jpg', 'path' => 'private.jpg', 'file_type' => 'image/jpeg', 'file_size' => 10, 'uploaded_by' => $admin->id, 'workflow_stage' => ShootFile::STAGE_COMPLETED], $attributes));
        }
        $this->getJson("/api/public/shoots/{$shoot->id}/branded?unitId={$unit->id}")->assertOk()->assertJsonPath('video_link', 'https://video.test/unit101')->assertJsonPath('photos', []);
        $this->getJson("/api/public/shoots/{$shoot->id}/branded?unitId={$units[1][0]->id}")->assertNotFound();
        $this->assertTrue(app(ShootUnitTourScope::class)->projectPublic($shoot->fresh(), $unit)->files->isEmpty());
    }

    public function test_resumable_unit_upload_assembles_and_finalizes_under_its_original_service_line(): void
    {
        Storage::fake('local'); Storage::fake('public'); Queue::fake();
        [$admin, $shoot, $units] = $this->property(); Sanctum::actingAs($admin);
        [$unit, $line] = $units[0];
        $path = tempnam(sys_get_temp_dir(), 'unit-chunk');
        $zip = new ZipArchive; $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE); $zip->addFromString('index.html', '<html>Unit 101 chunked</html>'); $zip->close();
        $bytes = file_get_contents($path); unlink($path);
        $url = "/api/integrations/shoots/{$shoot->id}/units/{$unit->id}/lines/{$line->id}/iguide/offline-package/uploads";
        $sessionId = $this->postJson($url, ['filename' => 'tour.zip', 'size_bytes' => strlen($bytes)], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('upload.id');
        $this->call('PUT', $url.'/'.$sessionId.'/chunks/0', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => (string) strlen($bytes), 'HTTP_CONTENT_RANGE' => 'bytes 0-'.(strlen($bytes) - 1).'/'.strlen($bytes), 'HTTP_X_CHUNK_SHA256' => hash('sha256', $bytes)], $bytes)->assertCreated();
        $this->postJson($url.'/'.$sessionId.'/complete')->assertAccepted();
        (new \App\Jobs\AssembleIguideOfflinePackageJob($sessionId))->handle(app(\App\Services\IguideOfflineChunkUploadService::class), app(\App\Services\UploadValidationService::class), app(IguideOfflinePackageService::class), app(\App\Services\ShootMediaStorageService::class));
        $file = ShootFile::where('shoot_service_id', $line->id)->sole();
        $this->assertSame($sessionId, data_get($unit->fresh()->provider_data, 'iguide_data.manual_offline_package.upload_id'));
        $this->assertNull(data_get($units[1][0]->fresh()->provider_data, 'iguide_data.manual_offline_package'));
        $file->update(['scan_status' => ShootFile::SCAN_STATUS_CLEAN]);
        (new \App\Jobs\FinalizeIguideOfflinePackageJob($file->id))->handle(app(IguideOfflinePackageService::class), app(\App\Services\ShootMediaStorageService::class));
        $this->assertSame('ready', data_get($unit->fresh()->provider_data, 'iguide_data.manual_offline_package.status'));
        $this->assertNull($shoot->fresh()->iguide_data);
    }

    public function test_legacy_public_portfolio_does_not_publish_multiunit_building_gallery(): void
    {
        [$admin, $shoot] = $this->property();
        $client = User::factory()->create(['role' => 'client']);
        $shoot->update(['client_id' => $client->id]);
        $legacy = Shoot::factory()->create(['client_id' => $client->id, 'status' => Shoot::STATUS_DELIVERED]);
        $profile = app(ShootPublicAssetsService::class)->buildPublicClientProfilePayload($client);
        $this->assertSame([$legacy->id], collect($profile['shoots'])->pluck('id')->all());
    }

    public function test_client_payload_and_property_save_withhold_pending_provider_lines_in_a_partial_unit(): void
    {
        [$admin, $shoot, $units] = $this->property();
        [$unit, $released] = $units[0];
        $client = User::factory()->create(['role' => 'client']);
        $shoot->update(['client_id' => $client->id]);
        $pending = ShootService::create(['shoot_id' => $shoot->id, 'shoot_unit_id' => $unit->id, 'service_id' => Service::factory()->create(['name' => 'iGuide Floor Plan'])->id, 'client_key' => (string) Str::uuid(), 'quantity' => 1, 'price' => 100, 'is_deliverable' => true, 'delivery_status' => ShootService::DELIVERY_NOT_STARTED]);
        $unit->update(['tour_links' => ['iguide_branded' => 'https://pending.test/iguide', 'video_branded' => 'https://pending.test/video', 'branded' => 'https://pending.test/legacy', 'property_description' => 'Safe listing text'], 'provider_data' => ['iguide_service_line_id' => $pending->id, 'iguide_tour_url' => 'https://pending.test/provider', 'iguide_data' => ['manual_offline_package' => ['status' => 'ready', 'file_id' => 4321]], 'lines' => [$pending->id => ['iguide_tour_url' => 'https://pending.test/provider']]]]);
        Sanctum::actingAs($client);
        $response = $this->getJson("/api/shoots/{$shoot->id}")->assertOk();
        $this->assertStringNotContainsString('pending.test', $response->getContent());
        $returnedUnit = collect($response->json('data.units'))->firstWhere('id', $unit->id);
        $this->assertSame([], $returnedUnit['provider_data']);
        $this->assertStringContainsString('Safe listing text', $response->getContent());
        $saved = $this->patchJson("/api/shoots/{$shoot->id}/units/{$unit->id}/tour", ['tour_links' => ['property_description' => 'Updated safe text']])->assertOk();
        $this->assertStringNotContainsString('pending.test', $saved->getContent());
        $this->assertArrayNotHasKey('provider_data', $saved->json('data'));
        $public = $this->getJson("/api/public/shoots/{$shoot->id}/branded?unitId={$unit->id}")->assertOk();
        $this->assertStringNotContainsString('pending.test', $public->getContent());
        Sanctum::actingAs($admin);
        $this->assertStringContainsString('pending.test', $this->getJson("/api/shoots/{$shoot->id}")->assertOk()->getContent());
        $pending->update(['delivery_status' => ShootService::DELIVERY_READY]);
        Sanctum::actingAs($client);
        $this->assertStringContainsString('pending.test', $this->getJson("/api/shoots/{$shoot->id}")->assertOk()->getContent());
    }
}
