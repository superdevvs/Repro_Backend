<?php

namespace Tests\Unit\Support;

use App\Support\ReportingWeek;
use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ReportingWeekTest extends TestCase
{
    public function test_containing_week_always_runs_from_sunday_through_saturday(): void
    {
        [$start, $end] = ReportingWeek::containing(Carbon::parse('2026-08-31 14:30:00', 'America/New_York'));

        $this->assertSame('2026-08-30 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-05 23:59:59', $end->format('Y-m-d H:i:s'));
        $this->assertSame('America/New_York', $start->timezoneName);
        $this->assertSame(Carbon::SUNDAY, $start->dayOfWeek);
        $this->assertSame(Carbon::SATURDAY, $end->dayOfWeek);
    }

    public function test_last_completed_week_does_not_include_the_current_partial_week(): void
    {
        [$start, $end] = ReportingWeek::lastCompleted('2026-08-31 10:00:00');

        $this->assertSame('2026-08-23 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-29 23:59:59', $end->format('Y-m-d H:i:s'));
    }

    public function test_range_is_expanded_to_complete_sunday_through_saturday_weeks(): void
    {
        [$start, $end] = ReportingWeek::normalizeRange('2026-08-31', '2026-09-09');

        $this->assertSame('2026-08-30', $start->toDateString());
        $this->assertSame('2026-09-12', $end->toDateString());
    }

    public function test_reversed_range_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReportingWeek::normalizeRange('2026-09-06', '2026-08-31');
    }
}
