<?php

namespace Tests\Feature;

use App\Models\{Invoice, InvoiceItem, Service, Shoot, ShootActivityLog, ShootFile, ShootService, User};
use App\Services\Shoots\ShootActiveWorkLifecycle;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Http, Mail, Notification, Queue, Storage};
use PDO;
use Tests\TestCase;

class ReconcilePartialServiceDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private array $backups = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 12:00:00');
        Http::preventStrayRequests();
        Mail::fake(); Notification::fake(); Queue::fake();
        // Other focused suites run against this worktree concurrently; do not share fake('public')'s root.
        $disk = 'partial_delivery_'.bin2hex(random_bytes(6));
        Storage::fake($disk);
        Storage::set('public', Storage::disk($disk));
        config(['mobile_notifications.enabled' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->backups as $path) if (is_file($path)) unlink($path);
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_default_preview_does_not_change_completion_files_finances_or_queue_work(): void
    {
        $fixture = $this->fixture();
        $before = $this->databaseState();
        $this->artisan('shoots:reconcile-partial-delivery', $this->arguments($fixture))
            ->expectsOutputToContain('"proposed_shoot_status": "active"')->assertSuccessful();
        $this->assertSame($before, $this->databaseState());
        Queue::assertNothingPushed(); Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_apply_repairs_only_reviewed_service_ownership_and_completion_and_is_idempotent(): void
    {
        $fixture = $this->fixture();
        [$shoot, $source, $target, $files] = $fixture;
        $before = $this->databaseState();
        $backup = $this->backup();
        $arguments = $this->arguments($fixture) + ['--apply' => true, '--backup-path' => $backup];
        $this->artisan('shoots:reconcile-partial-delivery', $arguments)->assertSuccessful();

        $this->assertSame('active', $shoot->fresh()->status);
        $this->assertSame('active', $shoot->fresh()->workflow_status);
        $this->assertSame('partially_delivered', $shoot->fresh()->delivery_status);
        $this->assertSame('2026-11-04', $shoot->fresh()->active_work_date);
        foreach (['scheduled_at', 'scheduled_date', 'time', 'admin_verified_at', 'completed_at', 'editing_completed_at', 'total_quote', 'payment_status', 'bypass_paywall'] as $field) {
            $this->assertSame($before['shoots'][0][$field], $shoot->fresh()->getRawOriginal($field), $field);
        }
        $source->refresh(); $target->refresh();
        $this->assertSame('scheduled', $source->workflow_status);
        $this->assertSame('not_started', $source->delivery_status);
        $this->assertNull($source->ready_at); $this->assertNull($source->delivered_at); $this->assertNull($source->editing_completed_at);
        $this->assertSame('2026-11-04 20:45:00', $source->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('delivered', $target->delivery_status);
        $this->assertSame('2026-11-03 08:20:32', $target->delivered_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-03 06:52:01', $target->editing_completed_at->format('Y-m-d H:i:s'));
        $this->assertSame($source->editor_id, $target->editor_id);
        foreach ($files as $index => $file) {
            $expected = $before['shoot_files'][$index];
            $expected['shoot_service_id'] = $target->id;
            $this->assertSame($expected, $file->fresh()->getRawOriginal());
            $this->assertSame('reviewed-image-'.$index, Storage::disk('public')->get($file->path));
        }
        $this->assertSame($before['invoices'], $this->databaseState()['invoices']);
        $this->assertSame($before['invoice_items'], $this->databaseState()['invoice_items']);
        $audit = ShootActivityLog::where('action', 'partial_service_delivery_reconciled')->sole();
        $this->assertSame('delivered', $audit->metadata['before']['shoot']['status']);
        $this->assertSame('active', $audit->metadata['after']['shoot']['status']);
        $this->assertSame($audit->metadata['before']['billing_hash'], $audit->metadata['after']['billing_hash']);
        $this->assertStringNotContainsString('private payout basis', json_encode($audit->metadata));
        $after = $this->databaseState();
        Cache::put('shoot_files_'.$shoot->id.'_edited', 'stale media');
        Cache::put('shoots_index_cache_keys', ['stale-reconciliation-list']);
        Cache::put('stale-reconciliation-list', 'stale list');
        $this->artisan('shoots:reconcile-partial-delivery', $arguments)
            ->expectsOutput('Already reconciled; no changes.')->assertSuccessful();
        $this->assertSame($after, $this->databaseState());
        $this->assertNull(Cache::get('shoot_files_'.$shoot->id.'_edited'));
        $this->assertNull(Cache::get('stale-reconciliation-list'));
        Queue::assertNothingPushed(); Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_apply_requires_a_fresh_matching_sqlite_backup(): void
    {
        $fixture = $this->fixture();
        $arguments = $this->arguments($fixture) + ['--apply' => true];
        $before = $this->databaseState();
        $this->artisan('shoots:reconcile-partial-delivery', $arguments)
            ->expectsOutputToContain('readable --backup-path')->assertFailed();
        $backup = $this->backup();
        touch($backup, time() - 901);
        clearstatcache(true, $backup);
        $this->artisan('shoots:reconcile-partial-delivery', $arguments + ['--backup-path' => $backup])
            ->expectsOutputToContain('15 minutes')->assertFailed();
        touch($backup); clearstatcache(true, $backup);
        $pdo = new PDO('sqlite:'.$backup);
        $pdo->exec('UPDATE shoot_service SET price = price + 1');
        $pdo = null;
        $this->artisan('shoots:reconcile-partial-delivery', $arguments + ['--backup-path' => $backup])
            ->expectsOutputToContain('backup does not match')->assertFailed();
        $this->assertSame($before, $this->databaseState());
        Queue::assertNothingPushed(); Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_incomplete_reviewed_file_set_and_changed_appointment_are_refused(): void
    {
        $fixture = $this->fixture();
        $arguments = $this->arguments($fixture);
        $before = $this->databaseState();
        $this->artisan('shoots:reconcile-partial-delivery', array_replace($arguments, ['--file-ids' => (string) $fixture[3][0]->id]))
            ->expectsOutputToContain('complete source file set')->assertFailed();
        $this->artisan('shoots:reconcile-partial-delivery', array_replace($arguments, ['--expected-source-scheduled-at' => '2026-11-05 20:45:00']))
            ->expectsOutputToContain('source appointment changed')->assertFailed();
        $this->assertSame($before, $this->databaseState());
    }

    public function test_non_admin_and_service_scoped_delivery_event_are_refused(): void
    {
        $fixture = $this->fixture();
        $arguments = $this->arguments($fixture);
        $photographer = User::factory()->photographer()->create();
        $this->artisan('shoots:reconcile-partial-delivery', array_replace($arguments, ['--actor' => (string) $photographer->id]))
            ->expectsOutputToContain('existing administrator')->assertFailed();
        $fixture[4]->forceFill(['metadata' => ['full_order_delivery' => false, 'shoot_service_id' => $fixture[2]->id]])->saveQuietly();
        $before = $this->databaseState();
        $this->artisan('shoots:reconcile-partial-delivery', $arguments)
            ->expectsOutputToContain('not a whole-order delivery event')->assertFailed();
        $this->assertSame($before, $this->databaseState());
    }

    public function test_unverified_media_and_paid_work_are_refused(): void
    {
        $fixture = $this->fixture();
        $arguments = $this->arguments($fixture);
        $fixture[3][0]->forceFill(['scan_status' => 'pending'])->saveQuietly();
        $this->artisan('shoots:reconcile-partial-delivery', $arguments)
            ->expectsOutputToContain('clean, verified edited image')->assertFailed();
        $fixture[3][0]->forceFill(['scan_status' => 'clean'])->saveQuietly();
        $fixture[0]->forceFill(['photographer_paid_at' => now()])->saveQuietly();
        $before = $this->databaseState();
        $this->artisan('shoots:reconcile-partial-delivery', $arguments)
            ->expectsOutputToContain('separate accounting reconciliation')->assertFailed();
        $this->assertSame($before, $this->databaseState());
    }

    public function test_failed_activation_rolls_back_file_moves_service_flags_and_audit(): void
    {
        $fixture = $this->fixture();
        $before = $this->databaseState();
        $backup = $this->backup();
        $this->mock(ShootActiveWorkLifecycle::class)->shouldReceive('begin')->once()->andReturn(false);
        $this->artisan('shoots:reconcile-partial-delivery', $this->arguments($fixture) + ['--apply' => true, '--backup-path' => $backup])
            ->expectsOutputToContain('did not accept the outstanding service')->assertFailed();
        $this->assertSame($before, $this->databaseState());
        Queue::assertNothingPushed(); Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    private function fixture(): array
    {
        return Model::withoutEvents(function (): array {
            $admin = User::factory()->create(['role' => 'superadmin']);
            $editor = User::factory()->create(['role' => 'editor']);
            $shoot = Shoot::factory()->create([
                'status' => 'delivered', 'workflow_status' => 'delivered', 'delivery_status' => 'delivered',
                'scheduled_at' => '2026-11-02 18:30:00', 'scheduled_date' => '2026-11-02', 'time' => '13:30', 'timezone' => 'America/New_York',
                'admin_verified_at' => '2026-11-03 08:20:32', 'completed_at' => '2026-11-03 08:20:32',
                'editing_completed_at' => '2026-11-03 08:20:27', 'editor_id' => $editor->id,
                'base_quote' => 325, 'tax_amount' => 19.5, 'total_quote' => 344.5, 'payment_status' => 'unpaid', 'bypass_paywall' => false,
            ]);
            $photo = Service::factory()->unbracketedPhoto()->create(['name' => 'HDR Photos']);
            $drone = Service::factory()->unbracketedPhoto()->create(['name' => 'Drone Photos']);
            $common = ['shoot_id' => $shoot->id, 'quantity' => 1, 'is_deliverable' => true,
                'workflow_status' => 'delivered', 'delivery_status' => 'delivered', 'ready_at' => '2026-11-03 06:51:47', 'delivered_at' => '2026-11-03 08:20:32'];
            $source = ShootService::create($common + ['service_id' => $photo->id, 'price' => 175, 'editor_id' => $editor->id, 'scheduled_at' => '2026-11-04 20:45:00', 'editing_completed_at' => '2026-11-03 06:52:01']);
            $target = ShootService::create($common + ['service_id' => $drone->id, 'price' => 150, 'scheduled_at' => '2026-11-02 18:30:00']);
            $source->forceFill(['payout_snapshot' => ['original' => 'private payout basis']])->saveQuietly();
            $target->forceFill(['payout_snapshot' => ['original' => 'private payout basis']])->saveQuietly();
            $files = [];
            for ($index = 0; $index < 2; $index++) {
                $path = "shoots/{$shoot->id}/completed/drone-$index.jpg";
                Storage::disk('public')->put($path, 'reviewed-image-'.$index);
                $files[] = ShootFile::create([
                    'shoot_id' => $shoot->id, 'shoot_service_id' => $source->id, 'filename' => "drone-$index.jpg", 'stored_filename' => "drone-$index.jpg", 'path' => $path,
                    'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'media_type' => 'edited', 'file_size' => 16, 'workflow_stage' => 'verified',
                    'verified_at' => '2026-11-03 08:20:32', 'verified_by' => $admin->id, 'uploaded_by' => $editor->id, 'scan_status' => 'clean', 'is_hidden' => false, 'is_extra' => false,
                ]);
            }
            $event = ShootActivityLog::create(['shoot_id' => $shoot->id, 'user_id' => $admin->id, 'action' => 'shoot_finalized_delivered',
                'description' => 'Reviewed incorrect whole-order delivery', 'metadata' => ['full_order_delivery' => true, 'shoot_service_id' => null]]);
            $event->forceFill(['created_at' => '2026-11-03 08:20:35'])->saveQuietly();
            $invoice = Invoice::factory()->create(['shoot_id' => $shoot->id, 'client_id' => $shoot->client_id, 'role' => 'client', 'status' => 'sent', 'total' => 344.5, 'amount_paid' => 0, 'balance_due' => 344.5]);
            InvoiceItem::create(['invoice_id' => $invoice->id, 'shoot_id' => $shoot->id, 'type' => 'charge', 'description' => 'HDR Photos', 'quantity' => 1, 'unit_amount' => 175, 'total_amount' => 175, 'meta' => ['shoot_service_id' => $source->id]]);
            return [$shoot->fresh(), $source->fresh(), $target->fresh(), $files, $event, $admin];
        });
    }

    private function arguments(array $fixture): array
    {
        return [
            'shoot' => (string) $fixture[0]->id,
            '--source-service' => (string) $fixture[1]->id,
            '--target-service' => (string) $fixture[2]->id,
            '--file-ids' => implode(',', array_map(fn ($file) => $file->id, $fixture[3])),
            '--expected-source-scheduled-at' => '2026-11-04 20:45:00',
            '--finalization-event' => (string) $fixture[4]->id,
            '--actor' => (string) $fixture[5]->id,
        ];
    }

    private function databaseState(): array
    {
        $state = [];
        foreach (['shoots', 'shoot_service', 'shoot_files', 'invoices', 'invoice_items', 'shoot_activity_logs'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        return $state;
    }

    private function backup(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'partial-delivery-backup-');
        $this->backups[] = $path;
        $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ($this->databaseState() as $table => $rows) {
            $definition = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
            $pdo->exec($definition->sql);
            foreach ($rows as $row) {
                $columns = implode(',', array_map(fn ($column) => '"'.$column.'"', array_keys($row)));
                $statement = $pdo->prepare("INSERT INTO $table ($columns) VALUES (".implode(',', array_fill(0, count($row), '?')).')');
                $statement->execute(array_values($row));
            }
        }
        $pdo = null;
        clearstatcache(true, $path);
        return $path;
    }
}
