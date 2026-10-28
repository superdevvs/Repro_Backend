<?php

namespace Tests\Feature;

use App\Models\{Payment, PaymentRefund, PaymentServiceAllocation, Service, Shoot, ShootFile, ShootService, User};
use App\Services\Shoots\{ShootEditingAssignmentService, ShootListReadContext, ShootServiceItemSupport, ShootSubmissionCapabilityService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Queue};
use Tests\TestCase;

class ShootListReadPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function file(Shoot $shoot, User $actor, array $attributes = []): ShootFile
    {
        return ShootFile::create(array_merge([
            'shoot_id' => $shoot->id, 'filename' => 'capture.jpg', 'stored_filename' => 'capture.jpg',
            'path' => 'capture.jpg', 'file_type' => 'image/jpeg', 'file_size' => 100,
            'media_type' => 'raw', 'workflow_stage' => 'todo', 'uploaded_by' => $actor->id,
            'required_for_editing' => true, 'is_extra' => false,
        ], $attributes));
    }

    public function test_batched_editing_and_submission_flags_match_unbatched_reads_and_do_not_escape_the_page(): void
    {
        Queue::fake();
        $this->freezeTime();
        $admin = User::factory()->create(['role' => 'admin']);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $editor = User::factory()->create(['role' => 'editor']);
        $service = Service::factory()->unbracketedPhoto()->create(['requires_editing' => false]);
        $video = Service::factory()->videoIntake()->create(['requires_editing' => false]);
        $shoots = collect();
        foreach (['scheduled', 'uploaded', 'ready', 'editing'] as $i => $status) {
            $shoot = Shoot::factory()->create(['status' => $status, 'workflow_status' => $status,
                'photographer_id' => $photographer->id, 'editor_id' => $editor->id,
                'photos_uploaded_at' => now()->subHour(), 'editing_completed_at' => now()->subHour(), 'raw_photo_count' => 0]);
            $item = ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $service->id,
                'photographer_id' => $photographer->id, 'editor_id' => $editor->id, 'price' => 100, 'quantity' => 1]);
            ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $video->id, 'editor_id' => $editor->id, 'price' => 100, 'quantity' => 1]);
            $this->file($shoot, $photographer, ['shoot_service_id' => $i % 2 ? $item->id : null]);
            $this->file($shoot, $editor, ['shoot_service_id' => $item->id, 'workflow_stage' => 'completed', 'media_type' => 'edited',
                'flag_reason' => $i === 3 ? 'rejected' : null]);
            $shoots->push($shoot);
        }
        $read = function () use ($shoots, $admin, $photographer, $editor): array {
            return $shoots->map(function ($shoot) use ($admin, $photographer, $editor) {
                $items = app(ShootServiceItemSupport::class)->presentation($shoot);
                $editing = app(ShootEditingAssignmentService::class);
                $capabilities = app(ShootSubmissionCapabilityService::class);
                return [
                    'items' => $items,
                    'assignments' => $editing->getTrackedServiceAssignments($shoot)->all(),
                    'raw' => $capabilities->canSubmitRaw($shoot, $photographer),
                    'admin_edits' => $capabilities->canSubmitEdits($shoot, $admin),
                    'editor_edits' => $capabilities->canSubmitEdits($shoot, $editor),
                ];
            })->all();
        };
        $expected = $read();
        $actual = ShootListReadContext::run($shoots, $read);
        $this->assertSame($expected, $actual);
        $this->assertNull(ShootListReadContext::current());

        // A later request must observe a changed required flag, not the prior page.
        ShootFile::query()->update(['required_for_editing' => false]);
        $this->assertFalse(app(ShootEditingAssignmentService::class)->serviceRequiresEditing($shoots[0]->serviceItems()->first()));
        try {
            ShootListReadContext::run($shoots, fn () => throw new \RuntimeException('stop'));
        } catch (\RuntimeException) {
            $this->assertNull(ShootListReadContext::current());
        }
    }

    public function test_repeated_optional_editing_checks_are_constant_queries_after_page_preparation(): void
    {
        Queue::fake();
        $actor = User::factory()->create(['role' => 'admin']);
        $service = Service::factory()->unbracketedPhoto()->create(['requires_editing' => false]);
        $shoots = Shoot::factory()->count(12)->create();
        foreach ($shoots as $shoot) {
            ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $service->id, 'quantity' => 1, 'price' => 100]);
            $this->file($shoot, $actor);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        ShootListReadContext::run($shoots, function () use ($shoots) {
            $this->assertLessThan(30, count(DB::getQueryLog()), 'Preparing twelve shoots must batch related reads.');
            DB::flushQueryLog();
            foreach ($shoots as $shoot) {
                foreach (ShootListReadContext::current()->serviceItems($shoot) as $item) {
                    for ($repeat = 0; $repeat < 4; $repeat++) {
                        $this->assertTrue(app(ShootEditingAssignmentService::class)->serviceRequiresEditing($item));
                        $this->assertSame(['photo'], app(ShootEditingAssignmentService::class)->serviceEditingLanes($item));
                    }
                }
            }
            $this->assertCount(0, DB::getQueryLog(), 'Prepared lane checks must not query once per service or per caller.');
            DB::disableQueryLog();
        });
    }

    public function test_sqlite_media_lookups_use_indexes_instead_of_full_table_scans(): void
    {
        foreach ([
            'select * from shoot_files where shoot_id = 1 and workflow_stage = "todo"',
            'select * from shoot_files where shoot_service_id = 1 and required_for_editing = 1',
            'select * from shoot_files where shoot_id = 1 and shoot_service_id is null and required_for_editing = 1',
            'select * from payments where shoot_id = 1',
        ] as $sql) {
            $plan = implode(' ', array_column(DB::select('EXPLAIN QUERY PLAN '.$sql), 'detail'));
            $this->assertStringContainsString('USING INDEX', $plan);
            $this->assertStringNotContainsString('SCAN ', $plan);
        }
    }

    public function test_batched_payment_allocations_preserve_refunds_pending_payments_and_exclusion(): void
    {
        Queue::fake();
        $shoot = Shoot::factory()->create();
        $item = ShootService::create(['shoot_id' => $shoot->id, 'service_id' => Service::factory()->create()->id,
            'quantity' => 1, 'price' => 200]);
        $completed = Payment::factory()->create(['shoot_id' => $shoot->id, 'invoice_id' => null, 'amount' => 100, 'status' => Payment::STATUS_COMPLETED, 'payment_method' => 'cash']);
        $pending = Payment::factory()->create(['shoot_id' => $shoot->id, 'invoice_id' => null, 'amount' => 100, 'status' => Payment::STATUS_PENDING]);
        foreach ([$completed, $pending] as $payment) PaymentServiceAllocation::create(['payment_id' => $payment->id, 'shoot_service_id' => $item->id, 'amount' => 100]);
        PaymentRefund::create(['payment_id' => $completed->id, 'shoot_id' => $shoot->id, 'amount' => 25, 'provider' => 'manual', 'status' => 'succeeded']);
        $support = app(ShootServiceItemSupport::class);
        $expected = $support->paidAmountsByItem(collect([$item]));
        $this->assertSame([$item->id => 75.0], $expected);
        $shoot->load(['payments' => fn ($query) => $query->select('id', 'shoot_id', 'amount', 'status')]);
        ShootListReadContext::run(collect([$shoot]), function () use ($shoot, $item, $completed, $support, $expected) {
            $this->assertSame('cash', $shoot->payments->firstWhere('id', $completed->id)->payment_method);
            $this->assertSame($expected, $support->paidAmountsByItem(collect([$item])));
            $this->assertSame([], $support->paidAmountsByItem(collect([$item]), $completed->id));
        });
    }

    public function test_legacy_people_outside_service_rows_are_loaded_once_and_missing_people_do_not_repeat_queries(): void
    {
        Queue::fake();
        $shoot = Shoot::factory()->create();
        $person = User::factory()->create(['role' => 'editor']);
        ShootListReadContext::run(collect([$shoot]), function () use ($person) {
            $context = ShootListReadContext::current();
            $this->assertSame($person->id, $context->users([$person->id])->get($person->id)?->id);
            $this->assertCount(0, $context->users([999999]));
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->assertSame($person->id, $context->users([$person->id])->get($person->id)?->id);
            $this->assertCount(0, $context->users([999999]));
            $this->assertCount(0, DB::getQueryLog());
            DB::disableQueryLog();
        });
    }
}
