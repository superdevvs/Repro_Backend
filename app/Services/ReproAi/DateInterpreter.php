<?php

namespace App\Services\ReproAi;

use Carbon\Carbon;

/**
 * Interpret a date the way a client would write one.
 *
 * Meeting 26 Jul 2026 [00:28:28]: "clients बड़े बेवकूफ होते हैं, वो लिखेंगे June 5th,
 * बस June 5, या 6-6 लिखेंगे, 6-6 at 5pm". Robbie meanwhile asked for
 * `YYYY-MM-DD` (A1.docx Robbie screenshot), which is the same problem from the
 * other side: the assistant demanded a machine format because it could not read
 * a human one.
 *
 * `parseDateFromMessage` was duplicated verbatim in BookShootFlow,
 * ManageBookingFlow and PhotographerManagementFlow, and three other call sites
 * used a bare `Carbon::parse`. This is the single implementation.
 *
 * Ambiguity is reported rather than guessed: `6-6` is unambiguous, but `5/6`
 * could be 5 June or 6 May, so the caller confirms before acting.
 */
class DateInterpreter
{
    /** Interpreted date as `Y-m-d`, or null when nothing could be read. */
    public ?string $date = null;

    /** True when more than one reading was plausible. */
    public bool $ambiguous = false;

    /** How the date was recognised, for logging and confirmation copy. */
    public ?string $source = null;

    private function __construct(?string $date, bool $ambiguous = false, ?string $source = null)
    {
        $this->date = $date;
        $this->ambiguous = $ambiguous;
        $this->source = $source;
    }

    public static function interpret(string $message, ?Carbon $now = null): self
    {
        $now = $now ? $now->copy() : Carbon::now();
        $text = strtolower(trim($message));

        if ($text === '') {
            return new self(null);
        }

        if ($relative = self::relative($text, $now)) {
            return new self($relative, false, 'relative');
        }

        // ISO first: unambiguous by definition. Zero-padding is not required.
        if (preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $message, $m)) {
            $date = self::build((int) $m[1], (int) $m[2], (int) $m[3]);
            if ($date) {
                return new self($date, false, 'iso');
            }
        }

        // Month name in either order: "June 5", "5 June", "Jun 5th, 2027".
        if ($named = self::monthName($message, $now)) {
            return new self($named, false, 'month-name');
        }

        // Numeric separators: US month-first convention, matching the audience.
        // `6-6` and `6/6` are treated the same; a value above 12 in the first
        // position can only be a day, so the order flips without ambiguity.
        if (preg_match('#\b(\d{1,2})[/\-.](\d{1,2})(?:[/\-.](\d{2,4}))?\b#', $message, $m)) {
            $first = (int) $m[1];
            $second = (int) $m[2];
            $year = isset($m[3]) ? self::normalizeYear((int) $m[3]) : null;

            [$month, $day, $ambiguous] = self::orderMonthDay($first, $second);
            if ($month === null) {
                return new self(null);
            }

            $date = self::build($year ?? self::inferYear($month, $day, $now), $month, $day);
            if ($date) {
                return new self($date, $ambiguous, 'numeric');
            }
        }

