<?php

namespace Tests\Feature;

use App\Models\{Service, Shoot, ShootFile, ShootService, User};
use App\Services\Shoots\ShootPaymentStatusSupport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail, Notification, Queue, Storage};
use Tests\TestCase;

class PublicTourPartialDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); Mail::fake(); Notification::fake(); Http::preventStrayRequests();
        Storage::fake('local'); Storage::fake('public');
        config(['media.read_from_r2' => false, 'media.r2_only' => false, 'media.tiered_storage_enabled' => false]);
        $this->mock(ShootPaymentStatusSupport::class)->shouldReceive('reconcileStripePaymentState')
            ->andReturnUsing(fn (Shoot $shoot) => $shoot->loadMissing(['files', 'client', 'payments']));
    }

    public function test_unpaid_shared_tours_show_their_photos_without_payment_messages_or_unlocking_downloads(): void
    {
        [$shoot, $client] = $this->fixture();
        foreach (['branded', 'mls', 'g-mls'] as $variant) {
            $response = $this->getJson('/api/public/shoots/'.$shoot->id.'/'.$variant)->assertOk()->assertJsonCount(2, 'photos');
            $this->assertArrayNotHasKey('locked', $response->json());
            $this->assertArrayNotHasKey('payment_status', $response->json('shoot'));
            $this->assertArrayNotHasKey('message', $response->json());
            foreach ($response->json('photos') as $url) {
                $this->get($url)->assertOk();
                $this->assertStringContainsString('/web/', $url);
            }
        }
        $this->actingAs($client, 'sanctum');
        $this->getJson('/api/public/shoots/'.$shoot->id.'/branded')->assertOk()->assertJsonCount(2, 'photos');
        $this->getJson('/api/shoots/'.$shoot->id.'/media/download-zip?type=edited')
            ->assertForbidden()->assertJsonPath('code', 'payment_required');
        $this->assertSame('unpaid', $shoot->fresh()->payment_status);
        $this->assertSame('ready', $shoot->serviceItems()->where('delivery_status', 'ready')->sole()->delivery_status);
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_original_pending_photos_are_not_mistaken_for_new_work_after_a_previous_full_delivery(): void
    {
        [$shoot] = $this->fixture();
        $shoot->forceFill(['admin_verified_at' => now()->subDay(), 'completed_at' => now()->subDay()])->saveQuietly();
        $response = $this->getJson('/api/public/shoots/'.$shoot->id.'/branded')->assertOk()->assertJsonCount(1, 'photos');
        $this->assertStringContainsString('/done.jpg', $response->json('photos.0'));
    }

    public function test_a_new_submitted_raw_batch_still_invalidates_older_initial_edits(): void
    {
        [$shoot, $client] = $this->fixture();
        $line = $shoot->serviceItems()->where('delivery_status', 'ready')->sole();
        $file = new ShootFile;
        $file->forceFill(['shoot_id' => $shoot->id, 'shoot_service_id' => $line->id, 'filename' => 'fresh-raw.jpg',
            'stored_filename' => 'fresh-raw.jpg', 'path' => 'shoots/'.$shoot->id.'/todo/fresh-raw.jpg',
            'file_type' => 'image/jpeg', 'file_size' => 100, 'uploaded_by' => $client->id,
            'workflow_stage' => 'todo', 'media_type' => 'raw', 'scan_status' => 'clean', 'content_version' => 1,
            'metadata' => ['active_work_raw_submission' => ['cycle' => $shoot->active_work_started_at->toIso8601String(), 'content_version' => 1]],
            'created_at' => now()])->saveQuietly();
        $this->getJson('/api/public/shoots/'.$shoot->id.'/branded')->assertOk()->assertJsonCount(1, 'photos');
    }

    private function fixture(): array
    {
        return Model::withoutEvents(function (): array {
            $client = User::factory()->create(['role' => 'client']);
            $shoot = Shoot::factory()->create(['client_id' => $client->id, 'status' => 'active', 'workflow_status' => 'active',
                'delivery_status' => 'partially_delivered', 'payment_status' => 'unpaid', 'total_quote' => 200,
                'bypass_paywall' => false, 'active_work_started_at' => now()->subHour(), 'active_work_completed_at' => null,
                'admin_verified_at' => null, 'completed_at' => null, 'created_at' => now()->subDays(2)]);
            $done = ShootService::create(['shoot_id' => $shoot->id, 'service_id' => Service::factory()->unbracketedPhoto()->create()->id, 'price' => 100,
                'quantity' => 1, 'is_deliverable' => true, 'workflow_status' => 'delivered',
                'delivery_status' => 'delivered', 'delivered_at' => now()->subHour()]);
            $pending = ShootService::create(['shoot_id' => $shoot->id, 'service_id' => Service::factory()->photoVideoIntake()->create()->id, 'price' => 100,
                'quantity' => 1, 'is_deliverable' => true, 'workflow_status' => 'ready', 'delivery_status' => 'ready']);
            foreach ([
                ['done', $done, ['workflow_stage' => 'verified']],
                ['pending', $pending, ['workflow_stage' => 'completed']],
                ['hidden', $pending, ['is_hidden' => true]],
                ['quarantined', $pending, ['scan_status' => 'quarantined']],
                ['raw', $pending, ['media_type' => 'raw']],
                ['todo', $pending, ['workflow_stage' => 'todo']],
            ] as [$name, $line, $changes]) {
                $path = 'shoots/'.$shoot->id.'/web/'.$name.'.jpg';
                Storage::disk('local')->put($path, 'preview-'.$name);
                $file = new ShootFile;
                $file->forceFill($changes + ['shoot_id' => $shoot->id, 'shoot_service_id' => $line->id,
                    'filename' => $name.'.jpg', 'stored_filename' => $name.'.jpg', 'path' => 'shoots/'.$shoot->id.'/completed/'.$name.'.jpg', 'web_path' => $path,
                    'file_type' => 'image/jpeg', 'file_size' => 100, 'uploaded_by' => $client->id,
                    'media_type' => 'edited', 'workflow_stage' => 'completed', 'scan_status' => 'clean', 'is_hidden' => false,
                    'created_at' => now()->subHours(2)])->saveQuietly();
            }
            return [$shoot->fresh(), $client];
        });
    }
}
