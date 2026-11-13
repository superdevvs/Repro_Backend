<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Models\{Service, Shoot, ShootFile, User};
use App\Services\CubiCasaService;
use App\Services\Shoots\{DeliveryPlanService, IguidePublicViewerVerification, ProviderAutoDeliveryService, ShootActiveWorkLifecycle};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Mail, Notification, Queue, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProviderAutoDeliveryTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp(); $this->travelTo(now()->setDate(2026, 11, 6)->setTime(12, 0));
        Http::preventStrayRequests(); Queue::fake(); Mail::fake(); Notification::fake(); Storage::fake('public');
    }
    protected function tearDown(): void { $this->travelBack(); parent::tearDown(); }

    private function fixture(bool $fakeAsset = true): array
    {
        $shoot = Shoot::factory()->create(['status' => 'scheduled', 'workflow_status' => 'scheduled', 'delivery_status' => 'not_started',
            'created_at' => now()->subDays(3), 'scheduled_at' => now()->subDay(), 'scheduled_date' => '2026-11-05', 'admin_verified_at' => null, 'completed_at' => null,
            'cubicasa_order_id' => 'order-current', 'total_quote' => 250, 'payment_status' => 'unpaid']);
        $catalog = Service::factory()->noIntake()->create(['name' => 'Explicit booked provider product', 'requires_editing' => false]);
        $item = $shoot->serviceItems()->create(['service_id' => $catalog->id, 'price' => 250, 'quantity' => 1, 'is_deliverable' => true,
            'workflow_status' => 'scheduled', 'delivery_status' => 'not_started', 'scheduled_at' => now()->subDay()]);
        $item->forceFill(['delivery_requirements' => [['key' => 'floorplan', 'kind' => 'floorplan', 'label' => 'Floorplans', 'provider' => 'cubicasa']]])->save();
        $jpeg = \Illuminate\Http\UploadedFile::fake()->image('floor.jpg')->get();
        Storage::disk('public')->put('plans/current.jpg', $jpeg); Storage::disk('public')->put('plans/current-web.jpg', $jpeg);
        if ($fakeAsset) Http::fake(['https://provider.test/plan.jpg' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg'])]);
        $file = new ShootFile;
        $file->forceFill(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id, 'filename' => 'floor.jpg', 'stored_filename' => 'floor.jpg', 'path' => 'plans/current.jpg',
            'web_path' => 'plans/current-web.jpg', 'file_size' => strlen($jpeg), 'mime_type' => 'image/jpeg', 'file_type' => 'image/jpeg', 'media_type' => 'floorplan',
            'workflow_stage' => 'completed', 'scan_status' => 'clean', 'uploaded_by' => $shoot->photographer_id,
            'metadata' => ['source' => 'cubicasa', 'cubicasa_asset_key' => 'plan-current', 'provider_identity' => 'order-current',
                'original_url' => 'https://provider.test/plan.jpg'], 'created_at' => now()->subHour()])->save();
        $parsed = ['order_id' => 'order-current', 'status' => 'Ready', 'floorplans' => [['asset_key' => 'plan-current', 'type' => 'jpg', 'url' => 'https://provider.test/plan.jpg']]];
        return [$shoot->fresh(), $item->fresh(), $file, $parsed];
    }
    private function currentCubi(array $parsed): void
    {
        $client = $this->mock(CubiCasaService::class);
        $client->shouldReceive('getOrder')->with('order-current')->andReturn(['id' => 'order-current']);
        $client->shouldReceive('parseOrderData')->andReturn($parsed);
    }

    private function homeReport(ShootFile $floorplan, array &$parsed): ShootFile
    {
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
        Storage::disk('public')->put('plans/home-report.pdf', $pdf);
        Http::fake(['https://provider.test/home-report.pdf' => Http::response($pdf, 200, ['Content-Type' => 'application/pdf'])]);
        $report = $floorplan->replicate();
        $report->forceFill(['filename' => 'home-report.pdf', 'stored_filename' => 'home-report.pdf',
            'path' => 'plans/home-report.pdf', 'storage_path' => null, 'file_size' => strlen($pdf),
            'mime_type' => 'application/pdf', 'file_type' => 'application/pdf', 'workflow_stage' => 'completed',
            'verified_at' => null, 'verified_by' => null, 'created_at' => now(),
            'metadata' => ['source' => 'cubicasa', 'cubicasa_asset_key' => 'pdf_home_report_0',
                'provider_identity' => 'order-current', 'original_url' => 'https://provider.test/home-report.pdf']])->save();
        $parsed['floorplans'][] = ['asset_key' => 'pdf_home_report_0', 'component_kind' => 'report',
            'type' => 'pdf', 'url' => 'https://provider.test/home-report.pdf'];
        return $report;
    }

    public function test_verified_cubicasa_home_report_is_released_with_floorplans_and_retains_document_download_rules(): void
    {
        [$shoot, $item, $floorplan, $parsed] = $this->fixture();
        $report = $this->homeReport($floorplan, $parsed);
        $this->currentCubi($parsed);
        $provider = app(ProviderAutoDeliveryService::class);
        $provider->run($shoot->id);

        $this->assertSame('verified', $report->fresh()->workflow_stage);
        $this->assertEqualsCanonicalizing([$floorplan->id, $report->id],
            app(DeliveryPlanService::class)->releasedFileIds($shoot->fresh()));
        $this->assertSame('other', app(\App\Services\Shoots\ShootDownloadAssetClassifier::class)->type($report));
        $this->assertSame('unpaid', $shoot->fresh()->payment_status);
        Sanctum::actingAs($shoot->client);
        $url = '/api/shoots/'.$shoot->id.'/media/'.$report->id.'/download';
        $this->getJson($url)->assertForbidden();
        $shoot->forceFill(['payment_status' => 'paid'])->saveQuietly();
        $this->get($url, ['Accept' => 'application/octet-stream'])->assertOk();
        $this->getJson($url.'?format=jpg')->assertUnprocessable();
        $payload = $this->getJson('/api/shoots/'.$shoot->id.'/files?type=edited')->assertOk()->json('data');
        $this->assertTrue(collect($payload)->firstWhere('id', $report->id)['can_download']);
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->getJson($url)->assertForbidden();
        $provider->run($shoot->id);
        $this->assertDatabaseCount('shoot_delivery_receipts', 1);
    }

    public function test_cubicasa_home_report_added_after_floorplan_delivery_gets_its_own_verified_release(): void
    {
        [$shoot, $item, $floorplan, $parsed] = $this->fixture();
        $client = $this->mock(CubiCasaService::class);
        $client->shouldReceive('getOrder')->andReturn(['id' => 'order-current']);
        $client->shouldReceive('parseOrderData')->andReturnUsing(function () use (&$parsed) { return $parsed; });
        $provider = app(ProviderAutoDeliveryService::class);
        $provider->run($shoot->id);
        $deliveredAt = $item->fresh()->getRawOriginal('delivered_at');
        $this->travel(1)->minutes();
        $report = $this->homeReport($floorplan, $parsed);
        $provider->run($shoot->id);

        $this->assertSame('verified', $report->fresh()->workflow_stage);
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($report->fresh()));
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($floorplan->fresh()));
        $this->assertSame($deliveredAt, $item->fresh()->getRawOriginal('delivered_at'));
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertDatabaseCount('shoot_delivery_receipts', 2);
        $provider->run($shoot->id);
        $this->assertDatabaseCount('shoot_delivery_receipts', 2);
    }

    public function test_cubicasa_home_report_without_a_clean_scan_is_not_approved(): void
    {
        [$shoot, $item, $floorplan, $parsed] = $this->fixture();
        $report = $this->homeReport($floorplan, $parsed);
        $report->forceFill(['scan_status' => ShootFile::SCAN_STATUS_QUARANTINED])->saveQuietly();
        $this->currentCubi($parsed);
        app(ProviderAutoDeliveryService::class)->run($shoot->id);

        $this->assertSame('completed', $report->fresh()->workflow_stage);
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 0);
        Queue::assertPushed(\App\Jobs\IngestCubiCasaAssetsJob::class);
    }

    public function test_clean_preview_ready_provider_floorplan_delivers_automatically_without_impersonating_authenticated_staff(): void
    {
        [$shoot, $item, $file, $parsed] = $this->fixture(); $this->currentCubi($parsed);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        app(ProviderAutoDeliveryService::class)->run($shoot->id);
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertSame('delivered', $item->fresh()->workflow_status);
        $this->assertSame('verified', $file->fresh()->workflow_stage);
        $this->assertNull($shoot->fresh()->verified_by); $this->assertNull($file->fresh()->verified_by);
        $receipt = DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->sole();
        $this->assertNull($receipt->actor_id); $this->assertSame('floorplan', $receipt->component_key);
        $this->assertSame('unpaid', $shoot->fresh()->payment_status);
        $this->assertDatabaseHas('workflow_logs', ['shoot_id' => $shoot->id, 'action' => 'status_changed_to_delivered', 'user_id' => null]);
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 1);
        app(ProviderAutoDeliveryService::class)->run($shoot->id);
        $this->assertDatabaseCount('shoot_delivery_receipts', 1); $this->assertDatabaseCount('shoot_delivery_notification_outbox', 1);
    }

    public function test_provider_component_can_finish_while_mixed_photo_work_remains_pending(): void
    {
        [$shoot, $item, $file, $parsed] = $this->fixture(); $this->currentCubi($parsed);
        $item->forceFill(['delivery_requirements' => array_merge($item->delivery_requirements,
            [['key' => 'photo', 'kind' => 'photo', 'label' => 'Photos', 'provider' => null]])])->save();
        app(ProviderAutoDeliveryService::class)->run($shoot->id);
        $this->assertSame('verified', $file->fresh()->workflow_stage);
        $this->assertSame('editing', $shoot->fresh()->workflow_status);
        $this->assertFalse(app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot->fresh()));
        $this->assertNull($shoot->fresh()->admin_verified_at);
        $plan = app(DeliveryPlanService::class)->plan($shoot->fresh(), null);
        $this->assertSame('delivered', collect($plan['components'])->firstWhere('kind', 'floorplan')['status']);
        $this->assertSame('pending', collect($plan['components'])->firstWhere('kind', 'photo')['status']);
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 0);
    }

    public function test_off_hold_and_future_appointments_never_auto_complete(): void
    {
        [$shoot, $item, $file, $parsed] = $this->fixture();
        foreach (['off', 'hold', 'future'] as $case) {
            $shoot->forceFill(['auto_delivery_enabled' => $case !== 'off', 'status' => $case === 'hold' ? 'on_hold' : 'scheduled', 'workflow_status' => 'scheduled'])->saveQuietly();
            $item->forceFill(['scheduled_at' => $case === 'future' ? now()->addDay() : now()->subDay()])->saveQuietly();
            app(ProviderAutoDeliveryService::class)->run($shoot->id);
            $this->assertSame('completed', $file->fresh()->workflow_stage);
            $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        }
        Http::assertNothingSent();
    }

    public function test_duplicate_authenticated_events_do_not_gain_freshness_in_a_later_execution(): void
    {
        [$shoot, $item, $file] = $this->fixture();
        $shoot->forceFill(['iguide_property_id' => 'ig-current', 'iguide_tour_url' => 'https://youriguide.com/current/'])->saveQuietly();
        $item->forceFill(['delivery_requirements' => [['key' => 'tour', 'kind' => 'tour', 'label' => 'Tour', 'provider' => 'iguide']]])->saveQuietly();
        $parsed = ['property_id' => 'ig-current', 'tour_url' => $shoot->iguide_tour_url, 'delivery_readiness' => ['state' => 'ready']];
        $service = app(ProviderAutoDeliveryService::class);
        $first = $service->observe($shoot->fresh(), 'iguide', $parsed, 'authenticated_webhook', str_repeat('a', 64), false);
        $before = (array) DB::table('shoot_provider_delivery_verifications')->find($first);
        $this->travel(1)->seconds();
        $item->forceFill(['delivery_requirements' => [['key' => 'tour', 'kind' => 'tour', 'label' => 'Tour', 'provider' => 'iguide', 'execution_key' => 'line:new-execution']]])->saveQuietly();
        $second = $service->observe($shoot->fresh(), 'iguide', $parsed, 'authenticated_webhook', str_repeat('a', 64), false);
        $this->assertSame($first, $second);
        $this->assertSame($before, (array) DB::table('shoot_provider_delivery_verifications')->find($first));
        $this->assertSame('initial', $before['execution_key']);
        $this->assertNull($service->observe($shoot->fresh(), 'iguide', $parsed, 'user_agent', str_repeat('b', 64), false));
    }

    public function test_viewer_shell_and_wrong_identity_are_not_published_tours(): void
    {
        $verifier = app(IguidePublicViewerVerification::class);
        $view = ['id' => 'v-current', 'iguide' => ['id' => 'ig-current'], 'updated_at' => now()->toIso8601String(), 'dataV2' => null];
        $html = fn ($value) => '<script>const viewerParams = { viewObject: '.json_encode($value).', tags: [] };</script>';
        $this->assertNull($verifier->inspect($html($view), 'ig-current'));
        $view['dataV2'] = ['buildings' => [['floors' => [['panos' => [['id' => 1]]]]]]];
        $this->assertNotNull($verifier->inspect($html($view), 'ig-current'));
        $this->assertNull($verifier->inspect($html($view), 'ig-other'));
        $this->assertNull($verifier->inspect('<html>HTTP 200, tour is not ready yet</html>', 'ig-current'));
    }

    public function test_provider_batch_rolls_back_an_earlier_valid_component_when_a_later_selection_is_stale(): void
    {
        [$shoot, $item, $file, $parsed] = $this->fixture();
        $item->forceFill(['delivery_requirements' => array_merge($item->delivery_requirements,
            [['key' => 'tour', 'kind' => 'tour', 'label' => 'Tour', 'provider' => 'iguide']])])->saveQuietly();
        $provider = app(ProviderAutoDeliveryService::class);
        $proof = $provider->observe($shoot, 'cubicasa', $parsed, 'provider_api', $provider->canonicalFingerprint('cubicasa', $parsed), false);
        $first = ['verification_id' => $proof, 'component_id' => $item->id.':floorplan', 'provider' => 'cubicasa', 'identity_key' => 'order-current',
            'execution_key' => 'initial', 'expected_assets' => $provider->assetManifest($parsed['floorplans']),
            'files' => [['id' => $file->id, 'version' => 1, 'path' => $file->path, 'web_path' => $file->web_path, 'storage_path' => $file->storage_path,
                'updated_at' => $file->getRawOriginal('updated_at'), 'metadata_hash' => hash('sha256', json_encode($file->metadata)),
                'asset_key' => 'plan-current', 'original_url' => 'https://provider.test/plan.jpg', 'sha256' => hash('sha256', Storage::disk('public')->get($file->path)),
                'preview_sha256' => hash('sha256', Storage::disk('public')->get($file->web_path))]], 'public_viewer' => null];
        $stale = ['verification_id' => 999999, 'component_id' => $item->id.':tour', 'provider' => 'iguide', 'identity_key' => 'ig-current',
            'execution_key' => 'old-execution', 'files' => [], 'public_viewer' => null];
        $first['files'][0] += $provider->localOutputSnapshot($file);
        FinalizeShootJob::forProvider($shoot->id, [$first, $stale])->handle(app(\App\Services\ShootActivityLogger::class));
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 0);
        $this->assertSame('completed', $file->fresh()->workflow_stage);
        $this->assertSame('scheduled', $shoot->fresh()->workflow_status);
        $this->assertSame('not_started', $item->fresh()->delivery_status);
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_fresh_identical_provider_api_payload_can_verify_current_output_in_a_new_execution(): void
    {
        [$shoot, $item, $file, $parsed] = $this->fixture(); $this->currentCubi($parsed);
        $provider = app(ProviderAutoDeliveryService::class);
        $old = $provider->observe($shoot, 'cubicasa', $parsed, 'provider_api', $provider->canonicalFingerprint('cubicasa', $parsed), false);
        $firstDelivery = now()->subDay();
        $shoot->forceFill(['status' => 'active', 'workflow_status' => 'active', 'active_work_is_reopened' => true, 'active_work_started_at' => now(),
            'admin_verified_at' => $firstDelivery, 'completed_at' => $firstDelivery])->saveQuietly();
        $item->forceFill(['delivery_requirements' => [['key' => 'floorplan', 'kind' => 'floorplan', 'label' => 'Floorplans', 'provider' => 'cubicasa',
            'execution_key' => 'line:new-current', 'execution_started_at' => now()->toIso8601String()]]])->saveQuietly();
        $new = $file->replicate(); $new->created_at = now(); $new->save();
        $provider->run($shoot->id);
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertSame($firstDelivery->toIso8601String(), $shoot->fresh()->admin_verified_at->toIso8601String());
        $this->assertSame('verified', $new->fresh()->workflow_stage);
        $this->assertSame('completed', $file->fresh()->workflow_stage);
        $current = DB::table('shoot_provider_delivery_verifications')->where('execution_key', 'line:new-current')->sole();
        $this->assertNotSame($old, $current->id);
        $this->assertSame('initial', DB::table('shoot_provider_delivery_verifications')->find($old)->execution_key);
    }

    public function test_disabling_after_provider_fetch_is_rechecked_before_any_release(): void
    {
        [$shoot, $item, $file, $parsed] = $this->fixture();
        $client = $this->mock(CubiCasaService::class);
        $client->shouldReceive('getOrder')->andReturnUsing(function () use ($shoot) {
            $shoot->forceFill(['auto_delivery_enabled' => false])->saveQuietly(); return ['id' => 'order-current'];
        });
        $client->shouldReceive('parseOrderData')->andReturn($parsed);
        app(ProviderAutoDeliveryService::class)->run($shoot->id);
        $this->assertFalse($shoot->fresh()->auto_delivery_enabled);
        $this->assertSame('completed', $file->fresh()->workflow_stage);
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 0);
    }

    public function test_rotating_access_tokens_do_not_create_a_new_webhook_identity(): void
    {
        $provider = app(ProviderAutoDeliveryService::class);
        $first = ['type' => 'ready', 'iguideId' => 'ig-current', 'authtoken' => 'one', 'urls' => ['publicUrl' => 'https://youriguide.com/current/?authtoken=one']];
        $second = ['urls' => ['publicUrl' => 'https://youriguide.com/current/?authtoken=two&expires=123'], 'iguideId' => 'ig-current', 'type' => 'ready', 'authtoken' => 'two'];
        $this->assertSame($provider->webhookFingerprint($first), $provider->webhookFingerprint($second));
        $first['urls']['publicUrl'] = 'https://youriguide.com/current/?view=one&authtoken=one';
        $second['urls']['publicUrl'] = 'https://youriguide.com/current/?view=two&authtoken=two';
        $this->assertNotSame($provider->webhookFingerprint($first), $provider->webhookFingerprint($second));
    }

    public static function staleProviderCases(): array { return [['identity'], ['url'], ['bytes']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('staleProviderCases')]
    public function test_old_provider_identity_url_or_bytes_are_recovered_instead_of_approved(string $case): void
    {
            [$shoot, $item, $file, $parsed] = $this->fixture($case !== 'bytes'); $this->currentCubi($parsed);
            if ($case === 'identity') $file->forceFill(['metadata' => array_replace($file->metadata, ['provider_identity' => 'old-order'])])->saveQuietly();
            if ($case === 'url') $file->forceFill(['metadata' => array_replace($file->metadata, ['original_url' => 'https://provider.test/old-plan.jpg'])])->saveQuietly();
            if ($case === 'bytes') Http::fake(['https://provider.test/plan.jpg' => Http::response(Storage::disk('public')->get($file->path).'new-revision', 200, ['Content-Type' => 'image/jpeg'])]);
            app(ProviderAutoDeliveryService::class)->run($shoot->id);
            $this->assertSame('completed', $file->fresh()->workflow_stage);
            $this->assertSame(0, DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->count());
            Queue::assertPushed(\App\Jobs\IngestCubiCasaAssetsJob::class, fn ($job) => $job->shootId === $shoot->id && $job->refreshExisting);
    }

    public function test_financial_only_change_during_network_verification_blocks_release(): void
    {
        [$shoot, $item, $file, $parsed] = $this->fixture(); $this->currentCubi($parsed);
        Http::fake(['https://provider.test/plan.jpg' => function () use ($item, $file) {
            DB::table('shoot_service')->where('id', $item->id)->update(['payout_snapshot' => json_encode(['delivery_requirement' => 'financial_only'])]);
            return Http::response(Storage::disk('public')->get($file->path), 200, ['Content-Type' => 'image/jpeg']);
        }]);
        app(ProviderAutoDeliveryService::class)->run($shoot->id);
        $this->assertSame('completed', $file->fresh()->workflow_stage);
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 0);
    }

    public function test_staff_approval_during_network_verification_does_not_duplicate_a_receipt_or_notice(): void
    {
        [$shoot, $item, $file, $parsed] = $this->fixture(); $this->currentCubi($parsed);
        $admin = User::factory()->create(['role' => 'admin']);
        Http::fake(['https://provider.test/plan.jpg' => function () use ($shoot, $admin, $file) {
            (new FinalizeShootJob($shoot->id, $admin->id))->handle(app(\App\Services\ShootActivityLogger::class));
            return Http::response(Storage::disk('public')->get($file->path), 200, ['Content-Type' => 'image/jpeg']);
        }]);
        app(ProviderAutoDeliveryService::class)->run($shoot->id);
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertSame($admin->id, $file->fresh()->verified_by);
        $this->assertDatabaseCount('shoot_delivery_receipts', 1);
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 1);
        $this->assertSame($admin->id, (int) DB::table('shoot_delivery_receipts')->sole()->actor_id);
    }

    public function test_historical_mixed_name_package_is_not_provider_only_work(): void
    {
        [$shoot, $item, $file] = $this->fixture();
        $item->service->forceFill(['is_migration_only' => true, 'name' => 'HDR Photos + Video + iGuide'])->save();
        $item->forceFill(['delivery_requirements' => [['key' => 'tour', 'kind' => 'tour', 'label' => 'Tour', 'provider' => 'iguide']]])->save();
        $this->assertTrue(app(\App\Services\Shoots\ProviderAutoDeliverySettings::class)->supportedItems($shoot)->isEmpty());
        app(ProviderAutoDeliveryService::class)->run($shoot->id);
        Http::assertNothingSent(); $this->assertDatabaseCount('shoot_delivery_receipts', 0);
    }

    public function test_authenticated_iguide_ready_event_and_actual_published_viewer_can_finish_when_portal_reads_are_blocked(): void
    {
        [$shoot, $item, $file] = $this->fixture();
        $url = 'https://youriguide.com/current/';
        $shoot->forceFill(['iguide_property_id' => 'ig-current', 'iguide_tour_url' => $url])->saveQuietly();
        $item->forceFill(['delivery_requirements' => [['key' => 'floorplan', 'kind' => 'floorplan', 'label' => 'Floorplans', 'provider' => 'iguide'],
            ['key' => 'tour', 'kind' => 'tour', 'label' => 'Tour', 'provider' => 'iguide']]])->saveQuietly();
        $file->forceFill(['metadata' => ['source' => 'iguide', 'iguide_asset_key' => 'plan-current', 'provider_identity' => 'ig-current',
            'original_url' => 'https://provider.test/plan.jpg']])->saveQuietly();
        $parsed = ['property_id' => 'ig-current', 'tour_url' => $url, 'delivery_readiness' => ['state' => 'ready'],
            'floorplans' => [['asset_key' => 'plan-current', 'type' => 'jpg', 'url' => 'https://provider.test/plan.jpg']]];
        $provider = app(ProviderAutoDeliveryService::class);
        $provider->observe($shoot->fresh(), 'iguide', $parsed, 'authenticated_webhook', str_repeat('c', 64), false);
        $this->mock(\App\Services\IguideService::class)->shouldReceive('syncProperty')->with('ig-current')->andReturn(null);
        $view = ['id' => 'v-current', 'iguide' => ['id' => 'ig-current'], 'updated_at' => now()->toIso8601String(),
            'dataV2' => ['buildings' => [['floors' => [['panos' => [['id' => 1]]]]]]]];
        Http::fake([$url => Http::response('<script>const viewerParams = { viewObject: '.json_encode($view).', tags: [] };</script>'),
            'https://provider.test/plan.jpg' => Http::response(Storage::disk('public')->get($file->path), 200, ['Content-Type' => 'image/jpeg'])]);
        $provider->run($shoot->id);
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertSame(['floorplan', 'tour'], DB::table('shoot_delivery_receipts')->orderBy('id')->pluck('component_key')->all());
        $this->assertSame('floorplan', $file->fresh()->media_type);
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 1);
    }

    public function test_settings_require_effective_staff_management_and_explicit_supported_booked_components(): void
    {
        [$shoot] = $this->fixture(); $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->assertJsonPath('data.auto_delivery.enabled', true)->assertJsonPath('data.auto_delivery.available', true);
        $this->patchJson('/api/shoots/'.$shoot->id.'/auto-delivery', ['enabled' => false])->assertOk()->assertJsonPath('data.auto_delivery.enabled', false);
        Sanctum::actingAs(User::factory()->create(['role' => 'editor']));
        $this->patchJson('/api/shoots/'.$shoot->id.'/auto-delivery', ['enabled' => true])->assertForbidden();
        $this->assertFalse($shoot->fresh()->auto_delivery_enabled);
    }

    public function test_cubicasa_floorplans_never_complete_its_unsupported_tour_component(): void
    {
        [$shoot, $item, $file, $parsed] = $this->fixture(); $this->currentCubi($parsed);
        $item->forceFill(['delivery_requirements' => array_merge($item->delivery_requirements,
            [['key' => 'tour', 'kind' => 'tour', 'label' => 'Tour', 'provider' => 'cubicasa']])])->save();
        $provider = app(ProviderAutoDeliveryService::class);
        $this->assertSame(['floorplan'], array_column($provider->parts($item), 'kind'));
        $provider->run($shoot->id);
        $this->assertSame('verified', $file->fresh()->workflow_stage);
        $this->assertSame('editing', $shoot->fresh()->workflow_status);
        $this->assertSame(['floorplan'], DB::table('shoot_delivery_receipts')->pluck('component_key')->all());
        $this->assertFalse(collect(app(DeliveryPlanService::class)->plan($shoot->fresh(), null)['components'])->firstWhere('kind', 'tour')['resolved']);
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 0);
    }

    public function test_missing_owned_provider_asset_requests_normal_ingestion_without_approval(): void
    {
        [$shoot, $item, $file, $parsed] = $this->fixture(); $this->currentCubi($parsed);
        $file->delete();
        app(ProviderAutoDeliveryService::class)->run($shoot->id);
        Queue::assertPushed(\App\Jobs\IngestCubiCasaAssetsJob::class, fn ($job) => $job->shootId === $shoot->id && $job->refreshExisting);
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 0);
    }

    public function test_duplicate_file_entries_cannot_stand_in_for_the_complete_authenticated_asset_set(): void
    {
        [$shoot, $item, $file, $parsed] = $this->fixture();
        $parsed['floorplans'][] = ['asset_key' => 'second-plan', 'type' => 'jpg', 'url' => 'https://provider.test/second.jpg'];
        $provider = app(ProviderAutoDeliveryService::class);
        $proof = $provider->observe($shoot, 'cubicasa', $parsed, 'provider_api', $provider->canonicalFingerprint('cubicasa', $parsed), false);
        $entry = ['id' => $file->id, 'version' => 1, 'path' => $file->path, 'web_path' => $file->web_path, 'storage_path' => $file->storage_path,
            'updated_at' => $file->getRawOriginal('updated_at'), 'metadata_hash' => hash('sha256', json_encode($file->metadata)),
            'asset_key' => 'plan-current', 'original_url' => 'https://provider.test/plan.jpg', 'sha256' => hash('sha256', Storage::disk('public')->get($file->path)),
            'preview_sha256' => hash('sha256', Storage::disk('public')->get($file->web_path))];
        $selection = ['verification_id' => $proof, 'component_id' => $item->id.':floorplan', 'provider' => 'cubicasa', 'identity_key' => 'order-current',
            'execution_key' => 'initial', 'expected_assets' => $provider->assetManifest($parsed['floorplans']), 'files' => [$entry, $entry], 'public_viewer' => null];
        foreach ($selection['files'] as &$selectedFile) $selectedFile += $provider->localOutputSnapshot($file);
        unset($selectedFile);
        FinalizeShootJob::forProvider($shoot->id, [$selection])->handle(app(\App\Services\ShootActivityLogger::class));
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 0);
        $this->assertSame('completed', $file->fresh()->workflow_stage);
    }

    public function test_current_provider_not_ready_overrides_an_authenticated_ready_event(): void
    {
        [$shoot, $item] = $this->fixture();
        $shoot->forceFill(['iguide_property_id' => 'ig-current', 'iguide_tour_url' => 'https://youriguide.com/current/'])->saveQuietly();
        $item->forceFill(['delivery_requirements' => [['key' => 'tour', 'kind' => 'tour', 'label' => 'Tour', 'provider' => 'iguide']]])->saveQuietly();
        $parsed = ['property_id' => 'ig-current', 'tour_url' => $shoot->iguide_tour_url, 'delivery_readiness' => ['state' => 'ready']];
        $provider = app(ProviderAutoDeliveryService::class);
        $provider->observe($shoot->fresh(), 'iguide', $parsed, 'authenticated_webhook', str_repeat('e', 64), false);
        $parsed['delivery_readiness']['state'] = 'not_ready';
        $this->mock(\App\Services\IguideService::class)->shouldReceive('syncProperty')->andReturn($parsed);
        $provider->run($shoot->id);
        $this->assertSame('not_ready', DB::table('shoot_provider_delivery_verifications')->orderByDesc('id')->first()->state);
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        Http::assertNothingSent();
    }

    public function test_changing_the_semantic_tour_url_during_viewer_fetch_rejects_the_old_check(): void
    {
        [$shoot, $item] = $this->fixture();
        $url = 'https://youriguide.com/current/?view=one';
        $shoot->forceFill(['iguide_property_id' => 'ig-current', 'iguide_tour_url' => $url])->saveQuietly();
        $item->forceFill(['delivery_requirements' => [['key' => 'tour', 'kind' => 'tour', 'label' => 'Tour', 'provider' => 'iguide']]])->saveQuietly();
        $parsed = ['property_id' => 'ig-current', 'tour_url' => $url];
        $this->mock(\App\Services\IguideService::class)->shouldReceive('syncProperty')->andReturn($parsed);
        Http::fake([$url => function () use ($shoot) {
            $shoot->forceFill(['iguide_tour_url' => 'https://youriguide.com/current/?view=two'])->saveQuietly();
            $view = ['id' => 'v-current', 'iguide' => ['id' => 'ig-current'], 'updated_at' => now()->toIso8601String(),
                'dataV2' => ['buildings' => [['floors' => [['panos' => [['id' => 1]]]]]]]];
            return Http::response('<script>const viewerParams = { viewObject: '.json_encode($view).', tags: [] };</script>');
        }]);
        app(ProviderAutoDeliveryService::class)->run($shoot->id);
        $this->assertSame('https://youriguide.com/current/?view=two', $shoot->fresh()->iguide_tour_url);
        $this->assertSame('scheduled', $shoot->fresh()->workflow_status);
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 0);
    }

    public function test_fresh_api_signed_urls_are_used_without_refreshing_immutable_provider_evidence(): void
    {
        [$shoot, $item, $file, $parsed] = $this->fixture(false);
        $provider = app(ProviderAutoDeliveryService::class);
        $old = 'https://provider.test/plan.jpg?token=expired'; $new = 'https://provider.test/plan.jpg?token=renewed&expires=123';
        $parsed['floorplans'][0]['url'] = $old;
        $id = $provider->observe($shoot, 'cubicasa', $parsed, 'provider_api', $provider->canonicalFingerprint('cubicasa', $parsed), false);
        $before = (array) DB::table('shoot_provider_delivery_verifications')->find($id);
        $this->travel(1)->minutes(); $parsed['floorplans'][0]['url'] = $new; $this->currentCubi($parsed);
        Http::fake([$old => Http::response('expired', 403), $new => Http::response(Storage::disk('public')->get($file->path), 200)]);
        $provider->run($shoot->id);
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertSame($before, (array) DB::table('shoot_provider_delivery_verifications')->find($id));
        $this->assertDatabaseCount('shoot_provider_delivery_verifications', 1);
        Http::assertSent(fn ($request) => $request->url() === $new);
        Http::assertNotSent(fn ($request) => $request->url() === $old);
    }

    public function test_authenticated_webhook_transport_refresh_keeps_first_seen_proof_and_works_with_blocked_portal(): void
    {
        [$shoot, $item, $file, $parsed] = $this->fixture(false);
        $url = 'https://youriguide.com/current/';
        $shoot->forceFill(['iguide_property_id' => 'ig-current', 'iguide_tour_url' => $url])->saveQuietly();
        $item->forceFill(['delivery_requirements' => [['key' => 'floorplan', 'kind' => 'floorplan', 'label' => 'Floorplans', 'provider' => 'iguide'],
            ['key' => 'tour', 'kind' => 'tour', 'label' => 'Tour', 'provider' => 'iguide']]])->saveQuietly();
        $file->forceFill(['metadata' => ['source' => 'iguide', 'iguide_asset_key' => 'plan-current', 'provider_identity' => 'ig-current',
            'original_url' => 'https://provider.test/plan.jpg']])->saveQuietly();
        $old = 'https://provider.test/plan.jpg?token=expired'; $new = 'https://provider.test/plan.jpg?token=renewed';
        $parsed = ['property_id' => 'ig-current', 'tour_url' => $url, 'delivery_readiness' => ['state' => 'ready'],
            'floorplans' => [['asset_key' => 'plan-current', 'type' => 'jpg', 'url' => $old]]];
        $provider = app(ProviderAutoDeliveryService::class); $fingerprint = $provider->webhookFingerprint($parsed);
        $id = $provider->observe($shoot->fresh(), 'iguide', $parsed, 'authenticated_webhook', $fingerprint, false);
        $before = (array) DB::table('shoot_provider_delivery_verifications')->find($id);
        $this->travel(1)->minutes(); $parsed['floorplans'][0]['url'] = $new;
        $this->assertSame($fingerprint, $provider->webhookFingerprint($parsed));
        $this->assertSame($id, $provider->observe($shoot->fresh(), 'iguide', $parsed, 'authenticated_webhook', $fingerprint, false));
        $this->mock(\App\Services\IguideService::class)->shouldReceive('syncProperty')->andReturn(null);
        $view = ['id' => 'v-current', 'iguide' => ['id' => 'ig-current'], 'updated_at' => now()->toIso8601String(),
            'dataV2' => ['buildings' => [['floors' => [['panos' => [['id' => 1]]]]]]]];
        Http::fake([$old => Http::response('expired', 403), $new => Http::response(Storage::disk('public')->get($file->path), 200),
            $url => Http::response('<script>const viewerParams = { viewObject: '.json_encode($view).', tags: [] };</script>')]);
        $provider->run($shoot->id);
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertSame($before, (array) DB::table('shoot_provider_delivery_verifications')->find($id));
        $this->assertDatabaseCount('shoot_provider_delivery_verifications', 1);
        Http::assertSent(fn ($request) => $request->url() === $new);
        Http::assertNotSent(fn ($request) => $request->url() === $old);
    }

    public function test_replayed_webhook_cannot_refresh_transport_for_a_later_execution(): void
    {
        [$shoot, $item, $file] = $this->fixture(false);
        $shoot->forceFill(['iguide_property_id' => 'ig-current', 'iguide_tour_url' => 'https://youriguide.com/current/'])->saveQuietly();
        $requirements = [['key' => 'floorplan', 'kind' => 'floorplan', 'label' => 'Floorplans', 'provider' => 'iguide']];
        $item->forceFill(['delivery_requirements' => $requirements])->saveQuietly();
        $old = 'https://provider.test/plan.jpg?token=expired';
        $parsed = ['property_id' => 'ig-current', 'tour_url' => $shoot->iguide_tour_url, 'delivery_readiness' => ['state' => 'ready'],
            'floorplans' => [['asset_key' => 'plan-current', 'type' => 'jpg', 'url' => $old]]];
        $provider = app(ProviderAutoDeliveryService::class); $fingerprint = $provider->webhookFingerprint($parsed);
        $id = $provider->observe($shoot->fresh(), 'iguide', $parsed, 'authenticated_webhook', $fingerprint, false);
        $before = (array) DB::table('shoot_provider_delivery_verifications')->find($id);
        $cacheKey = 'provider:delivery:transport:'.$id.':'.hash('sha256', 'initial');
        $this->assertSame($old, \Illuminate\Support\Facades\Cache::get($cacheKey)['floorplans'][0]['url']);
        $item->forceFill(['delivery_requirements' => array_map(fn ($part) => $part + ['execution_key' => 'line:new-current',
            'execution_started_at' => now()->toIso8601String()], $requirements)])->saveQuietly();
        $parsed['floorplans'][0]['url'] = 'https://provider.test/plan.jpg?token=renewed';
        $this->assertSame($id, $provider->observe($shoot->fresh(), 'iguide', $parsed, 'authenticated_webhook', $fingerprint, false));
        $this->assertSame($old, \Illuminate\Support\Facades\Cache::get($cacheKey)['floorplans'][0]['url']);
        $this->mock(\App\Services\IguideService::class)->shouldReceive('syncProperty')->andReturn(null);
        $provider->run($shoot->id);
        $this->assertSame($before, (array) DB::table('shoot_provider_delivery_verifications')->find($id));
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        Http::assertNothingSent();
    }

    public function test_semantic_asset_url_revision_requires_new_evidence_and_normal_owned_output_recovery(): void
    {
        [$shoot, $item, $file, $parsed] = $this->fixture(false);
        $provider = app(ProviderAutoDeliveryService::class);
        $old = 'https://provider.test/plan.jpg?revision=one&token=old'; $new = 'https://provider.test/plan.jpg?revision=two&token=new';
        $parsed['floorplans'][0]['url'] = $old;
        $file->forceFill(['metadata' => array_replace($file->metadata, ['original_url' => $old])])->saveQuietly();
        $id = $provider->observe($shoot, 'cubicasa', $parsed, 'provider_api', $provider->canonicalFingerprint('cubicasa', $parsed), false);
        $before = (array) DB::table('shoot_provider_delivery_verifications')->find($id);
        $parsed['floorplans'][0]['url'] = $new; $this->currentCubi($parsed);
        Http::fake([$old => Http::response('old', 403), $new => Http::response(Storage::disk('public')->get($file->path), 200)]);
        $provider->run($shoot->id);
        $this->assertSame($before, (array) DB::table('shoot_provider_delivery_verifications')->find($id));
        $this->assertDatabaseCount('shoot_provider_delivery_verifications', 2);
        $this->assertSame('completed', $file->fresh()->workflow_stage);
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        Queue::assertPushed(\App\Jobs\IngestCubiCasaAssetsJob::class, fn ($job) => $job->shootId === $shoot->id
            && $job->refreshExisting && $job->floorplans[0]['url'] === $new);
        Http::assertNotSent(fn ($request) => $request->url() === $old);
    }

    public function test_moving_the_line_to_another_unit_during_asset_fetch_cannot_reuse_its_prior_scope_proof(): void
    {
        [$shoot, $item, $file, $parsed] = $this->fixture(false); $this->currentCubi($parsed);
        $unit = $shoot->units()->create(['client_key' => 'second-unit', 'label' => 'Other unit', 'kind' => 'unit',
            'provider_data' => ['cubicasa_order_id' => 'order-current', 'cubicasa_service_line_id' => $item->id]]);
        Http::fake(['https://provider.test/plan.jpg' => function () use ($item, $file, $unit) {
            DB::table('shoot_service')->where('id', $item->id)->update(['shoot_unit_id' => $unit->id]);
            return Http::response(Storage::disk('public')->get($file->path), 200);
        }]);
        app(ProviderAutoDeliveryService::class)->run($shoot->id);
        $this->assertSame($unit->id, $item->fresh()->shoot_unit_id);
        $proof = DB::table('shoot_provider_delivery_verifications')->sole();
        $this->assertNull(json_decode($proof->evidence, true)['unit_id']);
        $this->assertSame('scheduled', $shoot->fresh()->workflow_status);
        $this->assertSame('completed', $file->fresh()->workflow_stage);
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 0);
    }

    public static function localOutputChanges(): array { return [['original'], ['preview'], ['missing_preview']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('localOutputChanges')]
    public function test_output_bytes_and_previews_are_rechecked_under_the_writer_after_preflight(string $changed): void
    {
        [$shoot, $item, $file, $parsed] = $this->fixture(false); $this->currentCubi($parsed);
        Http::fake(['https://provider.test/plan.jpg' => function () use ($file, $changed) {
            $providerBytes = Storage::disk('public')->get($file->path);
            if ($changed === 'missing_preview') Storage::disk('public')->delete($file->web_path);
            else {
                $path = $changed === 'original' ? $file->path : $file->web_path;
                Storage::disk('public')->put($path, Storage::disk('public')->get($path).'changed-after-capture');
            }
            return Http::response($providerBytes, 200);
        }]);
        app(ProviderAutoDeliveryService::class)->run($shoot->id);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('shoot_provider_delivery_verifications', 1);
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 0);
        $this->assertSame('scheduled', $shoot->fresh()->workflow_status);
        $this->assertSame('not_started', $item->fresh()->delivery_status);
        $this->assertSame('completed', $file->fresh()->workflow_stage);
    }

    public function test_a_fast_after_commit_notification_worker_keeps_its_terminal_progress_state(): void
    {
        [$shoot, $item, $file] = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $tracker = app(\App\Services\Shoots\FinalizeProgressTracker::class); $tracker->start($shoot->id);
        $outbox = \Mockery::mock(\App\Services\Messaging\ShootDeliveryNotificationOutbox::class)->makePartial();
        $outbox->shouldReceive('dispatch')->once()->andReturnUsing(function (int $id) use ($shoot, $tracker) {
            $this->assertSame('delivered', $shoot->fresh()->workflow_status);
            $this->assertDatabaseCount('shoot_delivery_receipts', 1);
            $this->assertSame('running', collect($tracker->get($shoot->id)['stages'])->firstWhere('key', 'delivery_email')['status']);
            $tracker->stageCompleted($shoot->id, 'delivery_email', 'Fast worker completed the delivery email.');
            DB::table('shoot_delivery_notification_outbox')->where('id', $id)->update(['status' => 'completed', 'completed_at' => now()]);
            return true;
        });
        app()->instance(\App\Services\Messaging\ShootDeliveryNotificationOutbox::class, $outbox);
        (new FinalizeShootJob($shoot->id, $admin->id))->handle(app(\App\Services\ShootActivityLogger::class), $tracker);
        $stage = collect($tracker->get($shoot->id)['stages'])->firstWhere('key', 'delivery_email');
        $this->assertSame('completed', $stage['status']);
        $this->assertSame('Fast worker completed the delivery email.', $stage['message']);
        $this->assertSame('completed', DB::table('shoot_delivery_notification_outbox')->sole()->status);
    }

    public static function remoteServingModes(): array { return [['read_from_r2'], ['r2_only']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('remoteServingModes')]
    public function test_remote_serving_modes_cannot_approve_a_different_local_cached_copy(string $mode): void
    {
        [$shoot, $item, $file, $parsed] = $this->fixture(); $this->currentCubi($parsed);
        $provider = app(ProviderAutoDeliveryService::class); $media = app(\App\Services\Media\MediaStorage::class);
        $this->assertNotNull($provider->localOutputSnapshot($file));
        Storage::fake('provider-remote');
        $remoteBytes = \Illuminate\Http\UploadedFile::fake()->image('remote.jpg', 30, 40)->get();
        Storage::disk('provider-remote')->put($file->path, $remoteBytes);
        config(['media.remote_disk' => 'provider-remote', 'media.read_from_r2' => $mode === 'read_from_r2', 'media.r2_only' => $mode === 'r2_only']);
        $this->assertNotNull($media->absolutePath($file->path));
        $this->assertSame($remoteBytes, $media->get($file->path));
        $this->assertNotSame(Storage::disk('public')->get($file->path), $media->get($file->path));
        $this->assertNull($provider->localOutputSnapshot($file));
        $provider->run($shoot->id);
        $this->assertSame('scheduled', $shoot->fresh()->workflow_status);
        $this->assertSame('not_started', $item->fresh()->delivery_status);
        $this->assertSame('completed', $file->fresh()->workflow_stage);
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 0);
        Http::assertNothingSent();
    }
}
