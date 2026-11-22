<?php

namespace Tests\Feature;

use App\Jobs\SendShootReadyEmailJob;
use App\Models\{Service, Shoot, ShootFile, ShootService, User};
use App\Services\Messaging\ShootDeliveryNotificationOutbox;
use App\Services\Shoots\{DeliveryComponentRequirements, DeliveryPlanService, ReviewedTravelFeeCatalog, ShootActiveWorkLifecycle, ShootEditingAssignmentService, ShootServiceProgress};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Artisan, DB, Http, Mail, Notification, Queue, Schema};
use PDO;
use Tests\TestCase;

class TravelFeeClassificationTest extends TestCase
{
    use RefreshDatabase;

    private array $backups = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 6)->setTime(15, 0));
        Queue::fake(); Mail::fake(); Notification::fake(); Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach ($this->backups as $path) if (is_file($path)) unlink($path);
        $this->travelBack(); parent::tearDown();
    }

    private function catalog(int $id = 63, bool $financial = false): Service
    {
        // Reserve the reviewed production IDs before unrelated factory products
        // increment into them; the migration must still reject real identity drift.
        foreach (ReviewedTravelFeeCatalog::PRODUCTS as $catalogId => $name) {
            if (! Service::find($catalogId)) Service::factory()->noIntake()->create(['id' => $catalogId, 'name' => $name,
                'price' => [61 => 25, 62 => 35, 63 => 50, 64 => 75][$catalogId], 'financial_only' => false,
                'photographer_required' => false, 'requires_editing' => true, 'is_migration_only' => false]);
        }
        $catalog = Service::findOrFail($id);
        if ($financial) $catalog->forceFill(['financial_only' => true])->saveQuietly();
        return $catalog->fresh();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_06_150000_classify_reviewed_travel_fees_as_financial.php');
    }

    public function test_catalog_migration_is_idempotent_and_does_not_rewrite_bookings_or_other_fee_work(): void
    {
        $travel = $this->catalog();
        $shoot = Shoot::factory()->create(['status' => 'scheduled', 'workflow_status' => 'scheduled']);
        $fee = $shoot->serviceItems()->create(['service_id' => $travel->id, 'price' => 50, 'quantity' => 1,
            'photographer_id' => $shoot->photographer_id, 'workflow_status' => 'scheduled', 'delivery_status' => 'not_started']);
        $before = $fee->fresh()->getRawOriginal();
        $actualEditing = Service::factory()->noIntake()->create(['name' => 'Object removal fee - Basic', 'financial_only' => false]);
        $this->migration()->up(); $this->migration()->up();
        $this->assertTrue($travel->fresh()->financial_only);
        $this->assertFalse($actualEditing->fresh()->financial_only);
        $this->assertSame($before, $fee->fresh()->getRawOriginal());
        $this->assertSame(['external'], array_column($fee->delivery_requirements, 'kind'));
        Queue::assertNotPushed(SendShootReadyEmailJob::class);
    }

    public function test_catalog_identity_drift_refuses_the_entire_migration(): void
    {
        $first = $this->catalog(61);
        $other = $this->catalog(62);
        DB::table('services')->where('id', $other->id)->update(['upload_intake_type' => 'photo']);
        try { $this->migration()->up(); $this->fail('Drifted work must not be classified.'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('identity changed', $error->getMessage()); }
        $this->assertFalse($first->fresh()->financial_only);
        $this->assertFalse($other->fresh()->financial_only);
    }

    public function test_new_travel_charge_keeps_payee_and_price_without_reopening_a_delivered_shoot(): void
    {
        $travel = $this->catalog(63, true);
        $shoot = Shoot::factory()->create(['status' => 'delivered', 'workflow_status' => 'delivered', 'delivery_status' => 'delivered',
            'admin_verified_at' => now()->subDay(), 'completed_at' => now()->subDay()]);
        $before = $shoot->fresh()->getRawOriginal();
        $fee = $shoot->serviceItems()->create(['service_id' => $travel->id, 'quantity' => 2, 'price' => 50,
            'photographer_id' => $shoot->photographer_id, 'photographer_pay' => 17, 'workflow_status' => 'pending', 'delivery_status' => 'not_started']);
        $this->assertFalse($fee->is_deliverable);
        $this->assertSame('financial_only', data_get($fee->payout_snapshot, 'delivery_requirement'));
        $this->assertSame([], $fee->delivery_requirements);
        $this->assertSame($shoot->photographer_id, $fee->photographer_id);
        $this->assertEquals(50, $fee->price); $this->assertEquals(17, $fee->photographer_pay);
        $this->assertFalse(app(ShootEditingAssignmentService::class)->serviceRequiresEditing($fee));
        $this->assertSame('not_applicable', app(ShootServiceProgress::class)->forItem($shoot->fresh(), $fee));
        $this->assertFalse(app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot->fresh()));
        foreach (['status', 'workflow_status', 'admin_verified_at', 'completed_at', 'scheduled_date'] as $key) $this->assertSame($before[$key], $shoot->fresh()->getRawOriginal($key));
        $this->assertSame([], app(DeliveryPlanService::class)->plan($shoot->fresh(), null)['components']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson('/api/shoots/'.$shoot->id)->assertOk()
            ->assertJsonPath('data.service_items.0.progress_status', 'not_applicable')
            ->assertJsonPath('data.service_items.0.is_deliverable', false);
    }

    public function test_financial_requirements_override_stale_work_and_cancellation_wins_over_applied(): void
    {
        $shoot = Shoot::factory()->create(['status' => 'scheduled', 'workflow_status' => 'scheduled']);
        $fee = new ShootService(['is_deliverable' => true, 'workflow_status' => 'pending', 'delivery_status' => 'not_started']);
        $fee->forceFill(['payout_snapshot' => ['delivery_requirement' => 'financial_only'],
            'delivery_requirements' => [['key' => 'external', 'kind' => 'external', 'label' => 'Travel']]]);
        $this->assertSame([], app(DeliveryComponentRequirements::class)->forItem($fee));
        $this->assertSame('not_applicable', app(ShootServiceProgress::class)->forItem($shoot, $fee));
        $fee->cancelled_at = now();
        $this->assertSame('cancelled', app(ShootServiceProgress::class)->forItem($shoot, $fee));
        $fee->cancelled_at = null; $shoot->status = 'cancelled';
        $this->assertSame('not_applicable', app(ShootServiceProgress::class)->forItem($shoot, $fee));
    }

    public function test_delivered_shoot_edit_api_adds_a_financial_charge_without_starting_new_work(): void
    {
        $travel = $this->catalog(63, true);
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create(['status' => 'delivered', 'workflow_status' => 'delivered', 'delivery_status' => 'delivered',
            'admin_verified_at' => now()->subDay(), 'completed_at' => now()->subDay(), 'scheduled_date' => '2026-11-05']);
        $photo = $shoot->serviceItems()->create(['service_id' => Service::factory()->unbracketedPhoto()->create()->id,
            'price' => 200, 'quantity' => 1, 'workflow_status' => 'delivered', 'delivery_status' => 'delivered', 'delivered_at' => now()->subDay()]);
        $before = $shoot->fresh()->getRawOriginal(); $photoBefore = $photo->fresh()->getRawOriginal();
        $this->actingAs($admin)->patchJson('/api/shoots/'.$shoot->id, ['services' => [['id' => $photo->service_id], ['id' => $travel->id]],
            'service_change_intent' => 'new_work', 'notify_client' => false, 'notify_photographer' => false])->assertOk();
        $fee = $shoot->serviceItems()->where('service_id', $travel->id)->sole();
        $this->assertFalse($fee->is_deliverable); $this->assertSame([], $fee->delivery_requirements);
        $this->assertSame('financial_only', data_get($fee->payout_snapshot, 'delivery_requirement'));
        $this->assertSame($photoBefore, $photo->fresh()->getRawOriginal());
        foreach (['status', 'workflow_status', 'delivery_status', 'admin_verified_at', 'completed_at', 'scheduled_date'] as $field)
            $this->assertSame($before[$field], $shoot->fresh()->getRawOriginal($field));
        $this->assertFalse(app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot->fresh()));
        Queue::assertNotPushed(SendShootReadyEmailJob::class);
    }

    private function fixture(): array
    {
        $catalog = $this->catalog();
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::withoutEvents(fn () => Shoot::factory()->create(['id' => 5946, 'status' => 'ready', 'workflow_status' => 'ready',
            'delivery_status' => 'partially_delivered', 'admin_verified_at' => null, 'completed_at' => null, 'active_work_started_at' => null,
            'active_work_completed_at' => null, 'active_work_cycle_key' => null, 'active_work_is_reopened' => null,
            'scheduled_date' => '2026-11-05', 'total_quote' => 446.5, 'payment_status' => 'unpaid', 'created_at' => now()->subDays(2)]));
        $fee = $shoot->serviceItems()->create(['service_id' => $catalog->id, 'price' => 50, 'quantity' => 1, 'photographer_pay' => 18,
            'photographer_id' => $shoot->photographer_id, 'scheduled_at' => now()->subDay(), 'workflow_status' => 'scheduled', 'delivery_status' => 'not_started']);
        $photo = $shoot->serviceItems()->create(['service_id' => Service::factory()->unbracketedPhoto()->create()->id,
            'price' => 270, 'quantity' => 1, 'workflow_status' => 'delivered', 'delivery_status' => 'delivered', 'delivered_at' => now()->subHour()]);
        $photo->forceFill(['delivery_requirements' => [['key' => 'photo', 'kind' => 'photo', 'label' => 'Photos']]])->saveQuietly();
        $file = ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $photo->id, 'filename' => 'approved.jpg', 'stored_filename' => 'approved.jpg',
            'path' => 'approved.jpg', 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => 123,
            'media_type' => 'edited', 'workflow_stage' => 'verified', 'scan_status' => 'clean', 'content_version' => 1,
            'verified_at' => now()->subHour(), 'uploaded_by' => $admin->id]);
        DB::table('shoot_delivery_receipts')->insert(['shoot_id' => $shoot->id, 'shoot_service_id' => $photo->id, 'component_key' => 'photo',
            'execution_key' => 'initial', 'method' => 'media', 'evidence' => json_encode(['files' => [['id' => $file->id, 'version' => 1]]]),
            'actor_id' => $admin->id, 'plan_revision' => str_repeat('a', 64), 'operation_key' => 'original-approval', 'delivered_at' => now()->subHour()]);
        $history = Shoot::withoutEvents(fn () => Shoot::factory()->create(['status' => 'delivered', 'workflow_status' => 'delivered',
            'delivery_status' => 'delivered', 'admin_verified_at' => now()->subYear(), 'completed_at' => now()->subYear()]));
        $oldFee = ShootService::withoutEvents(fn () => $history->serviceItems()->create(['service_id' => $catalog->id, 'price' => 45, 'quantity' => 2,
            'photographer_id' => $history->photographer_id, 'photographer_pay' => 16, 'workflow_status' => 'delivered', 'delivery_status' => 'delivered', 'delivered_at' => now()->subYear()]));
        $this->migration()->up(); Queue::fake(); app(DeliveryPlanService::class)->forgetReleasedFiles();
        return [$shoot->fresh(), $fee->fresh(), $photo->fresh(), $file->fresh(), $history->fresh(), $oldFee->fresh(), $admin];
    }

    private function plan(): array
    {
        $this->assertSame(0, Artisan::call('shoots:classify-travel-fees'));
        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_reviewed_repair_only_completes_current_order_and_replay_does_not_repeat_notices(): void
    {
        [$shoot, $fee, $photo, $file, $history, $oldFee, $admin] = $this->fixture();
        $before = $this->state(); $plan = $this->plan();
        $this->assertSame($before, $this->state()); Queue::assertNothingPushed();
        $this->assertSame(5946, $plan['completion_shoot_id']);
        $args = ['--apply' => true, '--actor' => $admin->id, '--expected-plan' => $plan['plan_hash'], '--backup-path' => $this->backup()];
        $this->artisan('shoots:classify-travel-fees', $args)->assertSuccessful();
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertNotNull($shoot->fresh()->completed_at);
        $this->assertNull($shoot->fresh()->active_work_started_at);
        $this->assertSame('unpaid', $shoot->fresh()->payment_status);
        foreach ([$fee, $oldFee] as $item) {
            $original = collect($before['shoot_service'])->firstWhere('id', $item->id);
            $current = $item->fresh()->getRawOriginal();
            foreach (['is_deliverable', 'payout_snapshot', 'delivery_requirements'] as $key) { unset($original[$key], $current[$key]); }
            $this->assertSame($original, $current);
            $this->assertFalse($item->fresh()->is_deliverable);
            $this->assertSame([], $item->fresh()->delivery_requirements);
        }
        $this->assertSame(collect($before['shoots'])->firstWhere('id', $history->id), $history->fresh()->getRawOriginal());
        $this->assertSame(collect($before['shoot_service'])->firstWhere('id', $photo->id), $photo->fresh()->getRawOriginal());
        $this->assertSame($before['shoot_files'], $this->state()['shoot_files']);
        $this->assertSame($before['shoot_delivery_receipts'], $this->state()['shoot_delivery_receipts']);
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 1);
        $this->assertDatabaseCount('client_delivery_notifications', 1);
        $this->assertDatabaseHas('shoot_delivery_notification_outbox', ['shoot_id' => 5946, 'is_full_order_delivery' => true, 'cycle_key' => 'initial']);
        $this->assertDatabaseHas('workflow_logs', ['shoot_id' => 5946, 'action' => 'status_changed_to_delivered', 'user_id' => $admin->id]);
        $after = $this->state();
        $this->artisan('shoots:classify-travel-fees', $args)->expectsOutput('Already classified; no changes.')->assertSuccessful();
        $this->assertSame($after, $this->state());
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 1); $this->assertDatabaseCount('client_delivery_notifications', 1);
        $auditCount = DB::table('shoot_activity_logs')->where('action', 'reviewed_travel_fees_classified')->count();
        $replanned = $this->plan(); $this->assertSame(0, $replanned['change_count']);
        $this->artisan('shoots:classify-travel-fees', array_replace($args, ['--expected-plan' => $replanned['plan_hash'], '--backup-path' => $this->backup()]))
            ->expectsOutput('Already classified; no changes.')->assertSuccessful();
        $this->assertSame($auditCount, DB::table('shoot_activity_logs')->where('action', 'reviewed_travel_fees_classified')->count());
        $this->assertSame($after, $this->state());
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_wrong_plan_or_missing_backup_refuses_without_any_business_writes(): void
    {
        $fixture = $this->fixture(); $plan = $this->plan(); $before = $this->state();
        $args = ['--apply' => true, '--actor' => $fixture[6]->id, '--expected-plan' => $plan['plan_hash']];
        $this->artisan('shoots:classify-travel-fees', array_replace($args, ['--expected-plan' => str_repeat('b', 64)]))->assertFailed();
        $this->artisan('shoots:classify-travel-fees', $args)->assertFailed();
        $this->assertSame($before, $this->state()); Queue::assertNothingPushed();
    }

    public function test_revoked_real_work_or_suspended_current_order_refuses_the_correction(): void
    {
        [$shoot] = $this->fixture();
        DB::table('shoot_delivery_receipts')->update(['revoked_at' => now()]);
        $before = $this->state();
        $this->artisan('shoots:classify-travel-fees')->expectsOutputToContain('Real required work remains pending')->assertFailed();
        $this->assertSame($before, $this->state());
        DB::table('shoots')->where('id', $shoot->id)->update(['status' => 'on_hold']);
        $before = $this->state();
        $this->artisan('shoots:classify-travel-fees')->expectsOutputToContain('outside the reviewed')->assertFailed();
        $this->assertSame($before, $this->state()); Queue::assertNothingPushed();
    }

    public function test_late_failure_rolls_back_classification_completion_and_notification_intent(): void
    {
        $fixture = $this->fixture(); $plan = $this->plan(); $before = $this->state();
        $real = new ShootDeliveryNotificationOutbox;
        $mock = $this->mock(ShootDeliveryNotificationOutbox::class);
        $mock->shouldReceive('record')->once()->andReturnUsing(function (...$args) use ($real) { $real->record(...$args); throw new \RuntimeException('Simulated late failure.'); });
        $this->artisan('shoots:classify-travel-fees', ['--apply' => true, '--actor' => $fixture[6]->id,
            '--expected-plan' => $plan['plan_hash'], '--backup-path' => $this->backup()])->assertFailed();
        $this->assertSame($before, $this->state());
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 0); $this->assertDatabaseCount('client_delivery_notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_existing_full_notification_or_media_owned_by_a_fee_refuses_repair(): void
    {
        [$shoot, $fee, , $file] = $this->fixture();
        DB::table('shoot_files')->where('id', $file->id)->update(['shoot_service_id' => $fee->id]);
        $before = $this->state();
        $this->artisan('shoots:classify-travel-fees')->expectsOutputToContain('owns media')->assertFailed();
        $this->assertSame($before, $this->state());
        DB::table('shoot_files')->where('id', $file->id)->update(['shoot_service_id' => $file->shoot_service_id]);
        DB::table('shoot_delivery_notification_outbox')->insert(['shoot_id' => $shoot->id, 'deduplication_key' => str_repeat('c', 64),
            'operation_key' => 'prior-full', 'cycle_key' => 'initial', 'is_full_order_delivery' => true,
            'service_item_ids' => '[]', 'status' => 'completed', 'attempt_count' => 1, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $before = $this->state();
        $this->artisan('shoots:classify-travel-fees')->expectsOutputToContain('already has full-delivery')->assertFailed();
        $this->assertSame($before, $this->state());
    }

    private function state(): array
    {
        $out = [];
        foreach (['services', 'shoots', 'shoot_service', 'shoot_files', 'shoot_file_versions', 'shoot_delivery_receipts', 'invoices', 'invoice_items', 'payments'] as $table)
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        return $out;
    }

    private function backup(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'travel-fee-backup-'); $this->backups[] = $path;
        $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ($this->state() as $table => $rows) {
            // The operator validates a read-only SQLite snapshot even when the
            // application uses MySQL. Copy the real fixture's complete column
            // set through portable metadata, preserving decimal/JSON strings
            // with SQLite's non-coercing BLOB affinity and explicit scalar binds.
            $quote = fn (string $name) => '"'.str_replace('"', '""', $name).'"';
            $definitions = array_map(fn ($name) => $quote($name).($name === 'id' ? ' INTEGER PRIMARY KEY' : ' BLOB'), Schema::getColumnListing($table));
            $pdo->exec('CREATE TABLE '.$quote($table).' ('.implode(',', $definitions).')');
            foreach ($rows as $row) {
                $columns = implode(',', array_map($quote, array_keys($row)));
                $statement = $pdo->prepare('INSERT INTO "'.$table.'" ('.$columns.') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
                foreach (array_values($row) as $index => $value) {
                    $type = $value === null ? PDO::PARAM_NULL : ((is_int($value) || is_bool($value)) ? PDO::PARAM_INT : PDO::PARAM_STR);
                    $statement->bindValue($index + 1, $value, $type);
                }
                $statement->execute();
            }
        }
        $pdo = null; clearstatcache(true, $path); return $path;
    }
}
