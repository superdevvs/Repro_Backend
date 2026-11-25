<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootCompensation;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PayoutReportService;
use App\Support\ReadOnlyDatabase;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WeeklyBillingGapDryRunTest extends TestCase
{
    use RefreshDatabase;

    // The Sunday after the reporting week 2026-10-04 (Sunday) to 2026-10-10 (Saturday).
    private const THROUGH = '2026-10-11';

    public function test_lists_first_day_shoots_the_text_comparison_skipped_for_both_stored_date_shapes(): void
    {
        $rep = $this->salesRep();
        $service = Service::factory()->create(['price' => 500, 'exclude_from_sales_commission' => false]);
        $shoots = [];
        foreach ([
            'bare_first_day' => '2026-10-04',
            'datetime_first_day' => '2026-10-04 00:00:00',
            'bare_mid_week' => '2026-10-07',
            'bare_last_day' => '2026-10-10',
            'datetime_last_day' => '2026-10-10 00:00:00',
            'bare_day_before' => '2026-10-03',
            'datetime_day_after' => '2026-10-11 00:00:00',
        ] as $label => $stored) {
            $shoots[$label] = $this->repShoot($rep, $service, $stored);
        }

        $report = $this->dryRun();

        $period = $report['periods'][0];
        $this->assertSame('2026-10-04', $period['start']);
        $this->assertSame('2026-10-10', $period['end']);
        $counts = ['selected_now' => 4, 'selected_date_correct' => 5, 'skipped' => 1, 'selected_only_by_text' => 0];
        $this->assertSame($counts, $period['selections']['sales_rep_invoices']);
        $this->assertSame($counts, $period['selections']['sales_rep_payout_report']);
        $skipped = collect($report['skipped'])->where('selection', 'sales_rep_invoices')->values()->all();
        $this->assertSame([[
            'period_start' => '2026-10-04',
            'selection' => 'sales_rep_invoices',
            'record_type' => 'shoot',
            'record_id' => $shoots['bare_first_day']->id,
            'shoot_id' => $shoots['bare_first_day']->id,
            'stored_value' => '2026-10-04',
            'recipient_id' => $rep->id,
            'billed_invoice_ids' => [],
        ]], $skipped);
        // The invoice run and the payout report both skip the same shoot: two
        // records, one shoot.
        $this->assertSame(['skipped' => 2, 'unbilled' => 2, 'distinct_skipped' => 1, 'distinct_unbilled' => 1], $report['totals']);

        // The dry run mirrors today's generator: the real run bills exactly the
        // four shoots the report calls "selected now" and misses the skipped one.
        $invoices = app(InvoiceService::class)->generateSalesRepInvoicesForPeriod(
            Carbon::parse('2026-10-04'),
            Carbon::parse('2026-10-10'),
        );
        $billed = InvoiceItem::whereIn('invoice_id', $invoices->pluck('id'))->where('type', InvoiceItem::TYPE_CHARGE)
            ->pluck('shoot_id')->sort()->values()->all();
        $expected = collect(['datetime_first_day', 'bare_mid_week', 'bare_last_day', 'datetime_last_day'])
            ->map(fn (string $label) => $shoots[$label]->id)->sort()->values()->all();
        $this->assertSame($expected, $billed);
        // The weekly payout report misses the same shoot.
        $summary = app(PayoutReportService::class)->buildSalesRepSummaries(Carbon::parse('2026-10-04'), Carbon::parse('2026-10-10'));
        $this->assertSame(4, $summary->sole()['shoot_count']);
    }

    public function test_lists_compensations_whose_stored_earned_date_the_text_comparison_skipped(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $rep = $this->salesRep();
        $reshoot = Shoot::factory()->create(['shoot_type' => Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT, 'scheduled_date' => '2026-10-05']);
        $standard = Shoot::factory()->create(['scheduled_date' => '2026-10-05']);
        $photographerPay = [
            'bare_first_day' => $this->compensation($reshoot, ShootCompensation::RECIPIENT_PHOTOGRAPHER, $photographer, '2026-10-04'),
            'datetime_first_day' => $this->compensation($reshoot, ShootCompensation::RECIPIENT_PHOTOGRAPHER, $photographer, '2026-10-04 00:00:00'),
            'bare_last_day' => $this->compensation($reshoot, ShootCompensation::RECIPIENT_PHOTOGRAPHER, $photographer, '2026-10-10'),
            // Only complimentary-reshoot pay becomes a weekly payout line.
            'standard_shoot' => $this->compensation($standard, ShootCompensation::RECIPIENT_PHOTOGRAPHER, $photographer, '2026-10-04'),
        ];
        $repPay = [
            'bare_first_day' => $this->compensation($standard, ShootCompensation::RECIPIENT_SALES_REP, $rep, '2026-10-04'),
            'datetime_mid_week' => $this->compensation($standard, ShootCompensation::RECIPIENT_SALES_REP, $rep, '2026-10-06 09:15:00'),
        ];

        $report = $this->dryRun();

        $selections = $report['periods'][0]['selections'];
        $this->assertSame(['selected_now' => 2, 'selected_date_correct' => 3, 'skipped' => 1, 'selected_only_by_text' => 0], $selections['photographer_compensations']);
        $this->assertSame(['selected_now' => 1, 'selected_date_correct' => 2, 'skipped' => 1, 'selected_only_by_text' => 0], $selections['sales_rep_compensations']);
        $skipped = collect($report['skipped'])->keyBy('selection');
        $this->assertSame($photographerPay['bare_first_day']->id, $skipped['photographer_compensations']['record_id']);
        $this->assertSame('compensation', $skipped['photographer_compensations']['record_type']);
        $this->assertSame($reshoot->id, $skipped['photographer_compensations']['shoot_id']);
        $this->assertSame($photographer->id, $skipped['photographer_compensations']['recipient_id']);
        $this->assertSame($repPay['bare_first_day']->id, $skipped['sales_rep_compensations']['record_id']);
        $this->assertSame('2026-10-04', $skipped['sales_rep_compensations']['stored_value']);
    }

    public function test_complimentary_report_skips_last_day_shoots_stored_with_a_time(): void
    {
        $lastDay = Shoot::factory()->create(['shoot_type' => Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT, 'scheduled_at' => null]);
        $bareLastDay = Shoot::factory()->create(['shoot_type' => Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT, 'scheduled_at' => null]);
        DB::table('shoots')->where('id', $lastDay->id)->update(['scheduled_date' => '2026-10-10 00:00:00', 'scheduled_at' => null]);
        DB::table('shoots')->where('id', $bareLastDay->id)->update(['scheduled_date' => '2026-10-10', 'scheduled_at' => null]);

        $report = $this->dryRun();

        $this->assertSame(
            ['selected_now' => 1, 'selected_date_correct' => 2, 'skipped' => 1, 'selected_only_by_text' => 0],
            $report['periods'][0]['selections']['complimentary_reshoot_report'],
        );
        $skipped = collect($report['skipped'])->firstWhere('selection', 'complimentary_reshoot_report');
        $this->assertSame($lastDay->id, $skipped['shoot_id']);
        $this->assertNull($skipped['billed_invoice_ids'], 'A report-only selection has no invoice to look for.');
        // The real report counts only the shoot stored as a bare date.
        $summary = app(PayoutReportService::class)->buildComplimentaryReshootSummary(
            Carbon::parse('2026-10-04')->startOfDay(),
            Carbon::parse('2026-10-10')->endOfDay(),
        );
        $this->assertSame(1, $summary['shoot_count']);
    }

    public function test_marks_a_skipped_shoot_that_was_billed_on_another_invoice(): void
    {
        $rep = $this->salesRep();
        $service = Service::factory()->create(['price' => 500, 'exclude_from_sales_commission' => false]);
        $shoot = $this->repShoot($rep, $service, '2026-10-04');
        $manual = Invoice::factory()->create(['role' => Invoice::ROLE_SALES_REP, 'user_id' => $rep->id, 'shoot_id' => null]);
        $manual->items()->create([
            'type' => InvoiceItem::TYPE_CHARGE, 'description' => 'Manual commission', 'quantity' => 1,
            'unit_amount' => 50, 'total_amount' => 50, 'shoot_id' => $shoot->id,
        ]);

        $report = $this->dryRun();

        $skipped = collect($report['skipped'])->firstWhere('selection', 'sales_rep_invoices');
        $this->assertSame([$manual->id], $skipped['billed_invoice_ids']);
        $this->assertSame(['skipped' => 2, 'unbilled' => 0, 'distinct_skipped' => 1, 'distinct_unbilled' => 0], $report['totals']);
    }

    public function test_covers_the_last_eight_completed_weeks_by_default(): void
    {
        $this->assertSame(0, Artisan::call('billing:weekly-gap-dry-run', ['--through' => '2026-11-25', '--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            ['2026-09-27', '2026-10-04', '2026-10-11', '2026-10-18', '2026-10-25', '2026-11-01', '2026-11-08', '2026-11-15'],
            array_column($report['periods'], 'start'),
        );
        $this->assertSame('2026-11-21', $report['periods'][7]['end']);
        $this->assertSame([], $report['skipped']);
    }

    public function test_the_dry_run_only_reads(): void
    {
        $rep = $this->salesRep();
        $service = Service::factory()->create(['price' => 500]);
        $this->repShoot($rep, $service, '2026-10-04');
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = strtolower(ltrim($query->sql));
        });
        $before = [DB::table('invoices')->count(), DB::table('invoice_items')->count()];

        $this->dryRun(['--periods' => 8]);

        $writes = array_filter($statements, fn (string $sql) => ! str_starts_with($sql, 'select') && ! str_starts_with($sql, 'pragma'));
        $this->assertSame([], array_values($writes));
        $this->assertSame($before, [DB::table('invoices')->count(), DB::table('invoice_items')->count()]);
    }

    public function test_writes_are_refused_while_the_read_only_guard_is_active(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            // Only SQLite has a per-connection write switch; elsewhere the guard passes through.
            $this->assertSame('result', ReadOnlyDatabase::run(fn () => 'result'));

            return;
        }

        try {
            ReadOnlyDatabase::run(fn () => DB::table('invoices')->insert(['invoice_number' => 'GUARD-1']));
            $this->fail('The guard allowed a write.');
        } catch (QueryException $exception) {
            $this->assertStringContainsStringIgnoringCase('readonly', str_replace(' ', '', $exception->getMessage()));
        }

        // The guard is lifted afterwards, even after a failure.
        $this->assertSame(0, (int) DB::selectOne('PRAGMA query_only')->query_only);
    }

    public function test_table_output_lists_each_skipped_record(): void
    {
        $rep = $this->salesRep();
        $service = Service::factory()->create(['price' => 500]);
        $shoot = $this->repShoot($rep, $service, '2026-10-04');

        $this->artisan('billing:weekly-gap-dry-run', ['--through' => self::THROUGH, '--periods' => 1])
            ->expectsOutputToContain('Read-only dry run')
            ->expectsOutputToContain('Skipped by the current text comparison')
            // Mockery hands each line to the first matching expectation, so the
            // narrow summary line is listed before the record row.
            ->expectsOutputToContain('2 skipped record(s) across selections, 1 distinct; 1 distinct with no invoice line.')
            ->expectsOutputToContain('shoot '.$shoot->id.' ')
            ->assertSuccessful();
    }

    private function dryRun(array $options = []): array
    {
        $exit = Artisan::call('billing:weekly-gap-dry-run', ['--through' => self::THROUGH, '--periods' => 1, '--json' => true, ...$options]);
        // output() drains the buffer, so read it once.
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    private function salesRep(): User
    {
        return User::factory()->create(['role' => 'salesRep', 'metadata' => ['repDetails' => ['commissionPercentage' => 10]]]);
    }

    private function repShoot(User $rep, Service $service, string $storedDate): Shoot
    {
        $shoot = Shoot::factory()->create([
            'rep_id' => $rep->id, 'sales_rep_pay_enabled' => true, 'workflow_status' => Shoot::WORKFLOW_COMPLETED,
            'total_quote' => 500, 'base_quote' => 500,
        ]);
        $shoot->services()->attach($service->id, ['price' => 500, 'quantity' => 1]);
        // Store the exact text shape under test, bypassing the model's date cast.
        DB::table('shoots')->where('id', $shoot->id)->update(['scheduled_date' => $storedDate]);

        return $shoot;
    }

    private function compensation(Shoot $shoot, string $type, User $recipient, string $storedEarnedAt): ShootCompensation
    {
        $compensation = ShootCompensation::create([
            'shoot_id' => $shoot->id, 'scope_key' => 'shoot-'.bin2hex(random_bytes(4)), 'recipient_type' => $type,
            'recipient_user_id' => $recipient->id, 'mode' => 'custom', 'amount' => 40,
            'earned_at' => '2026-10-05 12:00:00', 'reason_code' => 'test_fixture', 'policy_version' => '1',
        ]);
        DB::table('shoot_compensations')->where('id', $compensation->id)->update(['earned_at' => $storedEarnedAt]);

        return $compensation;
    }
}
