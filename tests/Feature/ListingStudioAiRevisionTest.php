<?php

namespace Tests\Feature;

use App\Exceptions\PublicApiException;
use App\Exceptions\StudioProviderException;
use App\Jobs\ProcessStudioWorkspace;
use App\Models\ListingStudioAiRevision;
use App\Models\ListingStudioOrder;
use App\Models\ListingStudioOrderItem;
use App\Models\ListingStudioOutbox;
use App\Models\ListingStudioQuote;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\ListingStudio\ListingStudioAiRevisionService;
use App\Services\ListingStudio\ListingStudioPaidMedia;
use App\Services\ListingStudio\ListingStudioPaidPipeline;
use App\Services\ListingStudio\ListingStudioPaidStorage;
use App\Services\Studio\StudioProviderSettings;
use App\Services\Studio\WorkspaceImageOperations;
use App\Services\Studio\WorkspaceProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListingStudioAiRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['listing-studio.paid_enabled' => true, 'listing-studio.ai_revisions_enabled' => true]);
        $disk = Storage::fake('listing_studio_orders', config('filesystems.disks.listing_studio_orders'));
        $disk->makeDirectory('');
        $disk->setVisibility('', 'private');
        Storage::fake('public');
        Queue::fake();
        Http::preventStrayRequests();
        $this->mock(StudioProviderSettings::class, function ($mock): void {
            $mock->shouldReceive('route')->andReturn(['provider' => 'fal', 'model' => 'fal-ai/nano-banana-pro/edit']);
            $mock->shouldReceive('readiness')->andReturn(['ready' => true]);
        });
    }

    public function test_exact_replay_reserves_once_and_changed_instructions_cannot_reuse_key(): void
    {
        [$client, $item] = $this->item();
        $key = (string) Str::uuid();
        $service = app(ListingStudioAiRevisionService::class);
        $first = $service->request($client, $item->order_id, 'Use a lighter rug.', $key);
        $this->assertSame($first->id, $service->request($client, $item->order_id, 'Use a lighter rug.', $key)->id);
        $this->assertSame(1, ListingStudioAiRevision::count());
        $this->assertSame(1, ListingStudioOutbox::where('type', 'process_ai_revision')->count());
        $this->assertSame(1, ListingStudioOrder::count());
        $this->assertSame(100, $item->order->fresh()->paid_amount_cents);
        $this->expectException(PublicApiException::class);
        $service->request($client, $item->order_id, 'Different changes.', $key);
    }

    public function test_only_three_requests_share_the_original_edit_allowance(): void
    {
        [$client, $item] = $this->item();
        $service = app(ListingStudioAiRevisionService::class);
        for ($i = 1; $i <= 3; $i++) {
            $revision = $service->request($client, $item->order_id, 'Make the rug lighter '.$i, (string) Str::uuid());
            $this->assertSame($i, $revision->sequence);
            $this->finish($item, $revision);
        }
        $this->assertSame(0, $service->present($item->fresh())['remaining']);
        $this->assertFalse($service->present($item->fresh())['canRequest']);
        try {
            $service->request($client, $item->order_id, 'A fourth request.', (string) Str::uuid());
            $this->fail('A fourth revision must not be admitted.');
        } catch (PublicApiException $exception) {
            $this->assertSame('ai_revision_limit', $exception->errorCode);
        }
        $this->assertSame(3, ListingStudioAiRevision::count());
        $this->assertSame(1, ListingStudioOrder::count());
        $this->assertSame('settled', $item->order->fresh()->funding_status);
        Http::assertNothingSent();
    }

    public function test_active_or_uncertain_revision_blocks_a_new_submission_and_recovery_does_not_resubmit(): void
    {
        [$client, $item] = $this->item();
        $service = app(ListingStudioAiRevisionService::class);
        $revision = $service->request($client, $item->order_id, 'Make the rug lighter.', (string) Str::uuid());
        try {
            $service->request($client, $item->order_id, 'Another request.', (string) Str::uuid());
            $this->fail();
        } catch (PublicApiException $exception) {
            $this->assertSame('ai_revision_active', $exception->errorCode);
        }
        $workspace = $item->workspace->fresh();
        $operation = $workspace->operation;
        $operation['providerState']['openai-source'] = ['submitting' => true];
        $workspace->update(['status' => 'failed', 'operation' => $operation]);
        $service->sync($workspace->fresh());
        $this->assertSame('needs_attention', $revision->fresh()->status);
        try {
            $service->resume($workspace->fresh());
            $this->fail();
        } catch (StudioProviderException $exception) {
            $this->assertStringContainsString('Confirm the existing provider outcome', $exception->getMessage());
        }
        $this->assertSame(1, ListingStudioAiRevision::count());
        Queue::assertNothingPushed();
    }

    public function test_revisions_are_owner_only_and_do_not_allow_impersonation_or_staff_financial_access(): void
    {
        [$client, $item] = $this->item();
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/listing-studio/client/orders/'.$item->order_id.'/ai-revisions', ['instructions' => 'Use a lighter rug.'])->assertNotFound();
        foreach (['admin', 'superadmin', 'editing_manager', 'salesRep'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->postJson('/api/listing-studio/client/orders/'.$item->order_id.'/ai-revisions', ['instructions' => 'Use a lighter rug.'])->assertForbidden();
        }
        $this->assertSame(0, ListingStudioAiRevision::count());
    }

    public function test_unpaid_refunded_team_and_uncompleted_edits_cannot_receive_included_revisions(): void
    {
        foreach ([['funding_status' => 'pending'], ['refunded_amount_cents' => 1]] as $changes) {
            [$client, $item] = $this->item();
            $item->order->update($changes);
            try {
                app(ListingStudioAiRevisionService::class)->request($client, $item->order_id, 'Use a lighter rug.', (string) Str::uuid());
                $this->fail();
            } catch (PublicApiException $exception) {
                $this->assertSame('ai_revision_unavailable', $exception->errorCode);
            }
        }
        $this->assertSame(0, ListingStudioAiRevision::count());
    }

    public function test_tampered_operation_or_foreign_output_cannot_spend_provider_work(): void
    {
        [$client, $item] = $this->item();
        app(ListingStudioAiRevisionService::class)->request($client, $item->order_id, 'Use a lighter rug.', (string) Str::uuid());
        $workspace = $item->workspace->fresh();
        app(ListingStudioPaidPipeline::class)->authorize($workspace);
        $operation = $workspace->operation;
        $operation['payload']['outputId'] = 'foreign-output';
        $workspace->update(['operation' => $operation]);
        $this->expectException(StudioProviderException::class);
        app(ListingStudioPaidPipeline::class)->authorize($workspace->fresh());
    }

    public function test_existing_processor_finishes_one_revision_without_recharging_and_private_versions_remain_readable(): void
    {
        [$client, $item] = $this->item();
        $service = app(ListingStudioAiRevisionService::class);
        $initial = $item->output_descriptor;
        $revision = $service->request($client, $item->order_id, 'Use a lighter rug.', (string) Str::uuid());
        $this->mock(WorkspaceImageOperations::class)->shouldReceive('edit')->once()->andReturn($this->jpeg());
        $workspace = $item->workspace->fresh();
        $job = new ProcessStudioWorkspace($workspace->id, $revision->id);
        $job->handle(app(WorkspaceProcessor::class));
        $job->handle(app(WorkspaceProcessor::class));
        $this->assertSame('completed', $revision->fresh()->status);
        $this->assertSame($initial['path'], $service->version($item->fresh(), 'initial')['path']);
        $this->assertNotSame($initial['path'], $service->version($item->fresh(), $revision->id)['path']);
        $this->assertSame(100, $item->order->fresh()->paid_amount_cents);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame(1, ListingStudioOrder::count());
        Sanctum::actingAs($client);
        $this->get('/api/listing-studio/client/orders/'.$item->order_id.'/preview?variant=result&version=initial')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/api/listing-studio/client/orders/'.$item->order_id.'/download?version='.$revision->id)->assertOk();
        [$other, $foreign] = $this->item();
        Sanctum::actingAs($other);
        $this->getJson('/api/listing-studio/client/orders/'.$item->order_id.'/download?version='.$revision->id)->assertNotFound();
        Sanctum::actingAs($client);
        $this->getJson('/api/listing-studio/client/orders/'.$item->order_id.'/download?version='.$foreign->id)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_kill_switch_stops_new_revisions_but_exact_replay_still_returns_saved_request(): void
    {
        [$client, $item] = $this->item();
        $service = app(ListingStudioAiRevisionService::class);
        $key = (string) Str::uuid();
        $revision = $service->request($client, $item->order_id, 'Use a lighter rug.', $key);
        config(['listing-studio.ai_revisions_enabled' => false]);
        $this->assertSame($revision->id, $service->request($client, $item->order_id, 'Use a lighter rug.', $key)->id);
        $this->expectException(StudioProviderException::class);
        $service->assertMaySubmit($item->workspace->fresh());
    }

    private function finish(ListingStudioOrderItem $item, ListingStudioAiRevision $revision): void
    {
        $workspace = $item->workspace->fresh();
        $path = 'studio/workspaces/'.$workspace->id.'/'.$revision->id.'.jpg';
        Storage::disk('listing_studio_orders')->put($path, $this->jpeg());
        $outputs = $workspace->outputs;
        $outputs[] = ['id' => $revision->id.'-source', 'mediaId' => 'source', 'path' => $path, 'kind' => 'image', 'status' => 'completed', 'version' => $revision->sequence + 1];
        $workspace->update(['status' => 'completed', 'outputs' => $outputs]);
        app(ListingStudioAiRevisionService::class)->sync($workspace->fresh());
    }

    private function item(): array
    {
        $client = User::factory()->create(['role' => 'client']);
        $quote = ListingStudioQuote::create(['client_id' => $client->id, 'kind' => 'ai_edit', 'service' => 'grass', 'source_kind' => 'upload', 'source_id' => (string) Str::uuid(),
            'source_snapshot' => [], 'options' => [], 'pricing_snapshot' => [], 'catalog_version' => 'test', 'request_hash' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(10)]);
        $order = ListingStudioOrder::create(['client_id' => $client->id, 'quote_id' => $quote->id, 'kind' => 'ai_edit', 'service' => 'grass', 'funding_method' => 'card',
            'funding_status' => 'settled', 'total_cents' => 100, 'paid_amount_cents' => 100, 'pricing_snapshot' => [], 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64)]);
        $item = ListingStudioOrderItem::create(['order_id' => $order->id, 'client_id' => $client->id, 'kind' => 'ai_edit', 'service' => 'grass', 'status' => 'completed',
            'source_kind' => 'upload', 'source_id' => $quote->source_id, 'source_snapshot' => [], 'options' => [], 'completed_at' => now()]);
        $workspace = StudioWorkspace::create(['listing_studio_order_item_id' => $item->id, 'created_by' => $client->id, 'team_id' => null, 'name' => 'Paid photo', 'preset_id' => 'green-grass',
            'status' => 'completed', 'config' => ['ratio' => '3:2', 'frames' => [], 'adjustments' => []], 'media' => [], 'outputs' => [], 'operation' => []]);
        $storage = app(ListingStudioPaidStorage::class);
        $storage->prepareWorkspaceDirectory($workspace->id);
        $storage->prepareItemDirectory($item->id);
        $path = 'studio/workspaces/'.$workspace->id.'/initial.jpg';
        $source = 'items/'.$item->id.'/input.jpg';
        Storage::disk('listing_studio_orders')->put($path, $this->jpeg());
        Storage::disk('listing_studio_orders')->put($source, $this->jpeg());
        $workspace->update(['media' => [['id' => 'source', 'orderItemId' => $item->id, 'mediaRef' => $source, 'kind' => 'image']],
            'outputs' => [['id' => 'initial-source', 'mediaId' => 'source', 'path' => $path, 'kind' => 'image', 'status' => 'completed', 'version' => 1]]]);
        $item->update(['workspace_id' => $workspace->id, 'source_snapshot' => app(ListingStudioPaidMedia::class)->descriptor('listing_studio_orders', $source)]);
        $item->update(['output_descriptor' => app(ListingStudioPaidMedia::class)->completeOutput($item, ['disk' => 'listing_studio_orders', 'path' => $path])]);

        return [$client, $item->fresh()];
    }

    private function jpeg(): string
    {
        $photo = UploadedFile::fake()->image('photo.jpg', 120, 80);
        return file_get_contents($photo->getRealPath());
    }
}
