<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Shoot;
use App\Models\ShootCompensation;
use App\Support\ReportingWeek;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only replay of the weekly invoice and payout selections (Phase 0 item 8).
 *
 * The weekly runs compare stored text dates with text bounds. Dates are stored
 * in two shapes ("2026-10-05" and "2026-10-05 00:00:00"). A bare date sorts
 * before "2026-10-05 00:00:00", so a first-day record stored as a bare date is
 * never picked by a "start 00:00:00" bound, and a record stored with a time is
 * never picked by a bare "end" bound. For each selection this compares today's
 * exact query with a date-correct one (the first ten characters as a calendar
 * date) and lists every record only one of them picks.
 *
 * The filters copy the generators on main. When a generator's filters change,
 * change the matching query here. The feature test runs the real sales-rep
 * generator to keep the two in step.
 */
final class WeeklyBillingGapAudit
{
    private const CLOSED_INVOICE_STATUSES = ['cancelled', 'void'];

    /**
     * Sunday-to-Saturday weeks, oldest first, ending with the last completed week.
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    public function periods(int $count, CarbonInterface|string|null $through = null): array
    {
        [$start, $end] = ReportingWeek::lastCompleted($through);
        $periods = [];
        for ($offset = $count - 1; $offset >= 0; $offset--) {
            $periods[] = [$start->copy()->subWeeks($offset), $end->copy()->subWeeks($offset)];
        }

        return $periods;
    }

    public function audit(Carbon $start, Carbon $end): array
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->endOfDay();
        $dates = [$start->toDateString(), $end->toDateString()];
        $dayBounds = [$start->toDateTimeString(), $end->toDateTimeString()];
        $salesRepLines = fn (array $shootIds) => $this->invoicedShoots($shootIds, Invoice::ROLE_SALES_REP);
        $compensationLines = fn (array $ids) => $this->invoicedCompensations($ids);

        return [
            // InvoiceService::generateSalesRepInvoicesForPeriod, shoot commission lines.
            'sales_rep_invoices' => $this->compare(
                $this->salesRepShoots([Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT]),
                'scheduled_date', $dayBounds, $dates, 'id', 'rep_id', $salesRepLines,
            ),
            // PayoutReportService::buildSalesRepSummaries (weekly payout report).
            'sales_rep_payout_report' => $this->compare(
                $this->salesRepShoots([Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT, Shoot::SHOOT_TYPE_INTERNAL_TEST]),
                'scheduled_date', $dayBounds, $dates, 'id', 'rep_id', $salesRepLines,
            ),
            // InvoiceService::generateForPeriod. Only complimentary-reshoot pay becomes a line there;
            // other photographer pay is selected with whereDate, which is already date-correct.
            'photographer_compensations' => $this->compare(
                $this->compensations(ShootCompensation::RECIPIENT_PHOTOGRAPHER)
                    ->whereDoesntHave('shoot', fn ($query) => $query->where('shoot_type', Shoot::SHOOT_TYPE_INTERNAL_TEST))
                    ->whereHas('shoot', fn ($query) => $query->where('shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT)),
                'earned_at', [$start, $end], $dates, 'shoot_id', 'recipient_user_id', $compensationLines,
            ),
            // InvoiceService::generateSalesRepInvoicesForPeriod, compensation lines. Only
            // complimentary-reshoot pay becomes a line (buildSalesRepCompensationRow).
            'sales_rep_compensations' => $this->compare(
                $this->compensations(ShootCompensation::RECIPIENT_SALES_REP)
                    ->whereHas('shoot', fn ($query) => $query->where('shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT)),
                'earned_at', [$start, $end], $dates, 'shoot_id', 'recipient_user_id', $compensationLines,
            ),
            // PayoutReportService::buildComplimentaryReshootSummary, the branch without scheduled_at.
            'complimentary_reshoot_report' => $this->compare(
                Shoot::query()->where('shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT)
                    ->whereNull('scheduled_at')->whereNotNull('scheduled_date'),
                'scheduled_date', $dates, $dates, 'id', null, null,
            ),
        ];
    }

    /**
     * The calendar date a stored value means, or null when it does not start with one.
     */
    public static function calendarDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/\A(\d{4})-(\d{2})-(\d{2})/', $value, $match)
            || ! checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            return null;
        }

        return $match[1].'-'.$match[2].'-'.$match[3];
    }

    private function salesRepShoots(array $excludedTypes): Builder
    {
        return Shoot::query()
            ->excludeHistoricalImportsFromNewBilling()
            ->where(fn ($query) => $query->whereNull('shoot_type')->orWhereNotIn('shoot_type', $excludedTypes))
            ->where('sales_rep_pay_enabled', true)
            ->whereNotIn('workflow_status', [Shoot::STATUS_ON_HOLD, Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED]);
    }

    private function compensations(string $recipientType): Builder
    {
        return ShootCompensation::query()
            ->where('recipient_type', $recipientType)
            ->where('mode', '!=', ShootCompensation::MODE_NONE)
            ->whereNull('voided_at')
            ->whereNotNull('recipient_user_id')
            ->where('amount', '!=', 0);
    }

    private function compare(
        Builder $base,
        string $column,
        array $textBounds,
        array $dates,
        string $shootColumn,
        ?string $recipientColumn,
        ?Closure $invoiced,
    ): array {
        $model = $base->getModel();
        $key = $model->getQualifiedKeyName();
        $stored = $model->qualifyColumn($column);
        // Today's comparison, exactly as the generator writes it.
        $selectedNow = $base->clone()->whereBetween($stored, $textBounds)->toBase()->pluck($key)
            ->mapWithKeys(fn ($id) => [(int) $id => true]);
        $wrapped = $base->getQuery()->getGrammar()->wrap($stored);
        $columns = [$key.' as record_id', $stored.' as stored_value', $model->qualifyColumn($shootColumn).' as shoot_id'];
        if ($recipientColumn !== null) {
            $columns[] = $model->qualifyColumn($recipientColumn).' as recipient_id';
        }
        $candidates = $base->clone()
            ->where(fn (Builder $query) => $query->whereBetween($stored, $textBounds)
                ->orWhereRaw("substr({$wrapped}, 1, 10) between ? and ?", $dates))
            ->toBase()->orderBy($key)->get($columns);

        $dateCorrect = 0;
        $skipped = [];
        $onlyText = [];
        foreach ($candidates as $row) {
            $day = self::calendarDate($row->stored_value);
            $inPeriod = $day !== null && $day >= $dates[0] && $day <= $dates[1];
            $picked = $selectedNow->has((int) $row->record_id);
            $dateCorrect += $inPeriod ? 1 : 0;
            if ($inPeriod === $picked) {
                continue;
            }
            $record = [
                'record_type' => $model instanceof Shoot ? 'shoot' : 'compensation',
                'record_id' => (int) $row->record_id,
                'shoot_id' => $row->shoot_id === null ? null : (int) $row->shoot_id,
                'stored_value' => (string) $row->stored_value,
                'recipient_id' => isset($row->recipient_id) ? (int) $row->recipient_id : null,
                'billed_invoice_ids' => null,
            ];
            if ($inPeriod) {
                $skipped[] = $record;
            } else {
                $onlyText[] = $record;
            }
        }

        if ($invoiced !== null && $skipped !== []) {
            $lookupColumn = $model instanceof Shoot ? 'shoot_id' : 'record_id';
            $lines = $invoiced(array_column($skipped, $lookupColumn));
            foreach ($skipped as $index => $record) {
                $skipped[$index]['billed_invoice_ids'] = $lines[$record[$lookupColumn]] ?? [];
            }
        }

        return [
            'selected_now' => $selectedNow->count(),
            'selected_date_correct' => $dateCorrect,
            'skipped' => $skipped,
            'selected_only_by_text' => $onlyText,
        ];
    }

    /** @return array<int, list<int>> shoot id => invoice ids holding a charge line for it */
    private function invoicedShoots(array $shootIds, string $role): array
    {
        return $this->invoiceLines('shoot_id', $shootIds, fn ($query) => $query->where('invoices.role', $role)
            ->where('invoice_items.type', InvoiceItem::TYPE_CHARGE));
    }

    /** @return array<int, list<int>> compensation id => invoice ids holding its line */
    private function invoicedCompensations(array $compensationIds): array
    {
        return $this->invoiceLines('shoot_compensation_id', $compensationIds, fn ($query) => $query);
    }

    private function invoiceLines(string $column, array $ids, Closure $scope): array
    {
        $rows = $scope(DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereIn('invoice_items.'.$column, array_values(array_unique($ids)))
            ->where(fn ($query) => $query->whereNull('invoices.status')
                ->orWhereNotIn('invoices.status', self::CLOSED_INVOICE_STATUSES)))
            ->orderBy('invoice_items.invoice_id')
            ->get(['invoice_items.'.$column.' as owner_id', 'invoice_items.invoice_id']);
        $lines = [];
        foreach ($rows as $row) {
            $lines[(int) $row->owner_id][] = (int) $row->invoice_id;
        }

        return array_map(fn (array $invoiceIds) => array_values(array_unique($invoiceIds)), $lines);
    }
}
