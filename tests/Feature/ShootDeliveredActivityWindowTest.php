<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Payment;
use App\Models\PaymentServiceAllocation;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;
use App\Services\Shoots\ShootActiveWorkLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShootDeliveredActivityWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        Bus::fake();
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_boundaries_are_inclusive_exact_microseconds_across_dst_and_mixed_storage(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $start = $this->shoot('2026-11-01 05:30:00.000000');
        $end = $this->shoot('2026-11-01T01:30:00.000000-05:00');
        $middle = $this->shoot('2026-11-01T06:00:00Z');
        $this->shoot('2026-11-01 05:29:59.999999');
        $this->shoot('2026-11-01T01:30:00.000001-05:00');
        $bounds = ['delivered_activity_from' => '2026-11-01T01:30:00-04:00',
            'delivered_activity_to' => '2026-11-01T01:30:00-05:00'];
        $payload = $this->listing($bounds)->assertOk()->assertJsonPath('meta.count', 3)
            ->assertJsonPath('meta.delivered_activity_window', [
                'from' => '2026-11-01T05:30:00.000000Z', 'to' => '2026-11-01T06:30:00.000000Z',
            ])->json('data');
        $this->assertEqualsCanonicalizing([$start->id, $end->id, $middle->id], array_column($payload, 'id'));

        $instant = ['delivered_activity_from' => '2026-11-01T05:30:00.000000Z',
            'delivered_activity_to' => '2026-11-01T00:30:00-05:00'];
        $this->listing($instant)->assertOk()->assertJsonPath('meta.count', 1)->assertJsonPath('data.0.id', $start->id);
        Http::assertNothingSent();
    }

    public function test_admin_timestamp_precedes_completion_and_unknown_or_malformed_activity_is_excluded(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $primary = $this->shoot('2026-11-01 12:00:00', '2026-08-01 12:00:00');
        $legacy = $this->shoot(null, '2026-11-01T08:00:00-04:00');
        $sqlite = DB::getDriverName() === 'sqlite';
        $blank = $this->shoot($sqlite ? '' : null, '2026-11-01 12:00:00.123456');
        $this->shoot('2026-08-01 12:00:00', '2026-11-01 12:00:00');
        if ($sqlite) {
            // Invalid legacy storage is possible only with SQLite's text dates.
            // MySQL strict-storage rejection is covered in tests/MySql.
            $this->shoot('malformed', '2026-11-01 12:00:00');
            $this->shoot('2026-02-30 12:00:00', '2026-11-01 12:00:00');
            $this->shoot(null, '2026-11-01');
        }
        $this->shoot(null, null, ['editing_completed_at' => '2026-11-01 12:00:00',
            'photos_uploaded_at' => '2026-11-01 12:00:00', 'scheduled_date' => '2026-11-01',
            'created_at' => '2026-11-01 12:00:00', 'updated_at' => '2026-11-01 12:00:00']);
        $response = $this->listing($this->bounds())->assertOk()->assertJsonPath('meta.count', 3);
        $this->assertEqualsCanonicalizing([$primary->id, $legacy->id, $blank->id], array_column($response->json('data'), 'id'));
    }

    public function test_filter_and_acknowledgement_apply_before_count_pagination_and_combine_with_status(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $expected = collect(range(1, 13))->map(function ($index) {
            $shoot = $this->shoot($index <= 6 ? '2026-08-01 12:00:00' : '2026-11-01 12:00:00');
            if ($index <= 6) $this->serviceRow($shoot, '2026-11-01 12:00:00');
            return $shoot->id;
        })->all();
        $this->shoot('2026-08-01 12:00:00');
        $this->shoot('2026-11-01 12:00:00', null, ['status' => 'ready', 'workflow_status' => 'ready']);
        $params = $this->bounds() + ['per_page' => 9, 'statuses' => ['delivered']];
        $first = $this->listing($params)->assertOk()->assertJsonPath('meta.count', 13)
            ->assertJsonPath('meta.last_page', 2)->assertJsonCount(9, 'data');
        $second = $this->listing($params + ['page' => 2])->assertOk()->assertJsonPath('meta.count', 13)
            ->assertJsonPath('meta.current_page', 2)->assertJsonCount(4, 'data');
        $this->assertEqualsCanonicalizing($expected, array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')));
        $this->assertSame($first->json('meta.delivered_activity_window'), $second->json('meta.delivered_activity_window'));
        $this->listing($this->bounds() + ['search' => 'no-such-property'])->assertOk()
            ->assertJsonPath('meta.count', 0)->assertJsonPath('meta.delivered_activity_window', $first->json('meta.delivered_activity_window'));
        $this->listing($this->bounds() + ['tab' => 'scheduled'])->assertOk()->assertJsonPath('meta.count', 0);
    }

    public function test_closed_work_cycle_is_recent_delivery_activity_without_rewriting_the_first_delivery(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $closed = $this->shoot('2026-11-01 12:00:00', '2026-11-01 11:00:00');
        DB::table('shoots')->where('id', $closed->id)->update([
            'active_work_started_at' => '2026-11-10 12:00:00',
            'active_work_completed_at' => '2026-11-11T10:00:00.123456-05:00',
        ]);
        $bounds = ['delivered_activity_from' => '2026-11-11T15:00:00.123456Z',
            'delivered_activity_to' => '2026-11-11T15:00:00.123456Z'];
        $this->listing($bounds)->assertOk()->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.id', $closed->id)->assertJsonPath('meta.delivered_activity_window', [
                'from' => '2026-11-11T15:00:00.123456Z', 'to' => '2026-11-11T15:00:00.123456Z',
            ]);
        $this->listing($this->bounds())->assertOk()->assertJsonPath('meta.count', 0);
        $this->assertDatabaseHas('shoots', ['id' => $closed->id,
            'admin_verified_at' => '2026-11-01 12:00:00', 'completed_at' => '2026-11-01 11:00:00']);
    }

    public function test_preferred_closed_cycle_timestamp_does_not_fall_back_when_old_or_malformed(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $cycles = DB::getDriverName() === 'mysql' ? ['2026-08-01 12:00:00'] : ['2026-08-01 12:00:00', 'malformed', '2026-02-30 12:00:00'];
        foreach ($cycles as $cycle) {
            $shoot = $this->shoot('2026-11-01 12:00:00', '2026-11-01 11:00:00');
            DB::table('shoots')->where('id', $shoot->id)->update([
                'active_work_started_at' => '2026-07-01 12:00:00', 'active_work_completed_at' => $cycle,
            ]);
        }
        $this->listing($this->bounds())->assertOk()->assertJsonPath('meta.count', 0)
            ->assertJsonPath('meta.delivered_activity_window.from', '2026-11-01T00:00:00.000000Z');
    }

    public function test_latest_service_delivery_compares_exact_offsets_and_excludes_unfinished_or_cancelled_rows(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $shoot = $this->shoot('2026-08-01 12:00:00');
        // Lexical MAX would choose the second string, which is an earlier instant.
        $this->serviceRow($shoot, '2026-11-01T23:30:00.123456-05:00');
        $this->serviceRow($shoot, '2026-11-02T05:00:00.123456+02:00');
        $this->serviceRow($shoot, '2026-11-03 12:00:00', ['is_deliverable' => false]);
        $this->serviceRow($shoot, '2026-11-04 12:00:00', ['cancelled_at' => '2026-11-04 13:00:00']);
        $this->serviceRow($shoot, '2026-11-05 12:00:00', ['workflow_status' => 'pending']);
        $this->serviceRow($shoot, '2026-11-06 12:00:00', ['delivery_status' => 'ready']);
        $invalid = $this->shoot(null);
        if (DB::getDriverName() === 'sqlite') {
            $this->serviceRow($invalid, 'malformed');
        }
        $bounds = ['delivered_activity_from' => '2026-11-02T04:30:00.123456Z',
            'delivered_activity_to' => '2026-11-02T04:30:00.123456Z'];
        $this->listing($bounds)->assertOk()->assertJsonPath('meta.count', 1)->assertJsonPath('data.0.id', $shoot->id);
        $this->listing(['delivered_activity_from' => '2026-11-02T03:00:00.123456Z',
            'delivered_activity_to' => '2026-11-02T04:30:00.123455Z'])->assertOk()->assertJsonPath('meta.count', 0);
    }

    public function test_reopening_repeated_cycles_retains_recent_deliveries_and_only_released_unlocked_client_images(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-04T12:00:00Z'));
        $shoot = $this->shoot('2026-11-01 12:00:00', '2026-11-01 11:00:00',
            ['payment_status' => 'partial', 'total_quote' => 300]);
        $first = $this->serviceRow($shoot, '2026-11-01 12:00:00');
        $second = $this->serviceRow($shoot, '2026-11-03 12:00:00');
        $third = $this->serviceRow($shoot, null, ['workflow_status' => 'pending', 'delivery_status' => 'not_started',
            'scheduled_at' => '2026-11-05 12:00:00']);
        Model::withoutEvents(function () use ($shoot, $first, $second) {
            $payment = Payment::factory()->create(['shoot_id' => $shoot->id, 'invoice_id' => null,
                'amount' => 200, 'status' => Payment::STATUS_COMPLETED]);
            foreach ([$first, $second] as $item) {
                PaymentServiceAllocation::create(['payment_id' => $payment->id, 'shoot_service_id' => $item->id, 'amount' => 100]);
            }
        });
        $this->previewFile($shoot, $first, 'first-paid');
        $this->previewFile($shoot, $second, 'second-paid');
        $this->previewFile($shoot, $third, 'third-unpaid-secret');
        DB::table('shoots')->where('id', $shoot->id)->update([
            'active_work_started_at' => '2026-11-02 12:00:00', 'active_work_completed_at' => '2026-11-03 12:00:00',
        ]);
        Shoot::withoutEvents(fn () => app(ShootActiveWorkLifecycle::class)->begin($shoot->fresh()));
        $this->assertNull($shoot->fresh()->active_work_completed_at);
        $this->assertSame('active', $shoot->fresh()->status);
        $this->actingAs($shoot->client);
        $novemberThird = ['delivered_activity_from' => '2026-11-03T00:00:00Z',
            'delivered_activity_to' => '2026-11-04T00:00:00Z'];
        $response = $this->listing($novemberThird)->assertOk()->assertJsonPath('meta.count', 1)->assertJsonPath('data.0.id', $shoot->id);
        $media = $response->json('data.0.delivery_card_media');
        $this->assertCount(2, $media['preview_images']);
        $this->assertFalse($media['uses_watermark']);
        $this->assertStringContainsString('second-paid-grid.jpg', json_encode($media));
        $this->assertStringNotContainsString('third-unpaid-secret', json_encode($media));

        // Complete a third delivery, then reopen again. Its durable timestamp
        // still counts, while its unpaid images and the new pending work stay hidden.
        DB::table('shoot_service')->where('id', $third->id)->update(['workflow_status' => 'delivered',
            'delivery_status' => 'delivered', 'delivered_at' => '2026-11-05 12:00:00']);
        $fourth = $this->serviceRow($shoot, null, ['workflow_status' => 'pending', 'delivery_status' => 'not_started',
            'scheduled_at' => '2026-11-07 12:00:00']);
        $this->previewFile($shoot, $fourth, 'fourth-pending-secret');
        DB::table('shoots')->where('id', $shoot->id)->update(['status' => 'delivered', 'workflow_status' => 'delivered',
            'active_work_completed_at' => '2026-11-05 12:00:00']);
        $this->travelTo(\Carbon\Carbon::parse('2026-11-06T12:00:00Z'));
        Shoot::withoutEvents(fn () => app(ShootActiveWorkLifecycle::class)->begin($shoot->fresh()));
        $this->assertNull($shoot->fresh()->active_work_completed_at);
        $latest = $this->listing(['delivered_activity_from' => '2026-11-05T00:00:00Z',
            'delivered_activity_to' => '2026-11-06T00:00:00Z', 'no_cache' => 'true'])->assertOk()->assertJsonPath('meta.count', 1);
        $this->assertCount(2, $latest->json('data.0.delivery_card_media.preview_images'));
        $this->assertStringNotContainsString('secret', json_encode($latest->json('data.0.delivery_card_media')));
        $this->listing($novemberThird + ['no_cache' => 'true'])->assertOk()->assertJsonPath('meta.count', 0);
        $this->assertDatabaseHas('shoots', ['id' => $shoot->id,
            'admin_verified_at' => '2026-11-01 12:00:00', 'completed_at' => '2026-11-01 11:00:00']);
        Http::assertNothingSent(); Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_windowed_plain_and_different_windows_have_separate_cached_counts_and_acknowledgements(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $recent = $this->shoot('2026-11-01 12:00:00');
        $old = $this->shoot('2026-08-01 12:00:00');
        $plain = $this->listing()->assertOk()->assertJsonPath('meta.count', 2)->assertJsonMissingPath('meta.delivered_activity_window')->json();
        $one = $this->listing($this->bounds())->assertOk()->assertJsonPath('meta.count', 1)->assertJsonPath('data.0.id', $recent->id)->json();
        $oldBounds = ['delivered_activity_from' => '2026-08-01T00:00:00Z', 'delivered_activity_to' => '2026-08-02T00:00:00Z'];
        $this->listing($oldBounds)->assertOk()->assertJsonPath('meta.count', 1)->assertJsonPath('data.0.id', $old->id)
            ->assertJsonPath('meta.delivered_activity_window.from', '2026-08-01T00:00:00.000000Z');
        $this->assertSame($one, $this->listing($this->bounds())->assertOk()->json());
        $this->assertSame($plain, $this->listing()->assertOk()->json());
    }

    public static function invalidBounds(): array
    {
        $valid = '2026-11-01T12:00:00Z';
        return [
            'missing end' => [['delivered_activity_from' => $valid]],
            'missing start' => [['delivered_activity_to' => $valid]],
            'blank' => [['delivered_activity_from' => '', 'delivered_activity_to' => $valid]],
            'date only' => [['delivered_activity_from' => '2026-11-01', 'delivered_activity_to' => $valid]],
            'no timezone' => [['delivered_activity_from' => '2026-11-01T11:00:00', 'delivered_activity_to' => $valid]],
            'relative' => [['delivered_activity_from' => 'yesterday', 'delivered_activity_to' => $valid]],
            'rollover day' => [['delivered_activity_from' => '2026-02-30T11:00:00Z', 'delivered_activity_to' => $valid]],
            'rollover hour' => [['delivered_activity_from' => '2026-11-01T24:00:00Z', 'delivered_activity_to' => $valid]],
            'bad offset' => [['delivered_activity_from' => '2026-11-01T11:00:00+25:00', 'delivered_activity_to' => $valid]],
            'too much precision' => [['delivered_activity_from' => '2026-11-01T11:00:00.0000001Z', 'delivered_activity_to' => $valid]],
            'inverted' => [['delivered_activity_from' => $valid, 'delivered_activity_to' => '2026-11-01T11:59:59.999999Z']],
            'array' => [['delivered_activity_from' => [$valid], 'delivered_activity_to' => $valid]],
        ];
    }

    public function test_property_only_discovery_rejects_activity_windows_and_keeps_its_original_no_window_projection(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'client']));
        $discovery = ['private_listing' => 'true', 'listing_scope' => 'all'];
        $this->listing($discovery)->assertOk()->assertJsonMissingPath('meta.delivered_activity_window');
        $this->listing($discovery + $this->bounds())->assertUnprocessable()->assertJsonMissingPath('meta.delivered_activity_window');
        $this->listing($discovery + ['delivered_activity_from' => '2026-11-01T00:00:00Z'])
            ->assertUnprocessable()->assertJsonMissingPath('meta.delivered_activity_window');
    }

    #[DataProvider('invalidBounds')]
    public function test_invalid_or_partial_bounds_return_422_without_an_applied_acknowledgement(array $bounds): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->listing($bounds)->assertUnprocessable()->assertJsonMissingPath('meta.delivered_activity_window');
    }

    public static function roles(): array
    {
        return array_map(fn ($role) => [$role], ['admin', 'superadmin', 'editing_manager', 'salesRep', 'photographer', 'editor', 'client']);
    }

    #[DataProvider('roles')]
    public function test_activity_filter_never_broadens_role_scope_or_editor_payment_visibility(string $role): void
    {
        $viewer = User::factory()->create(['role' => $role]);
        $client = $role === 'client' ? $viewer : User::factory()->create(['role' => 'client']);
        $person = $role === 'photographer' ? $viewer : User::factory()->photographer()->create();
        $mine = $this->shoot('2026-11-01 12:00:00', null, ['client_id' => $client->id,
            'photographer_id' => $person->id, 'editor_id' => $role === 'editor' ? $viewer->id : null]);
        $service = Service::factory()->create();
        $mine->services()->attach($service->id, ['photographer_id' => $person->id,
            'editor_id' => $role === 'editor' ? $viewer->id : null, 'price' => 200]);
        $foreign = $this->shoot('2026-11-01 12:00:00');
        $this->actingAs($viewer);
        $response = $this->listing($this->bounds())->assertOk();
        $staff = in_array($role, ['admin', 'superadmin', 'editing_manager', 'salesRep'], true);
        $this->assertEqualsCanonicalizing($staff ? [$mine->id, $foreign->id] : [$mine->id], array_column($response->json('data'), 'id'));
        $this->assertSame($staff ? 2 : 1, $response->json('meta.count'));
        if ($role === 'editor') {
            $response->assertJsonPath('data.0.payment_status', null)->assertJsonPath('data.0.client', null)
                ->assertJsonPath('data.0.services.0.payment_status', null);
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    private function bounds(): array
    {
        return ['delivered_activity_from' => '2026-11-01T00:00:00Z', 'delivered_activity_to' => '2026-11-02T00:00:00Z'];
    }

    private function listing(array $params = [])
    {
        return $this->getJson('/api/shoots?'.http_build_query($params + [
            'tab' => 'delivered', 'per_page' => 50, 'include_filters' => 'false', 'include_files' => 'false', 'view' => 'card',
        ]));
    }

    private function shoot(?string $primary, ?string $completion = null, array $attributes = []): Shoot
    {
        $shoot = Shoot::withoutEvents(fn () => Shoot::factory()->create(array_replace([
            'status' => 'delivered', 'workflow_status' => 'delivered', 'scheduled_date' => '2026-01-01',
            'payment_status' => 'paid', 'total_quote' => 200, 'admin_verified_at' => null, 'completed_at' => null,
        ], $attributes)));
        // SQLite preserves mixed raw text. MySQL stores the same instants in
        // typed UTC DATETIME(6), including the exact microsecond boundaries.
        if (DB::getDriverName() === 'mysql') {
            $primary = $this->mysqlTimestamp($primary);
            $completion = $this->mysqlTimestamp($completion);
        }
        DB::table('shoots')->where('id', $shoot->id)->update(['admin_verified_at' => $primary, 'completed_at' => $completion]);
        return $shoot;
    }

    private function serviceRow(Shoot $shoot, ?string $deliveredAt, array $attributes = []): ShootService
    {
        $item = Model::withoutEvents(fn () => ShootService::create(array_replace([
            'shoot_id' => $shoot->id, 'service_id' => Service::factory()->unbracketedPhoto()->create()->id,
            'price' => 100, 'quantity' => 1, 'is_deliverable' => true,
            'workflow_status' => 'delivered', 'delivery_status' => 'delivered',
        ], $attributes)));
        DB::table('shoot_service')->where('id', $item->id)->update(['delivered_at' => DB::getDriverName() === 'mysql' ? $this->mysqlTimestamp($deliveredAt) : $deliveredAt]);
        return $item;
    }

    private function mysqlTimestamp(?string $value): ?string
    {
        return $value === null ? null : \Carbon\CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d H:i:s.u');
    }

    private function previewFile(Shoot $shoot, ShootService $item, string $name): void
    {
        $file = new ShootFile;
        $file->forceFill(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id,
            'filename' => $name.'.jpg', 'stored_filename' => $name.'.jpg', 'path' => 'photos/'.$name.'.jpg',
            'grid_path' => 'photos/'.$name.'-grid.jpg', 'web_path' => 'photos/'.$name.'-web.jpg',
            'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'media_type' => 'edited', 'file_size' => 100,
            'workflow_stage' => 'verified', 'verified_at' => now(), 'scan_status' => 'clean',
            'is_hidden' => false, 'is_cover' => true, 'uploaded_by' => $shoot->client_id])->saveQuietly();
    }
}
