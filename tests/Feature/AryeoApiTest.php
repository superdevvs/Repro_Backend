<?php

namespace Tests\Feature;

use App\Jobs\RunAryeoApiExport;
use App\Models\AryeoApiAsset;
use App\Models\AryeoApiDestination;
use App\Models\AryeoApiExport;
use App\Models\AryeoApiInstallation;
use App\Models\AryeoApiOperation;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;
use App\Services\Aryeo\Api\BridgeGuard;
use App\Services\Aryeo\Api\Client;
use App\Services\Aryeo\Api\Exports;
use App\Services\Aryeo\Api\Grants;
use App\Services\Aryeo\Api\Guard;
use App\Services\Aryeo\Api\OAuth;
use App\Services\Aryeo\Api\Operations;
use App\Services\Aryeo\Api\Requirements;
use App\Services\Aryeo\Api\Snapshots;
use App\Services\Aryeo\AryeoCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AryeoApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Shoot $shoot;

    private ShootFile $file;

    private AryeoApiInstallation $installation;

    private AryeoApiDestination $destination;

    private array $remote = [];

    private array $intents = [];

    private array $deliveries = [];

    private array $writes = [];

    private ?string $loseResponse = null;

    private bool $withdrawAfterDelivery = false;

    private string $base = '/api/integrations/aryeo/api';

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Queue::fake();
        Http::preventStrayRequests();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32)), 'aryeo_api.enabled' => true, 'aryeo_api.writes_enabled' => true,
            'aryeo_api.production_enabled' => false, 'aryeo_api.sandbox_url' => 'https://aryeo.test/v2beta1', 'aryeo_api.client_id' => Str::uuid()->toString()]);
        // CI's unprivileged runner does not belong to the production web group.
        // Exercise the same chmod/chgrp path using its own configured group.
        if (function_exists('posix_getegid')) {
            config(['aryeo_api.storage_group' => posix_getegid()]);
        }
        $this->admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['role' => 'client']);
        $this->shoot = Shoot::factory()->create(['client_id' => $client->id, 'status' => 'delivered', 'workflow_status' => 'delivered', 'admin_verified_at' => now(), 'payment_status' => 'paid']);
        $this->file = ShootFile::create(['shoot_id' => $this->shoot->id, 'filename' => 'photo.jpg', 'stored_filename' => 'photo.jpg', 'path' => 'aryeo-test/photo.jpg',
            'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => 8, 'uploaded_by' => $this->admin->id, 'workflow_stage' => 'verified', 'scan_status' => 'clean']);
        $disk = Storage::fake('local');
        $disk->put('aryeo-test/photo.jpg', 'original');
        $this->mock(\App\Services\Shoots\ShootFileAccessService::class, function ($m) use ($disk) {
            $m->shouldReceive('resolveLocalPath')->andReturn($disk->path('aryeo-test/photo.jpg'));
        });
        $requirements = Requirements::defaults();
        foreach ($requirements as &$r) {
            $r['status'] = 'sandbox_demonstrated';
            $r['evidence_url'] = 'https://evidence.test/sandbox';
        } unset($r);
        $this->installation = AryeoApiInstallation::create(['environment' => 'sandbox', 'company_id' => Str::uuid(), 'name' => 'Test', 'status' => 'connected',
            'paused' => false, 'enrolled' => true, 'allowed_client_ids' => [$client->id], 'allowed_shoot_ids' => [$this->shoot->id], 'requirements' => $requirements,
            'capabilities' => ['media' => [['key' => 'photos:image/jpeg', 'workflow' => 'ordinary', 'ingest' => true, 'listing' => true, 'product' => true, 'evidence_url' => 'https://evidence.test/photo']]],
            'credentials' => ['access_token' => 'test-private-token'], 'token_expires_at' => now()->addMinutes(15)]);
        $this->installation->refresh();
        $this->destination = AryeoApiDestination::create(['installation_id' => $this->installation->id, 'shoot_id' => $this->shoot->id, 'client_id' => $client->id,
            'workflow' => 'ordinary', 'binding_key' => hash('sha256', Str::uuid()), 'provider_job_id' => Str::uuid(), 'provider_place_id' => Str::uuid(), 'legacy_listing_id' => Str::uuid(),
            'listing_url' => 'https://www.zillow.com/homedetails/test/', 'identity' => app(AryeoCatalog::class)->identity($this->shoot->load('client')),
            'recipients' => [['email' => $client->email, 'provider_customer_id' => (string) Str::uuid()]], 'address' => ['street' => $this->shoot->address],
            'requirements' => ['photos' => 1], 'consent' => ['distribution_authorized' => true, 'dual_email_acknowledged' => true, 'intent' => 'initial', 'evidence_url' => 'https://evidence.test/consent'],
            'association_status' => 'matched', 'verified_by' => $this->admin->id, 'verified_at' => now()]);
        $this->provider();
    }

    private function provider(): void
    {
        $this->resetHttp();
        Http::fake(function ($request) {
            $path = str_replace('/v2beta1', '', parse_url($request->url(), PHP_URL_PATH));
            $body = $request->data();
            $method = $request->method();
            $data = [];
            if ($method === 'POST') {
                $this->writes[] = $path;
                if ($path === '/file-ingest-intents') {
                    $id = (string) Str::uuid();
                    $data = $this->intents[$id] = ['id' => $id, 'status' => 'completed', 'file_id' => (string) Str::uuid(), 'source_url' => $body['source_url']];
                } elseif ($path === '/place-assets') {
                    $data = ['id' => (string) Str::uuid(), 'place_id' => $this->destination->provider_place_id, 'subsection_id' => 'section-photos', 'position' => count($this->remote) + 1,
                        'title' => null, 'display_type' => 'both', 'displays_on_property_websites' => true, 'delivery_id' => null,
                        'asset' => ['id' => $body['asset_id'], 'resource_type' => $body['asset_type'], 'type' => 'image', 'mime_type' => 'image/jpeg']];
                    $this->remote[] = $data;
                } elseif ($path === '/deliveries') {
                    $id = (string) Str::uuid();
                    $data = $this->deliveries[$id] = ['id' => $id, 'job_id' => $this->destination->provider_job_id, 'company_id' => $this->installation->company_id, 'status' => 'undelivered'];
                } elseif (preg_match('~/deliveries/([^/]+)/actions/deliver~', $path, $match)) {
                    $data = $this->deliveries[$match[1]] = array_replace($this->deliveries[$match[1]], ['status' => 'delivered']);
                    foreach ($this->remote as &$row) {
                        if (! $row['delivery_id']) {
                            $row['delivery_id'] = $match[1];
                        }
                    } unset($row);
                    if ($this->withdrawAfterDelivery) {
                        $this->destination->update(['consent' => ['distribution_authorized' => false]]);
                    }
                } else {
                    throw new \RuntimeException('Unexpected test write: '.$path);
                }
                if ($this->loseResponse === $path) {
                    $this->loseResponse = null;

                    return Http::failedConnection();
                }
            } elseif ($path === '/place-assets') {
                $data = $this->remote;
            } elseif ($path === '/file-ingest-intents') {
                $data = array_values($this->intents);
            } elseif (str_starts_with($path, '/file-ingest-intents/')) {
                $data = $this->intents[basename($path)];
            } elseif ($path === '/deliveries') {
                $data = array_values($this->deliveries);
            } elseif (str_starts_with($path, '/deliveries/')) {
                $data = $this->deliveries[basename($path)];
            } elseif (str_starts_with($path, '/jobs/')) {
                $data = ['id' => $this->destination->provider_job_id, 'company_id' => $this->installation->company_id, 'legacy_listing_id' => $this->destination->legacy_listing_id];
            } elseif (str_starts_with($path, '/places/')) {
                $data = ['id' => $this->destination->provider_place_id, 'company_id' => $this->installation->company_id, 'job_id' => $this->destination->provider_job_id, 'address' => ['street' => $this->shoot->address]];
            } else {
                throw new \RuntimeException('Unexpected test read: '.$path);
            }

            return Http::response(['data' => $data, 'meta' => ['paging' => ['after_url' => null]]]);
        });
    }

    private function resetHttp(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach (AryeoApiAsset::all() as $asset) {
            $path = app(\App\Services\Aryeo\Api\FrozenOriginals::class)->path($asset);
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function approved(): AryeoApiExport
    {
        $e = app(Exports::class)->preview($this->destination, $this->admin->id);

        return app(Exports::class)->approve($e, $e->revision, $this->admin->id, now()->addHours(2)->toDateTimeString());
    }

    private function runWorker(AryeoApiExport $e, int $steps = 6): void
    {
        for ($n = 0; $n < $steps; $n++) {
            (new RunAryeoApiExport($e->id))->handle();
        }
        $e->refresh();
    }

    private function blocked(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Expected a blocked operation');
        } catch (HttpException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_staff_delivery_records_provider_receipt_without_claiming_zillow_benefits(): void
    {
        $e = $this->approved();
        app(Exports::class)->submit($e);
        $this->runWorker($e);
        $this->assertSame('delivered', $e->status);
        $this->assertSame('confirmed', $e->outcomes['delivery']);
        $this->assertSame('awaiting_verification', $e->outcomes['media']);
        $this->assertSame('eligibility_unknown', $e->outcomes['hd']);
        $this->assertSame('unverified', $e->outcomes['notification']);
        $this->assertCount(4, $this->writes);
        $this->assertNull($this->destination->fresh()->active_export_id);
        $this->assertSame('paid', $this->shoot->fresh()->payment_status);
        $this->assertNotNull($e->assets()->first()->grant_revoked_at);
    }

    public function test_lost_delivery_response_is_reconciled_without_repeating_delivery_or_handing_to_bridge(): void
    {
        $e = $this->approved();
        app(Exports::class)->submit($e);
        $this->runWorker($e, 3);
        $this->loseResponse = '/deliveries/'.$e->provider_delivery_id.'/actions/deliver';
        $this->runWorker($e, 1);
        $this->assertSame('outcome_unknown', $e->status);
        $this->blocked(fn () => app(BridgeGuard::class)->assertAvailable($this->shoot->id), 'Reconcile');
        $this->runWorker($e);
        $this->assertCount(4, $this->writes);
        $result = app(Operations::class)->reconcile($e);
        $this->assertSame(0, $result['unresolved_operations']);
        app(Exports::class)->submit($e->fresh());
        $this->runWorker($e);
        $this->assertSame('delivered', $e->status);
        $this->assertCount(4, $this->writes);
    }

    public function test_empty_lookup_preserves_unknown_and_expired_executor_cannot_be_taken_over(): void
    {
        $e = $this->approved();
        $op = AryeoApiOperation::create(['export_id' => $e->id, 'operation_key' => 'ingest:lost', 'method' => 'POST', 'path' => '/file-ingest-intents', 'request_hash' => str_repeat('a', 64), 'request' => ['source_url' => 'https://private.test/grant'], 'status' => 'unknown']);
        $this->assertSame(1, app(Operations::class)->reconcile($e)['unresolved_operations']);
        $this->assertSame('unknown', $op->fresh()->status);
        $op->update(['status' => 'confirmed']);
        $this->destination->update(['executor_token' => Str::uuid(), 'executor_expires_at' => now()->subMinute()]);
        $this->blocked(fn () => app(Guard::class)->claim($e), 'executor_stop_and_reconciliation_required');
    }

    public function test_lost_response_after_cancellation_preserves_hold_and_durable_escalation(): void
    {
        $e = $this->approved();
        $op = AryeoApiOperation::create(['export_id' => $e->id, 'operation_key' => 'deliver', 'method' => 'POST',
            'path' => '/deliveries/test/actions/deliver', 'request_hash' => str_repeat('a', 64), 'request' => [], 'status' => 'sent']);
        app(Exports::class)->cancel($e->fresh(), $this->admin->id, 'Withdrawn while provider request was in flight');
        app(Operations::class)->unknown($e, $op, 'response_unavailable');
        $this->assertSame('correction_required', $e->fresh()->status);
        $this->assertNotNull($e->fresh()->cancelled_at);
        $this->assertSame('outcome_unknown', $e->fresh()->outcomes['delivery']);
        $this->assertDatabaseHas('aryeo_api_alerts', ['key' => 'unknown:'.$op->id]);
        $this->assertTrue($e->cases()->where('kind', 'unknown_operation')->where('status', 'open')->exists());
        $op->update(['status' => 'confirmed']);
        app(Operations::class)->unknown($e, $op, 'late_worker_timeout');
        $this->assertSame('confirmed', $op->fresh()->status);
    }

    public function test_recipient_change_invalidates_approval_and_withdrawal_after_ingestion_stops_delivery(): void
    {
        $e = $this->approved();
        $old = $this->destination->recipients;
        $this->destination->update(['recipients' => [['email' => 'other@example.test', 'provider_customer_id' => 'different']]]);
        $this->blocked(fn () => app(Exports::class)->submit($e), 'approval_stale');
        $this->destination->update(['recipients' => $old]);
        app(Exports::class)->submit($e);
        $this->runWorker($e, 1);
        $this->destination->update(['consent' => ['distribution_authorized' => false]]);
        $this->runWorker($e);
        $this->assertSame('blocked', $e->status);
        $this->assertCount(1, $this->writes);
    }

    public function test_external_inventory_change_prevents_delivery_and_partial_staging_prevents_new_revision(): void
    {
        $e = $this->approved();
        app(Exports::class)->submit($e);
        $this->runWorker($e, 2);
        $foreign = $this->remote[0];
        $foreign['id'] = (string) Str::uuid();
        $foreign['asset']['id'] = (string) Str::uuid();
        $this->remote[] = $foreign;
        $this->runWorker($e);
        $this->assertSame('blocked', $e->status);
        $this->assertCount(2, $this->writes);
        $this->blocked(fn () => app(Exports::class)->preview($this->destination, $this->admin->id), 'destination_has_unfinished_export');
    }

    public function test_pause_and_cancel_prevent_further_mutations_and_preserve_staged_objects(): void
    {
        $e = $this->approved();
        app(Exports::class)->submit($e);
        $this->runWorker($e, 1);
        $this->installation->update(['paused' => true]);
        $this->runWorker($e);
        $this->assertSame('blocked', $e->status);
        $this->assertCount(1, $this->writes);
        app(Exports::class)->cancel($e, $this->admin->id, 'Permission withdrawn');
        $this->assertSame('correction_required', $e->fresh()->status);
        $this->assertNotNull($e->assets()->first()->grant_revoked_at);
        $this->assertTrue($e->cases()->where('status', 'open')->exists());
        $this->assertSame($e->id, $this->destination->fresh()->active_export_id);
    }

    public function test_original_grants_are_repeatable_support_ranges_and_revoke_immediately(): void
    {
        $e = $this->approved();
        $owner = app(Guard::class)->claim($e);
        $asset = $e->assets()->first();
        $url = app(Grants::class)->issue($asset, $owner);
        $path = parse_url($url, PHP_URL_PATH);
        $this->get($path)->assertOk()->assertStreamedContent('original');
        $this->get($path)->assertOk()->assertStreamedContent('original');
        $this->withHeader('Range', 'bytes=1-3')->get($path)->assertStatus(206)->assertStreamedContent('rig');
        $asset->update(['grant_revoked_at' => now()]);
        $this->get($path)->assertStatus(410);
    }

    public function test_expired_grant_does_not_create_another_ingest_intent(): void
    {
        $e = $this->approved();
        app(Exports::class)->submit($e);
        $this->runWorker($e, 1);
        $asset = $e->assets()->first();
        $asset->update(['grant_expires_at' => now()->subMinute(), 'provider_asset_id' => null]);
        $this->intents[$asset->ingest_id]['status'] = 'ingesting';
        $this->runWorker($e);
        $this->assertSame('blocked', $e->status);
        $this->assertCount(1, $this->writes);
    }

    public function test_service_override_releases_only_covered_files_and_revocation_blocks_again(): void
    {
        $this->shoot->update(['payment_status' => 'unpaid', 'total_quote' => 100]);
        $one = ShootService::create(['shoot_id' => $this->shoot->id, 'service_id' => $this->shoot->service_id, 'price' => 100, 'quantity' => 1, 'is_deliverable' => true, 'delivery_status' => 'delivered', 'force_unlock_delivery' => true]);
        $two = ShootService::create(['shoot_id' => $this->shoot->id, 'service_id' => \App\Models\Service::factory()->create()->id, 'price' => 100, 'quantity' => 1, 'is_deliverable' => true, 'delivery_status' => 'delivered']);
        $this->file->update(['shoot_service_id' => $one->id]);
        $other = $this->file->replicate();
        $other->shoot_service_id = $two->id;
        $other->save();
        $ready = app(AryeoCatalog::class)->readiness($this->shoot->fresh(), null, ['photos' => 1]);
        $this->assertTrue($ready['eligible']);
        $this->assertSame([$this->file->id], array_column($ready['assets'], 'id'));
        $one->update(['force_unlock_delivery' => false]);
        $this->assertFalse(app(AryeoCatalog::class)->readiness($this->shoot->fresh(), null, ['photos' => 1])['eligible']);
    }

    public function test_complimentary_and_unknown_payment_remain_distinct(): void
    {
        $this->shoot->update(['payment_status' => 'no_payment_required', 'total_quote' => 0]);
        $this->assertTrue(app(AryeoCatalog::class)->readiness($this->shoot->fresh(), null, ['photos' => 1])['eligible']);
        $this->shoot->fill(['payment_status' => 'pending', 'total_quote' => null]);
        $this->assertFalse(app(AryeoCatalog::class)->readiness($this->shoot, null, ['photos' => 1])['eligible']);
        $this->shoot->bypass_paywall = true;
        $this->assertTrue(app(AryeoCatalog::class)->readiness($this->shoot, null, ['photos' => 1])['eligible']);
    }

    public function test_paid_component_file_without_component_receipt_is_not_exported(): void
    {
        $line = ShootService::create(['shoot_id' => $this->shoot->id, 'service_id' => $this->shoot->service_id, 'price' => 100, 'quantity' => 1, 'is_deliverable' => true, 'delivery_status' => 'delivered', 'force_unlock_delivery' => true, 'delivery_requirements' => [['kind' => 'photos']]]);
        $line->forceFill(['delivery_requirements' => [['kind' => 'photos']]])->save();
        $this->file->update(['shoot_service_id' => $line->id]);
        $this->assertSame([], app(AryeoCatalog::class)->readiness($this->shoot->fresh(), null, ['photos' => 1])['assets']);
    }

    public function test_endpoint_roles_and_safe_serialization(): void
    {
        $this->getJson($this->base.'/installations')->assertUnauthorized();
        $this->actingAs($this->shoot->client)->getJson($this->base.'/installations')->assertForbidden();
        $result = $this->actingAs($this->admin)->getJson($this->base.'/installations')->assertOk();
        $this->assertStringNotContainsString('test-private-token', $result->getContent());
        $this->assertStringNotContainsString('test-private-token', DB::table('aryeo_api_installations')->value('credentials'));
        $this->withHeader('X-Impersonate-User-Id', (string) $this->shoot->client_id)->patchJson($this->base.'/installations/'.$this->installation->id, ['paused' => false])->assertForbidden();
    }

    public function test_unqualified_media_and_disabled_production_never_send(): void
    {
        $e = $this->approved();
        $this->installation->update(['capabilities' => ['media' => []]]);
        $this->blocked(fn () => app(Exports::class)->submit($e), 'media_capability_unqualified');
        $this->installation->update(['environment' => 'production']);
        $this->blocked(fn () => app(Exports::class)->submit($e), 'production_not_enabled');
        $this->assertSame([], $this->writes);
    }

    public function test_rebinding_material_fields_retires_prior_approvals_without_blocking_the_replacement_bridge(): void
    {
        $e = $this->approved();
        $d = $this->destination;
        $body = ['installation_id' => $d->installation_id, 'workflow' => 'ordinary', 'unit_id' => null,
            'provider_job_id' => $d->provider_job_id, 'provider_place_id' => $d->provider_place_id,
            'legacy_listing_id' => $d->legacy_listing_id, 'listing_url' => $d->listing_url,
            'recipients' => $d->recipients, 'requirements' => ['photos' => 2], 'consent' => $d->consent,
            'relationship_evidence_url' => 'https://evidence.test/relationship'];
        $url = '/api/shoots/'.$this->shoot->id.'/aryeo-api/destinations';
        $first = $this->actingAs($this->admin)->postJson($url, $body)->assertCreated()->json('destination.id');
        $this->assertNotNull($d->fresh()->superseded_at);
        $this->blocked(fn () => app(Exports::class)->submit($e), 'destination_superseded');
        $this->patchJson($this->base.'/destinations/'.$d->id.'/hold', ['on_hold' => false])->assertConflict();
        app(BridgeGuard::class)->assertAvailable($this->shoot->id);
        $body['requirements']['photos'] = 3;
        $second = $this->postJson($url, $body)->assertCreated()->json('destination.id');
        $this->assertNotSame($first, $second);
        $this->assertNotNull(AryeoApiDestination::findOrFail($first)->superseded_at);
        $this->assertSame(3, AryeoApiDestination::findOrFail($second)->requirements['photos']);
        $body['requirements']['photos'] = 2;
        $this->postJson($url, $body)->assertConflict();
    }

    public function test_webhooks_require_signature_deduplicate_and_do_not_claim_delivery(): void
    {
        config(['aryeo_api.webhook_secrets' => [$this->installation->id => 'test-signing-secret'], 'aryeo_api.webhook_source' => 'https://app.aryeo.com']);
        $e = $this->approved();
        $body = json_encode(['specversion' => '1.0', 'id' => 'event-1', 'source' => 'https://app.aryeo.com', 'type' => 'delivery.created', 'data' => ['id' => 'legacy-id']]);
        $timestamp = (string) time();
        $url = '/api/integrations/aryeo/webhooks/'.$this->installation->id;
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_TIMESTAMP' => $timestamp, 'HTTP_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, 'test-signing-secret'), 'HTTP_SOURCE' => 'https://app.aryeo.com', 'HTTP_IDEMPOTENCY_KEY' => 'event-1'];
        $this->call('POST', $url, [], [], [], $headers, $body)->assertStatus(202);
        $this->call('POST', $url, [], [], [], $headers, $body)->assertStatus(202);
        $this->assertSame(1, DB::table('aryeo_api_webhooks')->count());
        $this->assertSame('pending', $e->fresh()->outcomes['delivery']);
        $headers['HTTP_SIGNATURE'] = str_repeat('0', 64);
        $this->call('POST', $url, [], [], [], $headers, $body)->assertUnauthorized();
        Queue::assertPushed(\App\Jobs\ReconcileAryeoWebhook::class, 1);
    }

    public function test_verification_is_revision_specific_and_history_is_preserved(): void
    {
        $e = $this->approved();
        $payload = ['feature' => 'branding', 'status' => 'verified', 'listing_url' => $e->snapshot['listing_url'], 'revision' => $e->revision, 'evidence_url' => 'https://evidence.test/branding', 'observed_at' => now()->subSecond()->toIso8601String()];
        $this->actingAs($this->admin)->postJson($this->base.'/exports/'.$e->id.'/evidence', $payload)->assertOk();
        $payload['status'] = 'missing';
        $this->postJson($this->base.'/exports/'.$e->id.'/evidence', $payload)->assertOk()->assertJsonCount(2, 'export.evidence');
        $payload['revision'] = str_repeat('0', 64);
        $this->postJson($this->base.'/exports/'.$e->id.'/evidence', $payload)->assertConflict();
        $this->assertSame('pending', $e->fresh()->outcomes['delivery']);
    }

    public function test_refresh_uncertainty_requires_reconnect_and_never_retries_rotating_credential(): void
    {
        $this->installation->update(['token_expires_at' => now()->subMinute(), 'credentials' => ['access_token' => 'old', 'refresh_token' => 'once-only']]);
        $oauth = \Mockery::mock(OAuth::class)->makePartial();
        $oauth->shouldReceive('tokenRequest')->once()->andThrow(new \RuntimeException('lost response'));
        try {
            $oauth->accessToken($this->installation);
        } catch (\RuntimeException) {
        }
        $this->assertSame('reconnect_required', $this->installation->fresh()->status);
        $this->assertNotNull($this->installation->fresh()->refresh_started_at);
        $this->blocked(fn () => $oauth->accessToken($this->installation), 'connection_required');
    }

    public function test_reconnection_invalidates_approval_without_repointing_destination(): void
    {
        $e = $this->approved();
        $before = $this->destination->attributesToArray();
        app(OAuth::class)->store($this->installation, ['access_token' => 'new-token', 'refresh_token' => 'new-refresh'], true);
        $this->assertTrue($this->installation->fresh()->paused);
        $this->assertSame($before, $this->destination->fresh()->attributesToArray());
        $this->blocked(fn () => app(Snapshots::class)->assertCurrent($e), 'approval_stale');
    }

    public function test_refresh_result_cannot_overwrite_a_new_connection_generation(): void
    {
        $old = $this->installation->fresh();
        app(OAuth::class)->store($this->installation, ['access_token' => 'new-connection'], true);
        $this->blocked(fn () => app(OAuth::class)->store($old, ['access_token' => 'late-old-refresh']), 'authorization_connection_changed');
        $this->assertSame('new-connection', $this->installation->fresh()->credentials['access_token']);
    }

    public function test_authorization_requires_company_user_principal_and_effective_scopes(): void
    {
        $me = ['tenant_id' => $this->installation->company_id, 'tenant_type' => 'company', 'subject_type' => 'user', 'subject_id' => (string) Str::uuid(), 'actor_type' => 'application', 'authority' => []];
        $this->resetHttp();
        Http::fake(['*/me' => Http::response(['data' => $me])]);
        $this->blocked(fn () => app(OAuth::class)->assertIdentity($this->installation, 'token'), 'oauth_scopes_insufficient');
        foreach (explode(' ', config('aryeo_api.delegated_scopes')) as $scope) {
            if (str_contains($scope, ':')) {
                [$resource, $level] = explode(':', $scope);
                $me['authority'][$resource] = ['level' => $level];
            }
        }
        $this->resetHttp();
        Http::fake(['*/me' => Http::response(['data' => $me])]);
        $this->assertSame($me['subject_id'], app(OAuth::class)->assertIdentity($this->installation, 'token')['subject_id']);
        $me['subject_type'] = 'application';
        $this->resetHttp();
        Http::fake(['*/me' => Http::response(['data' => $me])]);
        $this->blocked(fn () => app(OAuth::class)->assertIdentity($this->installation, 'token'), 'oauth_company_or_principal_mismatch');
    }

    public function test_oauth_callback_is_bound_to_browser_and_single_use(): void
    {
        $state = 'opaque-state';
        $hash = hash('sha256', $state);
        $cookie = 'aryeo_oauth_'.$hash;
        DB::table('aryeo_api_oauth_states')->insert(['state_hash' => $hash, 'installation_id' => $this->installation->id,
            'actor_id' => $this->admin->id, 'browser_hash' => hash('sha256', 'same-browser'), 'kind' => 'delegate',
            'verifier' => \Illuminate\Support\Facades\Crypt::encryptString('pkce-verifier'), 'expires_at' => now()->addMinutes(5)]);
        $oauth = \Mockery::mock(OAuth::class)->makePartial();
        $oauth->shouldReceive('tokenRequest')->once()->with(\Mockery::on(fn ($fields) => $fields['code_verifier'] === 'pkce-verifier'))->andReturn(['access_token' => 'new', 'refresh_token' => 'rotate']);
        $oauth->shouldReceive('assertIdentity')->once()->andReturn(['subject_id' => 'provider-user']);
        $this->app->instance(OAuth::class, $oauth);
        $path = '/api/integrations/aryeo/oauth/callback?state='.$state.'&code=authorization-code';
        $this->get($path)->assertRedirectContains('reconnect_required');
        $this->assertNull(DB::table('aryeo_api_oauth_states')->value('consumed_at'));
        $this->withUnencryptedCookie($cookie, 'same-browser')->get($path)->assertRedirectContains('connected');
        $this->withUnencryptedCookie($cookie, 'same-browser')->get($path)->assertRedirectContains('reconnect_required');
        $this->assertSame(1, $this->installation->fresh()->authorization_generation);
    }

    public function test_inventory_pagination_uses_only_the_known_endpoint_and_requires_complete_metadata(): void
    {
        $this->resetHttp();
        Http::fakeSequence()->push(['data' => [['id' => 'one']], 'meta' => ['paging' => ['after_url' => 'https://untrusted.test/steal?paging%5Bafter%5D=cursor']]])
            ->push(['data' => [['id' => 'two']], 'meta' => ['paging' => ['after_url' => null]]]);
        $this->assertCount(2, app(Client::class)->listing($this->installation, '/links'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'untrusted.test'));
        $this->resetHttp();
        Http::fake(['*' => Http::response(['data' => []])]);
        $this->blocked(fn () => app(Client::class)->listing($this->installation, '/links'), 'provider_inventory_incomplete');
    }

    public function test_critical_incident_pauses_workflow_and_cannot_be_resolved_without_regression_evidence(): void
    {
        $e = $this->approved();
        $case = $this->actingAs($this->admin)->postJson($this->base.'/exports/'.$e->id.'/cases', ['kind' => 'wrong_destination', 'reason' => 'Wrong unit', 'owner' => 'Shubham', 'next_action' => 'Request removal'])->assertCreated()->json('case.id');
        $this->assertContains('ordinary', $this->installation->fresh()->paused_workflows);
        $this->patchJson($this->base.'/installations/'.$this->installation->id, ['paused_workflows' => []])->assertConflict();
        $data = ['evidence_url' => 'https://evidence.test/removal', 'provider_objects_accounted_for' => true, 'downstream_verified' => true];
        $this->postJson($this->base.'/cases/'.$case.'/resolve', $data)->assertUnprocessable();
        $data += ['fix_url' => 'https://evidence.test/fix', 'regression_test_url' => 'https://evidence.test/test', 'supervised_runs' => ['https://evidence.test/run1', 'https://evidence.test/run2']];
        $this->postJson($this->base.'/cases/'.$case.'/resolve', $data)->assertOk();
        $this->assertTrue($this->destination->fresh()->on_hold);
        $this->assertContains('ordinary', $this->installation->fresh()->paused_workflows);
    }

    public function test_completed_api_destination_requires_explicit_verified_bridge_handoff(): void
    {
        $e = $this->approved();
        app(Exports::class)->submit($e);
        $this->runWorker($e);
        $this->blocked(fn () => app(BridgeGuard::class)->assertAvailable($this->shoot->id), 'handoff');
        $this->actingAs($this->admin)->postJson($this->base.'/destinations/'.$this->destination->id.'/handoff', ['executor' => 'bridge', 'old_executor_stopped' => true, 'evidence_url' => 'https://evidence.test/handoff'])->assertOk();
        app(BridgeGuard::class)->assertAvailable($this->shoot->id);
        $this->assertContains('bridge_handoff_requires_reconciliation', app(Guard::class)->blockers($this->destination->fresh()));
    }

    public function test_failed_and_resumed_attempts_are_accounted_for_and_support_packet_omits_grants(): void
    {
        $e = $this->approved();
        app(Exports::class)->submit($e);
        $this->runWorker($e, 1);
        $this->installation->update(['paused' => true]);
        $this->runWorker($e);
        $this->installation->update(['paused' => false]);
        app(Exports::class)->submit($e);
        $this->runWorker($e);
        $this->assertSame(['blocked', 'delivered'], DB::table('aryeo_api_attempts')->where('export_id', $e->id)->orderBy('id')->pluck('result')->all());
        $packet = $this->actingAs($this->admin)->getJson($this->base.'/exports/'.$e->id.'/support')->assertOk();
        $this->assertStringNotContainsString('/download/', $packet->getContent());
        $this->assertStringNotContainsString('test-private-token', $packet->getContent());
        $this->assertStringNotContainsString($this->shoot->client->email, $packet->getContent());
    }

    public function test_local_rate_limit_leaves_a_known_unsent_intent_safe_to_resume(): void
    {
        $e = $this->approved();
        $owner = app(Guard::class)->claim($e);
        config(['aryeo_api.requests_per_minute' => 0]);
        $this->blocked(fn () => app(Operations::class)->write($e, $owner, 'rate-test', '/file-ingest-intents', ['source_url' => 'https://source.test/one']), 'provider_rate_limited');
        $this->assertSame('prepared', $e->operations()->first()->status);
        $this->assertSame([], $this->writes);
        config(['aryeo_api.requests_per_minute' => 400]);
        app(Operations::class)->write($e, $owner, 'rate-test', '/file-ingest-intents', ['source_url' => 'https://source.test/one']);
        $this->assertCount(1, $this->writes);
    }

    public function test_original_copy_stays_immutable_when_the_source_changes(): void
    {
        $e = $this->approved();
        $owner = app(Guard::class)->claim($e);
        $asset = $e->assets()->first();
        $url = app(Grants::class)->issue($asset, $owner);
        Storage::disk('local')->put('aryeo-test/photo.jpg', 'replaced');
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertStreamedContent('original');
        if (function_exists('posix_getegid')) {
            $path = app(\App\Services\Aryeo\Api\FrozenOriginals::class)->path($asset);
            $this->assertSame(0660, fileperms($path) & 0777);
            $this->assertSame(posix_getegid(), filegroup($path));
        }
        $this->blocked(fn () => app(Snapshots::class)->assertCurrent($e, true), 'approval_stale');
    }

    public function test_revoked_connection_or_approval_cannot_issue_a_new_download_grant(): void
    {
        $e = $this->approved();
        $owner = app(Guard::class)->claim($e);
        $this->installation->update(['status' => 'reconnect_required']);
        $this->blocked(fn () => app(Grants::class)->issue($e->assets()->first(), $owner), 'connection_required');
        $this->assertNull($e->assets()->first()->source_url);
    }

    public function test_permission_change_during_accepted_delivery_preserves_receipt_and_opens_correction(): void
    {
        $e = $this->approved();
        app(Exports::class)->submit($e);
        $this->withdrawAfterDelivery = true;
        $this->runWorker($e);
        $this->assertSame('correction_required', $e->status);
        $this->assertSame('confirmed', $e->outcomes['delivery']);
        $this->assertNotEmpty($e->receipt);
        $this->assertTrue($this->destination->fresh()->on_hold);
        $this->assertSame($e->id, $this->destination->fresh()->active_export_id);
        $this->assertTrue($e->cases()->where('kind', 'post_delivery_change')->exists());
    }
}
