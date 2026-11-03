<?php

namespace Tests\Feature;

use App\Models\{Payment, PaymentServiceAllocation, Service, Shoot, ShootFile, ShootService, User};
use App\Services\Invoices\PhotographerPayoutBasis;
use App\Services\Shoots\{ShootActiveWorkLifecycle, ShootAuthorizationSupport};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail, Notification, Queue, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootActiveWorkLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); Mail::fake(); Notification::fake(); Queue::fake(); Storage::fake('public');
        $this->travelTo(now()->setDate(2026, 11, 6)->setTime(12, 0));
    }

    private function fixture(): array
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $shoot = Shoot::factory()->create(['status' => 'delivered', 'workflow_status' => 'delivered',
            'delivery_status' => 'delivered', 'rep_id' => $rep->id, 'scheduled_at' => '2026-11-03 15:00:00',
            'scheduled_date' => '2026-11-03', 'time' => '10:00', 'timezone' => 'America/New_York',
            'completed_at' => '2026-11-04 15:00:00', 'admin_verified_at' => '2026-11-04 15:00:00',
            'photos_uploaded_at' => '2026-11-03 18:00:00', 'state' => 'VA', 'base_quote' => 100,
            'tax_percent' => 0, 'tax_amount' => 0, 'total_quote' => 100]);
        $service = Service::factory()->unbracketedPhoto()->create(['price' => 100]);
        $done = ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $service->id, 'price' => 100,
            'quantity' => 1, 'workflow_status' => 'delivered', 'delivery_status' => 'delivered',
            'delivered_at' => '2026-11-04 15:00:00', 'is_deliverable' => true]);
        Sanctum::actingAs($rep);
        return [$shoot->fresh(), $done->fresh(), $rep];
    }

    private function addService(Shoot $shoot, ShootService $done, string $intent = 'new_work'): ShootService
    {
        $service = Service::factory()->videoIntake()->create(['price' => 35, 'photographer_pay' => 12]);
        $this->patchJson('/api/shoots/'.$shoot->id, ['services' => [['id' => $done->service_id], ['id' => $service->id]],
            'service_change_intent' => $intent, 'notify_client' => false, 'notify_photographer' => false])->assertOk();
        return $shoot->serviceItems()->where('service_id', $service->id)->sole();
    }

    public function test_new_service_reactivates_the_order_and_preserves_prior_delivery_and_appointment(): void
    {
        [$shoot, $done] = $this->fixture();
        $before = $done->getRawOriginal();
        $new = $this->addService($shoot, $done);
        $shoot->refresh();
        $this->assertSame('active', $shoot->status);
        $this->assertSame('active', $shoot->workflow_status);
        $this->assertSame('partially_delivered', $shoot->delivery_status);
        $this->assertSame('2026-11-03', $shoot->scheduled_date->toDateString());
        $this->assertSame('2026-11-04 15:00:00', $shoot->admin_verified_at->format('Y-m-d H:i:s'));
        $this->assertSame($before, $done->fresh()->getRawOriginal());
        $this->assertSame('pending', $new->workflow_status);
        $this->assertSame('not_started', $new->delivery_status);
        $this->assertTrue($new->payout_snapshot['additional_work']);
        $this->assertNull(app(PhotographerPayoutBasis::class)->completion($shoot, $new));
        $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->assertJsonPath('data.display_status', 'active')
            ->assertJsonPath('data.has_active_work', true)->assertJsonPath('data.active_work_date', '2026-11-06');
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_billing_only_addition_does_not_reactivate_or_block_delivery(): void
    {
        [$shoot, $done] = $this->fixture();
        $new = $this->addService($shoot, $done, 'billing_correction');
        $shoot->refresh();
        $this->assertSame('delivered', $shoot->workflow_status);
        $this->assertNull($shoot->active_work_started_at);
        $this->assertTrue($new->payout_snapshot['billing_correction_only']);
        $this->assertSame('delivered', $shoot->syncServiceItemRollups()['delivery_status']);
    }

    public function test_active_order_is_in_progress_at_the_new_service_date_and_visible_to_assigned_roles(): void
    {
        [$shoot, $done, $rep] = $this->fixture();
        $new = $this->addService($shoot, $done);
        $photographer = User::factory()->photographer()->create();
        $editor = User::factory()->create(['role' => 'editor']);
        $new->forceFill(['scheduled_at' => '2026-11-08 01:30:00', 'photographer_id' => $photographer->id,
            'editor_id' => $editor->id, 'workflow_status' => 'scheduled'])->save();
        $shoot->refresh();
        $this->assertSame('2026-11-07', $shoot->active_work_date);
        foreach ([$rep, User::factory()->create(['role' => 'admin']), $photographer, $editor] as $viewer) {
            Sanctum::actingAs($viewer);
            $this->getJson('/api/shoots?tab=completed&scheduled_start=2026-11-07&scheduled_end=2026-11-07&no_cache=1&view=card')
                ->assertOk()->assertJsonPath('meta.count', 1)->assertJsonPath('data.0.id', $shoot->id)
                ->assertJsonPath('data.0.display_status', 'active');
        }
        Sanctum::actingAs($rep);
        $this->getJson('/api/shoots?tab=delivered&no_cache=1')->assertOk()->assertJsonPath('meta.count', 0);
        $this->getJson('/api/shoots?tab=completed&statuses[]=active&no_cache=1')->assertOk()->assertJsonPath('meta.count', 1);
    }

    public function test_completion_requires_the_new_line_and_preserves_the_first_delivery_time(): void
    {
        [$shoot, $done] = $this->fixture();
        $new = $this->addService($shoot, $done);
        $shoot->refresh()->updateWorkflowStatus('delivered');
        $this->assertSame('active', $shoot->fresh()->workflow_status);
        $new->forceFill(['workflow_status' => 'ready', 'delivery_status' => 'ready', 'ready_at' => now()])->save();
        $this->assertSame('active', $shoot->fresh()->status);
        $new->forceFill(['workflow_status' => 'delivered', 'delivery_status' => 'delivered', 'delivered_at' => now()])->save();
        $shoot->refresh();
        $this->assertSame('delivered', $shoot->workflow_status);
        $this->assertNotNull($shoot->active_work_completed_at);
        $this->assertSame('2026-11-04 15:00:00', $shoot->admin_verified_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-04 15:00:00', $shoot->completed_at->format('Y-m-d H:i:s'));
    }

    public function test_cancelling_remaining_work_closes_cycle_without_releasing_a_pending_service(): void
    {
        [$shoot, $done] = $this->fixture();
        $new = $this->addService($shoot, $done);
        $new->forceFill(['workflow_status' => 'cancelled', 'delivery_status' => 'cancelled', 'cancelled_at' => now()])->save();
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertNotNull($shoot->fresh()->active_work_completed_at);
    }

    public function test_client_keeps_access_to_existing_delivered_work_while_the_order_is_active(): void
    {
        [$shoot, $done] = $this->fixture();
        $this->addService($shoot, $done);
        $shoot->refresh();
        $client = User::findOrFail($shoot->client_id);
        Sanctum::actingAs($client);
        $this->assertTrue(app(ShootAuthorizationSupport::class)->canClientAccessShoot($shoot, $client));
        $this->assertTrue(app(ShootAuthorizationSupport::class)->isShootDeliveredForClientAccess($shoot));
        $this->getJson('/api/shoots?tab=delivered&no_cache=1')->assertOk()->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.display_status', 'active');
    }

    public function test_old_paid_download_remains_available_without_unlocking_new_unpaid_or_raw_work(): void
    {
        [$shoot, $done] = $this->fixture();
        $payment = Payment::create(['shoot_id' => $shoot->id, 'amount' => 100, 'currency' => 'USD',
            'payment_method' => 'cash', 'status' => 'completed', 'processed_at' => now()]);
        PaymentServiceAllocation::create(['payment_id' => $payment->id, 'shoot_service_id' => $done->id, 'amount' => 100]);
        $shoot->syncPaymentStatusFromRecords();
        $new = $this->addService($shoot, $done);
        $makeFile = function (ShootService $line, string $name, string $stage) use ($shoot): ShootFile {
            $path = "shoots/{$shoot->id}/completed/{$name}";
            Storage::disk('public')->put($path, 'original-file-bytes');
            return ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $line->id, 'filename' => $name,
                'stored_filename' => $name, 'path' => $path, 'file_size' => 19, 'file_type' => 'image/jpeg',
                'media_type' => $stage === 'todo' ? 'raw' : 'edited', 'workflow_stage' => $stage,
                'scan_status' => 'clean', 'uploaded_by' => $shoot->photographer_id]);
        };
        $oldFile = $makeFile($done, 'previously-delivered.jpg', 'verified');
        $newFile = $makeFile($new, 'new-unpaid.jpg', 'completed');
        $raw = $makeFile($new, 'new-raw.jpg', 'todo');
        Sanctum::actingAs(User::findOrFail($shoot->client_id));
        $oldDownload = $this->get("/api/shoots/{$shoot->id}/media/{$oldFile->id}/download");
        if ($oldDownload->getStatusCode() === 302) {
            $this->assertStringContainsString('previously-delivered.jpg', $oldDownload->headers->get('Location'));
        } else $oldDownload->assertOk();
        $this->getJson("/api/shoots/{$shoot->id}/media/{$newFile->id}/download")->assertForbidden()
            ->assertJsonPath('code', 'payment_required');
        $this->getJson("/api/shoots/{$shoot->id}/media/{$raw->id}/download")->assertForbidden();
        $visible = collect($this->getJson("/api/shoots/{$shoot->id}/files?type=edited")->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($visible->contains($oldFile->id));
        $this->assertFalse($visible->contains($raw->id));
    }

    public function test_staff_can_assign_and_cancel_the_new_line_through_the_existing_update_path(): void
    {
        [$shoot, $done] = $this->fixture();
        $new = $this->addService($shoot, $done);
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['photo', 'video']]]);
        Sanctum::actingAs($admin);
        $selection = [['id' => $done->service_id], ['id' => $new->service_id]];
        $this->patchJson('/api/shoots/'.$shoot->id, ['services' => $selection,
            'scheduled_at' => $shoot->scheduled_at->toIso8601String(), 'scheduled_date' => $shoot->scheduled_date->toDateString(),
            'photographer_id' => $shoot->photographer_id,
            'service_items' => [['service_id' => $done->service_id, 'workflow_status' => 'delivered', 'delivery_status' => 'delivered'],
                ['service_id' => $new->service_id, 'editor_id' => $editor->id]],
            'notify_client' => false, 'notify_photographer' => false])->assertOk();
        $this->assertSame($editor->id, $new->fresh()->editor_id);
        $this->assertSame('delivered', $done->fresh()->delivery_status);
        $this->patchJson('/api/shoots/'.$shoot->id, ['services' => $selection, 'service_items' => [['service_id' => $new->service_id,
            'workflow_status' => 'cancelled', 'delivery_status' => 'cancelled']],
            'notify_client' => false, 'notify_photographer' => false])->assertOk();
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertNotNull($shoot->fresh()->active_work_completed_at);
    }

    public function test_old_delivered_artist_does_not_receive_the_other_artists_active_work(): void
    {
        [$shoot, $done] = $this->fixture();
        $oldArtist = User::factory()->photographer()->create();
        $done->photographer_id = $oldArtist->id;
        $done->save();
        $new = $this->addService($shoot, $done);
        $newArtist = User::factory()->photographer()->create();
        $new->photographer_id = $newArtist->id;
        $new->save();
        Sanctum::actingAs($oldArtist);
        $this->getJson('/api/shoots?tab=completed&no_cache=1')->assertOk()->assertJsonPath('meta.count', 0);
        Sanctum::actingAs($newArtist);
        $this->getJson('/api/shoots?tab=completed&no_cache=1')->assertOk()->assertJsonPath('meta.count', 1);
    }

    public function test_hold_and_cancellation_are_preserved_for_an_open_cycle(): void
    {
        foreach (['on_hold', 'cancelled'] as $status) {
            [$shoot, $done] = $this->fixture();
            $this->addService($shoot, $done);
            Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
            $this->patchJson('/api/shoots/'.$shoot->id, ['workflow_status' => $status,
                'notify_client' => false, 'notify_photographer' => false])->assertOk();
            $shoot->refresh();
            app(ShootActiveWorkLifecycle::class)->reconcile($shoot);
            $this->assertSame($status, $shoot->fresh()->workflow_status);
            $this->assertSame($status, $shoot->fresh()->status);
            $payload = app(ShootActiveWorkLifecycle::class)->presentation($shoot);
            $this->assertFalse($payload['has_active_work']);
            $this->assertSame($status, $payload['display_status']);
            $this->getJson('/api/shoots?tab=completed&no_cache=1')->assertOk()->assertJsonPath('meta.count', 0);
        }
    }

    public function test_an_old_delivery_flag_does_not_finish_an_unfinished_service_or_allow_a_manual_bypass(): void
    {
        [$shoot, $done] = $this->fixture();
        $new = $this->addService($shoot, $done);
        $new->forceFill(['workflow_status' => 'in_progress', 'delivery_status' => 'delivered'])->save();
        $this->assertSame('active', $shoot->fresh()->workflow_status);
        $this->assertSame('partially_delivered', $shoot->fresh()->syncServiceItemRollups()['delivery_status']);
        $this->assertNull($shoot->fresh()->active_work_completed_at);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->patchJson('/api/shoots/'.$shoot->id, ['service_items' => [['service_id' => $new->service_id,
            'workflow_status' => 'delivered', 'delivery_status' => 'delivered']],
            'notify_client' => false, 'notify_photographer' => false])->assertUnprocessable();
        $this->assertSame('active', $shoot->fresh()->workflow_status);
    }

    public function test_whole_order_schedule_cannot_move_the_original_visit_and_resume_keeps_service_dates(): void
    {
        [$shoot, $done] = $this->fixture();
        $new = $this->addService($shoot, $done);
        $new->scheduled_at = '2026-11-09 15:00:00';
        $new->save();
        $shoot->refresh()->updateWorkflowStatus('on_hold');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $original = $shoot->fresh()->getRawOriginal();
        $oldLine = $done->fresh()->getRawOriginal();
        $newLine = $new->fresh()->getRawOriginal();
        $this->postJson('/api/shoots/'.$shoot->id.'/schedule', ['scheduled_at' => '2026-11-10T15:00:00Z'])->assertUnprocessable();
        $this->assertSame('on_hold', $shoot->fresh()->workflow_status);
        $this->postJson('/api/shoots/'.$shoot->id.'/schedule', ['scheduled_at' => '2026-11-03T15:00:00Z'])->assertOk();
        $shoot->refresh();
        $this->assertSame('active', $shoot->workflow_status);
        foreach (['scheduled_date', 'scheduled_at', 'time', 'photographer_id', 'admin_verified_at', 'completed_at'] as $field) {
            $this->assertSame($original[$field], $shoot->getRawOriginal($field));
        }
        $this->assertSame($oldLine, $done->fresh()->getRawOriginal());
        $this->assertSame($newLine, $new->fresh()->getRawOriginal());
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }
}
