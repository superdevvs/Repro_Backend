<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Models\{Service, Shoot, ShootFile, User};
use App\Services\ShootActivityLogger;
use App\Services\Shoots\DeliveryPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Http, Mail, Notification, Queue, Storage};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupplementalDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['clamav.scan_on_upload' => false]);
        Http::preventStrayRequests(); Queue::fake(); Mail::fake(); Notification::fake(); Storage::fake('public');
    }

    public static function roles(): array { return [['admin'], ['superadmin'], ['editing_manager']]; }

    private function finalize(Shoot $shoot, User $actor, ?array $intent = null): array
    {
        $intent ??= app(DeliveryPlanService::class)->validateSelection($shoot->fresh(), $actor, []);
        $job = new FinalizeShootJob($shoot->id, $actor->id, deliveryEventKey: $intent['idempotency_key'], deliveryIntent: $intent);
        $job->suppressSideEffects = true;
        $job->handle(app(ShootActivityLogger::class));
        return $intent;
    }

    #[DataProvider('roles')]
    public function test_added_edits_can_be_reviewed_and_downloaded_after_delivery(string $role): void
    {
        $actor = User::factory()->create(['role' => $role]);
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'status' => 'ready', 'workflow_status' => 'ready',
            'payment_status' => 'paid', 'total_quote' => 175, 'scheduled_at' => now()->subDay()]);
        $service = Service::factory()->unbracketedPhoto(1)->create(['requires_editing' => true]);
        $item = $shoot->serviceItems()->create(['service_id' => $service->id, 'quantity' => 1, 'price' => 175,
            'workflow_status' => 'ready', 'delivery_status' => 'ready', 'scheduled_at' => now()->subDay()]);
        $upload = fn (string $name) => $this->actingAs($actor)->post('/api/shoots/'.$shoot->id.'/upload', [
            'files' => [UploadedFile::fake()->image($name, 8, 8)], 'upload_type' => 'edited', 'shoot_service_id' => $item->id,
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('success_count', 1)->json('uploaded_files.0.id');
        $first = $upload('first.jpg');
        ShootFile::findOrFail($first)->forceFill(['scan_status' => 'clean'])->save();
        $this->finalize($shoot, $actor);
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $originalReceipt = DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->first();
        $deliveredAt = $item->fresh()->getRawOriginal('delivered_at');
        $additional = $upload('GG-additional.jpg');
        ShootFile::findOrFail($additional)->forceFill(['scan_status' => 'clean'])->save();
        $endpoint = '/api/shoots/'.$shoot->id.'/media/';
        $this->actingAs($client)->get($endpoint.$first.'/download')->assertOk();
        $this->getJson($endpoint.$additional.'/download')->assertForbidden();
        $plan = app(DeliveryPlanService::class)->plan($shoot->fresh(), $actor);
        $this->assertSame([$item->id.':photo'], $plan['ready_component_ids']);
        $this->assertContains('additional_output', $plan['components'][0]['reason_codes']);

        $intent = $this->finalize($shoot, $actor);
        $this->assertSame('verified', ShootFile::findOrFail($additional)->workflow_stage);
        $this->actingAs($client)->get($endpoint.$additional.'/download')->assertOk();
        $this->get($endpoint.$first.'/download')->assertOk();
        $this->assertEquals($originalReceipt, DB::table('shoot_delivery_receipts')->find($originalReceipt->id));
        $this->assertSame($deliveredAt, $item->fresh()->getRawOriginal('delivered_at'));
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertSame('paid', $shoot->fresh()->payment_status);
        $this->assertSame([], app(DeliveryPlanService::class)->plan($shoot->fresh(), $actor)['remaining_component_ids']);
        $this->finalize($shoot, $actor, $intent);
        $this->assertSame(2, DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->count());

        $this->actingAs(User::factory()->create(['role' => 'client']))->getJson($endpoint.$additional.'/download')->assertForbidden();
        $shoot->forceFill(['payment_status' => 'unpaid'])->save();
        $this->actingAs($client)->getJson($endpoint.$additional.'/download')->assertForbidden()->assertJsonPath('code', 'payment_required');
    }

    public function test_unsafe_and_hidden_additions_cannot_supply_supplemental_delivery(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create(['status' => 'ready', 'workflow_status' => 'ready', 'scheduled_at' => now()->subDay()]);
        $service = Service::factory()->unbracketedPhoto(1)->create();
        $item = $shoot->serviceItems()->create(['service_id' => $service->id, 'quantity' => 1, 'price' => 175,
            'workflow_status' => 'ready', 'delivery_status' => 'ready', 'scheduled_at' => now()->subDay()]);
        $attributes = ['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id, 'filename' => 'output.jpg', 'stored_filename' => 'output.jpg',
            'path' => 'output.jpg', 'file_size' => 100, 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg',
            'media_type' => 'edited', 'workflow_stage' => 'completed', 'scan_status' => 'clean', 'uploaded_by' => $actor->id];
        ShootFile::forceCreate($attributes);
        $this->finalize($shoot, $actor);
        foreach ([['scan_status' => 'quarantined'], ['scan_status' => 'pending'], ['is_hidden' => true], ['media_type' => 'reference'], ['is_extra' => true]] as $override) {
            ShootFile::forceCreate($override + $attributes);
        }
        app(DeliveryPlanService::class)->forgetReleasedFiles($shoot);
        $this->assertSame([], app(DeliveryPlanService::class)->plan($shoot->fresh(), $actor)['ready_component_ids']);
        $this->assertSame(1, DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->count());
    }

    public function test_manual_completion_does_not_accidentally_approve_or_require_local_media(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create(['status' => 'ready', 'workflow_status' => 'ready', 'scheduled_at' => now()->subDay()]);
        $service = Service::factory()->unbracketedPhoto(1)->create();
        $item = $shoot->serviceItems()->create(['service_id' => $service->id, 'quantity' => 1, 'price' => 175,
            'workflow_status' => 'ready', 'delivery_status' => 'ready', 'scheduled_at' => now()->subDay()]);
        $file = ShootFile::forceCreate(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id, 'filename' => 'output.jpg', 'stored_filename' => 'output.jpg',
            'path' => 'output.jpg', 'file_size' => 100, 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg',
            'media_type' => 'edited', 'workflow_stage' => 'completed', 'scan_status' => 'clean', 'uploaded_by' => $actor->id]);
        $intent = app(DeliveryPlanService::class)->validateSelection($shoot, $actor, ['component_ids' => [$item->id.':photo'],
            'mode' => 'manual_no_content', 'confirmed' => true, 'reason' => 'Client received the finished work externally.']);
        $this->finalize($shoot, $actor, $intent);
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertSame('completed', $file->fresh()->workflow_stage);
        $this->assertFalse(app(DeliveryPlanService::class)->releasedFile($file->fresh()));
        $this->assertSame([], app(DeliveryPlanService::class)->plan($shoot->fresh(), $actor)['remaining_component_ids']);
    }
}
