<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConfirmedSalesShootRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_woodmire_repair_adds_one_five_minute_floor_plan_and_preserves_hdr_price(): void
    {
        Http::preventStrayRequests(); Mail::fake(); Notification::fake(); Queue::fake();
        $admin = User::factory()->admin()->create();
        User::factory()->photographer()->create(['id' => 989, 'name' => 'Jay']);
        User::factory()->photographer()->create(['id' => 1106, 'name' => 'KK - Kumnith Keo']);
        $photo = Service::factory()->create(['id' => 2, 'name' => 'HDR Photos', 'price' => 999]);
        Service::factory()->create(['id' => 17, 'name' => '2D Floor Plans', 'price' => 125, 'shoot_duration_minutes' => 5]);
        $shoot = Shoot::factory()->create(['id' => 2386, 'address' => '5156 Woodmire Ln', 'photographer_id' => 989, 'status' => 'scheduled', 'workflow_status' => 'scheduled',
            'scheduled_at' => '2026-10-09 15:00:00', 'scheduled_date' => '2026-10-09', 'time' => '11:00:00', 'timezone' => 'America/New_York']);
        $shoot->services()->attach($photo->id, ['photographer_id' => 1106, 'price' => 199, 'quantity' => 1, 'duration_minutes' => 60, 'scheduled_at' => $shoot->scheduled_at]);
        $before = $shoot->scheduled_at->toIso8601String();
        $this->artisan('shoots:reconcile-confirmed-sales-edits', ['--shoot' => 2386, '--apply' => true, '--actor' => $admin->id])->assertSuccessful();
        $shoot->refresh();
        $this->assertSame(1106, (int) $shoot->photographer_id);
        $this->assertSame($before, $shoot->scheduled_at->toIso8601String());
        $this->assertSame(199.0, (float) $shoot->serviceItems()->where('service_id', 2)->value('price'));
        $floor = $shoot->serviceItems()->where('service_id', 17)->sole();
        $this->assertSame(1106, (int) $floor->photographer_id);
        $this->assertSame(5, (int) $floor->duration_minutes);
        $this->assertSame(125.0, (float) $floor->price);
        $this->assertSame(324.0, (float) $shoot->base_quote);
        $this->artisan('shoots:reconcile-confirmed-sales-edits', ['--shoot' => 2386, '--apply' => true, '--actor' => $admin->id])->assertSuccessful();
        $this->assertSame(2, $shoot->serviceItems()->count());
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_staging_repair_is_scoped_muted_and_idempotent(): void
    {
        Http::preventStrayRequests(); Mail::fake(); Notification::fake(); Queue::fake();
        $admin = User::factory()->admin()->create();
        $harrison = User::factory()->photographer()->create(['id' => 1141, 'name' => 'Harrison Hart']);
        User::factory()->photographer()->create(['id' => 2264, 'name' => 'R/E Pro Photos Editor']);
        $photo = Service::factory()->create(['id' => 53, 'name' => 'HDR Photos & Video']);
        $staging = Service::factory()->noIntake()->create(['id' => 87, 'name' => 'Virtual staging (per image)', 'photographer_required' => false, 'shoot_duration_minutes' => 0]);
        $shoot = Shoot::factory()->create(['id' => 388, 'address' => '11413 Butterfruit Way Ellicott City', 'photographer_id' => $harrison->id, 'status' => 'scheduled', 'workflow_status' => 'scheduled']);
        $shoot->services()->attach($photo->id, ['photographer_id' => $harrison->id, 'price' => 525, 'duration_minutes' => 120]);
        $shoot->services()->attach($staging->id, ['photographer_id' => null, 'price' => 45, 'duration_minutes' => 0]);
        $this->artisan('shoots:reconcile-confirmed-sales-edits', ['--shoot' => 388])->assertSuccessful();
        $this->assertNull($shoot->serviceItems()->where('service_id', 87)->value('photographer_id'));
        $this->artisan('shoots:reconcile-confirmed-sales-edits', ['--shoot' => 388, '--apply' => true, '--actor' => $admin->id])->assertSuccessful();
        $this->assertSame(2264, (int) $shoot->serviceItems()->where('service_id', 87)->value('photographer_id'));
        $this->assertSame(1141, (int) $shoot->fresh()->photographer_id);
        $this->assertSame(1141, (int) $shoot->serviceItems()->where('service_id', 53)->value('photographer_id'));
        $this->assertSame(0, (int) $shoot->serviceItems()->where('service_id', 87)->value('duration_minutes'));
        $jobs = Queue::pushedJobs();
        $this->artisan('shoots:reconcile-confirmed-sales-edits', ['--shoot' => 388, '--apply' => true, '--actor' => $admin->id])->assertSuccessful();
        $this->assertSame($jobs, Queue::pushedJobs());
        Mail::assertNothingSent(); Notification::assertNothingSent();
        Queue::assertPushed(\App\Jobs\ProcessUpdatedShootSideEffectsJob::class, fn ($job) => $job->notifyClient === false && $job->notifyPhotographer === false);
    }
}
