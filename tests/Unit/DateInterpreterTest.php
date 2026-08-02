<?php

namespace Tests\Unit;

use App\Services\ReproAi\DateInterpreter;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * The formats clients actually type, from the meeting and the Robbie screenshot.
 */
class DateInterpreterTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        // Fixed reference so "next occurrence" logic is deterministic.
        $this->now = Carbon::create(2026, 7, 26, 12, 0, 0);
    }

    private function interpret(string $message): DateInterpreter
    {
        return DateInterpreter::interpret($message, $this->now);
    }

    public function test_iso_format(): void
    {
        $this->assertSame('2026-08-14', $this->interpret('2026-08-14')->date);
    }

    public function test_iso_without_zero_padding(): void
    {
        $this->assertSame('2026-08-04', $this->interpret('2026-8-4')->date);
    }

    public function test_month_name_with_day(): void
    {
        // "बस June 5" from the meeting.
        $this->assertSame('2027-06-05', $this->interpret('June 5')->date);
    }

    public function test_month_name_with_ordinal(): void
    {
        $this->assertSame('2027-06-05', $this->interpret('June 5th')->date);
    }

    public function test_day_before_month(): void
    {
        $this->assertSame('2027-06-05', $this->interpret('5th June')->date);
    }

    public function test_abbreviated_month(): void
    {
        $this->assertSame('2026-09-09', $this->interpret('Sep 9')->date);
    }

    public function test_month_name_with_explicit_year(): void
    {
        $this->assertSame('2026-08-05', $this->interpret('August 5, 2026')->date);
    }

    public function test_bare_month_day_rolls_to_next_year_when_past(): void
    {
        // 5 June is behind the 26 July reference, so it means next year.
        $this->assertSame('2027-06-05', $this->interpret('June 5')->date);
        // 9 September is still ahead, so it stays in this year.
        $this->assertSame('2026-09-09', $this->interpret('September 9')->date);
    }

    public function test_numeric_same_value_is_not_ambiguous(): void
    {
        // "6-6" from the meeting: both readings agree, so no confirmation needed.
        $result = $this->interpret('6-6 at 5pm');

        $this->assertSame('2027-06-06', $result->date);
        $this->assertFalse($result->ambiguous);
    }

    public function test_numeric_slash_is_month_first_but_flagged_ambiguous(): void
    {
        $result = $this->interpret('5/6');

        $this->assertSame('2027-05-06', $result->date);
        $this->assertTrue($result->ambiguous);
    }

    public function test_day_above_twelve_disambiguates_order(): void
    {
        $result = $this->interpret('25/8');

        $this->assertSame('2026-08-25', $result->date);
        $this->assertFalse($result->ambiguous);
    }

    public function test_two_digit_year(): void
    {
        $this->assertSame('2027-03-04', $this->interpret('3/4/27')->date);
    }

    public function test_four_digit_year_with_slashes(): void
    {
        $this->assertSame('2026-12-25', $this->interpret('12/25/2026')->date);
    }

    public function test_relative_words(): void
    {
        $this->assertSame('2026-07-27', $this->interpret('tomorrow')->date);
        $this->assertSame('2026-07-26', $this->interpret('today')->date);
        $this->assertSame('2026-07-28', $this->interpret('day after tomorrow')->date);
    }

    public function test_impossible_dates_are_rejected(): void
    {
        $this->assertNull($this->interpret('2026-02-30')->date);
        $this->assertNull($this->interpret('13/45')->date);
    }

    public function test_unparseable_text(): void
    {
        $this->assertNull($this->interpret('whenever works for you')->date);
        $this->assertNull($this->interpret('')->date);
    }

    public function test_vague_urgency_is_read_as_the_next_day(): void
    {
        // "asap" / "soon" are treated as a scheduling signal rather than noise,
        // which matches how clients phrase an urgent booking.
        $this->assertSame('2026-07-27', $this->interpret('need it asap')->date);
        $this->assertSame('2026-07-27', $this->interpret('sometime soonish')->date);
    }

    public function test_future_only_rejects_a_past_date(): void
    {
        $result = DateInterpreter::interpret('2020-01-01', $this->now)->futureOnly($this->now);

        $this->assertNull($result->date);
    }

    public function test_future_only_keeps_today(): void
    {
        $result = DateInterpreter::interpret('today', $this->now)->futureOnly($this->now);

        $this->assertSame('2026-07-26', $result->date);
    }

    public function test_describe_is_human_readable(): void
    {
        $this->assertSame(
            'Friday, 14 August 2026',
            DateInterpreter::interpret('2026-08-14', $this->now)->describe()
        );
    }
}
