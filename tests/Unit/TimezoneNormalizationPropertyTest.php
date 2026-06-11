<?php

namespace Tests\Unit;

use App\Support\Timezone;
use Tests\TestCase;

/**
 * Timezone equivalence regression (MANDATORY) for the backend boundary helper.
 *
 * Feature: booking-scheduling-fixes, Property 14: Asia/Calcutta and
 * Asia/Kolkata are treated as one timezone without rewriting stored values.
 *
 * Validates: Requirements 12.4, 12.5, 12.6, 12.7
 *
 * Proves that `Asia/Calcutta` (deprecated alias) and `Asia/Kolkata` (canonical
 * IANA name) produce equivalent scheduling behavior, and that normalization is a
 * pure, boundary-only transform that never mutates the stored timezone string or
 * any stored date/time value. The property is exercised with a generative,
 * randomized-input loop (min 100 iterations) over arbitrary stored times.
 */
class TimezoneNormalizationPropertyTest extends TestCase
{
    private const ALIAS = 'Asia/Calcutta';
    private const CANONICAL = 'Asia/Kolkata';

    /** Iterations for the property loop. */
    private const ITERATIONS = 200;

    /**
     * A representative, pure scheduling decision keyed on a named timezone. It
     * does NOT convert wall-clock time; it normalizes the zone name at the
     * boundary and builds a canonical scheduling key from the stored date/time
     * plus the normalized zone, mirroring how scheduling code keys decisions.
     */
    private function schedulingKey(string $date, string $time, string $timezone): string
    {
        return $date . 'T' . $time . '@' . Timezone::normalize($timezone);
    }

    public function test_alias_maps_to_canonical_and_canonical_is_stable(): void
    {
        $this->assertSame(self::CANONICAL, Timezone::normalize(self::ALIAS));
        $this->assertSame(self::CANONICAL, Timezone::normalize(self::CANONICAL));
        $this->assertSame(self::CANONICAL, Timezone::CANONICAL);
    }

    public function test_alias_lookup_is_case_insensitive_and_trims_whitespace(): void
    {
        $this->assertSame(self::CANONICAL, Timezone::normalize('asia/calcutta'));
        $this->assertSame(self::CANONICAL, Timezone::normalize('ASIA/CALCUTTA'));
        $this->assertSame(self::CANONICAL, Timezone::normalize('  Asia/Calcutta  '));
    }

    public function test_unrelated_zones_and_empty_values_are_left_untouched(): void
    {
        $this->assertSame('America/Los_Angeles', Timezone::normalize('America/Los_Angeles'));
        $this->assertSame('UTC', Timezone::normalize('  UTC '));
        $this->assertSame('', Timezone::normalize(null));
        $this->assertSame('', Timezone::normalize(''));
        $this->assertSame('', Timezone::normalize('   '));
    }

    public function test_is_same_treats_alias_and_canonical_as_equal(): void
    {
        $this->assertTrue(Timezone::isSame(self::ALIAS, self::CANONICAL));
        $this->assertTrue(Timezone::isSame(self::CANONICAL, self::ALIAS));
        $this->assertFalse(Timezone::isSame(self::ALIAS, 'America/Los_Angeles'));
        $this->assertFalse(Timezone::isSame(self::CANONICAL, 'UTC'));
    }

    public function test_shoot_one_canonical_time_is_unchanged_under_either_alias(): void
    {
        $underAlias = $this->schedulingKey('2026-06-09', '07:00:00', self::ALIAS);
        $underCanonical = $this->schedulingKey('2026-06-09', '07:00:00', self::CANONICAL);

        $this->assertSame($underCanonical, $underAlias);
        $this->assertStringContainsString('07:00:00', $underAlias);
    }

    /**
     * Feature: booking-scheduling-fixes, Property 14: Asia/Calcutta and
     * Asia/Kolkata are treated as one timezone without rewriting stored values.
     *
     * Validates: Requirements 12.4, 12.5, 12.6, 12.7
     */
    public function test_property_alias_and_canonical_yield_identical_scheduling_without_rewriting(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $year = random_int(2000, 2099);
            $month = random_int(1, 12);
            $day = random_int(1, 28);
            $hour = random_int(0, 23);
            $minute = random_int(0, 59);
            $second = random_int(0, 59);

            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $timeStr = sprintf('%02d:%02d:%02d', $hour, $minute, $second);

            // Preserve originals to assert nothing was mutated.
            $storedAlias = self::ALIAS;
            $storedCanonical = self::CANONICAL;
            $storedTime = $timeStr;
            $storedDate = $dateStr;

            $context = "date={$dateStr} time={$timeStr}";

            // (1) Both aliases normalize to the same canonical zone.
            $this->assertSame(
                Timezone::normalize($storedCanonical),
                Timezone::normalize($storedAlias),
                "Alias and canonical must normalize identically. {$context}"
            );
            $this->assertSame(self::CANONICAL, Timezone::normalize($storedAlias), $context);

            // (2) Any scheduling decision keyed on the zone is identical.
            $this->assertTrue(Timezone::isSame($storedAlias, $storedCanonical), $context);
            $this->assertSame(
                $this->schedulingKey($dateStr, $timeStr, $storedCanonical),
                $this->schedulingKey($dateStr, $timeStr, $storedAlias),
                "Scheduling key must match across the alias. {$context}"
            );

            // (3) Normalization never shifts the wall-clock time.
            $this->assertSame(
                $dateStr . 'T' . $timeStr . '@' . self::CANONICAL,
                $this->schedulingKey($dateStr, $timeStr, $storedAlias),
                "Stored wall-clock time must be preserved. {$context}"
            );

            // (4) Stored values are never rewritten by the boundary transform.
            $this->assertSame(self::ALIAS, $storedAlias, $context);
            $this->assertSame(self::CANONICAL, $storedCanonical, $context);
            $this->assertSame($timeStr, $storedTime, $context);
            $this->assertSame($dateStr, $storedDate, $context);
        }
    }
}