        return new self(null);
    }

    /** Only accept a date that is today or later, for scheduling use. */
    public function futureOnly(?Carbon $now = null): self
    {
        if ($this->date === null) {
            return $this;
        }

        $now = $now ? $now->copy() : Carbon::now();

        try {
            $parsed = Carbon::parse($this->date);
        } catch (\Throwable) {
            return new self(null);
        }

        if ($parsed->startOfDay()->lt($now->copy()->startOfDay())) {
            return new self(null);
        }

        return $this;
    }

    /** Human-readable form for a confirmation question. */
    public function describe(): ?string
    {
        if ($this->date === null) {
            return null;
        }

        try {
            return Carbon::parse($this->date)->format('l, j F Y');
        } catch (\Throwable) {
            return $this->date;
        }
    }

    private static function relative(string $text, Carbon $now): ?string
    {
        if (str_contains($text, 'day after tomorrow')) {
            return $now->copy()->addDays(2)->format('Y-m-d');
        }
        if (str_contains($text, 'tomorrow')) {
            return $now->copy()->addDay()->format('Y-m-d');
        }
        if (str_contains($text, 'today') || str_contains($text, 'tonight')) {
            return $now->copy()->format('Y-m-d');
        }
        if (str_contains($text, 'next available') || str_contains($text, 'asap') || str_contains($text, 'soon')) {
            return $now->copy()->addDay()->format('Y-m-d');
        }
        if ($text === 'next week') {
            return $now->copy()->addWeek()->startOfWeek()->format('Y-m-d');
        }
        if ($text === 'this week' || $text === 'week') {
            $next = $now->copy()->addDay();
            if ($next->isWeekend()) {
                $next = $now->copy()->next(Carbon::MONDAY);
            }
            return $next->format('Y-m-d');
        }
        if ($text === 'this weekend' || $text === 'weekend') {
            return $now->copy()->next(Carbon::SATURDAY)->format('Y-m-d');
        }

        $days = [
            'monday' => Carbon::MONDAY,
            'tuesday' => Carbon::TUESDAY,
            'wednesday' => Carbon::WEDNESDAY,
            'thursday' => Carbon::THURSDAY,
            'friday' => Carbon::FRIDAY,
            'saturday' => Carbon::SATURDAY,
            'sunday' => Carbon::SUNDAY,
        ];
        foreach ($days as $name => $carbonDay) {
            if (str_contains($text, $name)) {
                return $now->copy()->next($carbonDay)->format('Y-m-d');
            }
        }

        return null;
    }

    private static function monthName(string $message, Carbon $now): ?string
    {
        $months = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t)?(?:ember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';

        // "June 5th 2027" / "June 5"
        if (preg_match('/\b(' . $months . ')\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s*(\d{2,4}))?\b/i', $message, $m)) {
            return self::fromMonthName($m[1], (int) $m[2], $m[3] ?? null, $now);
        }

        // "5th June 2027" / "5 June"
        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+(' . $months . ')\.?(?:,?\s*(\d{2,4}))?\b/i', $message, $m)) {
            return self::fromMonthName($m[2], (int) $m[1], $m[3] ?? null, $now);
        }

        return null;
    }

    private static function fromMonthName(string $monthWord, int $day, ?string $yearRaw, Carbon $now): ?string
    {
        $month = self::monthNumber($monthWord);
        if ($month === null) {
            return null;
        }

        $year = $yearRaw !== null && $yearRaw !== ''
            ? self::normalizeYear((int) $yearRaw)
            : self::inferYear($month, $day, $now);

        return self::build($year, $month, $day);
    }

    private static function monthNumber(string $word): ?int
    {
        $key = strtolower(substr($word, 0, 3));

        return [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
            'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        ][$key] ?? null;
    }

    /**
     * Decide which number is the month, US-first.
     *
     * Returns [month, day, ambiguous]. When both values could be a month the
     * result is flagged ambiguous so the caller can confirm.
     */
    private static function orderMonthDay(int $first, int $second): array
    {
        if ($first >= 1 && $first <= 12 && $second >= 1 && $second <= 31) {
            // Both plausible as a month → ambiguous unless they are equal.
            $ambiguous = $second >= 1 && $second <= 12 && $first !== $second;

            return [$first, $second, $ambiguous];
        }

        // First value cannot be a month, so it must be the day.
        if ($second >= 1 && $second <= 12 && $first >= 1 && $first <= 31) {
            return [$second, $first, false];
        }

        return [null, null, false];
    }

    /** A bare month/day refers to the next occurrence, not a past one. */
    private static function inferYear(int $month, int $day, Carbon $now): int
    {
        $candidate = Carbon::create($now->year, $month, min($day, 28), 0, 0, 0);

        return $candidate && $candidate->startOfDay()->lt($now->copy()->startOfDay())
            ? $now->year + 1
            : $now->year;
    }

    private static function normalizeYear(int $year): int
    {
        if ($year >= 100) {
            return $year;
        }

        // Two-digit years are this century; nobody books a shoot in 1927.
        return 2000 + $year;
    }

    private static function build(int $year, int $month, int $day): ?string
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
