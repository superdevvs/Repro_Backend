<?php

namespace Tests\Feature;

use App\Services\ReproAi\Flows\BookShootFlow;
use App\Services\ReproAi\ShootOperatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The opening booking message must be decomposed, not swallowed.
 *
 * Verified on production (2 Aug 2026) as an impersonated client: "I want to book a
 * shoot" came back as "When would you like the shoot for **I want to book a shoot**?",
 * and "book a shoot next Friday afternoon at 400 Oak Street, Austin TX" produced the
 * same thing with the whole sentence as the property — the supplied date ignored.
 *
 * These tests exercise the extraction directly. No booking is created: nothing here
 * persists a shoot, and the assertions below confirm the tables stay empty.
 */
class BookShootOpeningSlotExtractionTest extends TestCase
{
    use RefreshDatabase;

    private function extract(string $message): array
    {
        $method = new ReflectionMethod(BookShootFlow::class, 'extractOpeningSlots');

        return $method->invoke(app(BookShootFlow::class), $message);
    }

    private function applySchedule(string $scheduleText): array
    {
        $data = [];
        $method = new ReflectionMethod(BookShootFlow::class, 'applyOpeningSchedule');
        $outcome = $method->invokeArgs(app(BookShootFlow::class), [&$data, $scheduleText]);

        return ['outcome' => $outcome, 'data' => $data];
    }

    public function test_the_reported_message_separates_the_date_from_the_address(): void
    {
        $slots = $this->extract('Book a shoot next Friday afternoon at 400 Oak Street, Austin TX');

        $this->assertSame('address', $slots['kind']);
        $this->assertSame('400 Oak Street, Austin TX', $slots['address']);
        $this->assertSame('next Friday afternoon', $slots['schedule_text']);
    }

    public function test_the_supplied_date_phrase_is_preserved_and_not_asked_for_again(): void
    {
        $applied = $this->applySchedule('next Friday afternoon');

        // A date was understood, so the flow must not re-ask for one.
        $this->assertContains($applied['outcome'], ['date', 'date_and_time']);
        $this->assertNotEmpty($applied['data']['date']);
        $this->assertSame('next Friday afternoon', $applied['data']['date_label']);
    }

    public function test_afternoon_is_carried_through_as_the_time_window(): void
    {
        $applied = $this->applySchedule('next Friday afternoon');

        $this->assertSame('date_and_time', $applied['outcome']);
        $this->assertNotEmpty($applied['data']['time_window']);
    }

    public function test_a_bare_booking_intent_carries_no_address(): void
    {
        foreach ([
            'I want to book a shoot',
            'book a shoot',
            "let's book a new shoot",
            'Hi, I would like to book a photoshoot',
            'Can you schedule a shoot',
        ] as $message) {
            $slots = $this->extract($message);

            $this->assertSame('intent_only', $slots['kind'], "Failed for: {$message}");
            $this->assertNull($slots['address'], "Failed for: {$message}");
        }
    }

    public function test_a_date_only_message_is_not_stored_as_a_property(): void
    {
        $slots = $this->extract('next Friday afternoon');

        $this->assertSame('schedule_only', $slots['kind']);
        $this->assertNull($slots['address']);
        $this->assertSame('next Friday afternoon', $slots['schedule_text']);
    }

    public function test_an_address_only_message_carries_no_schedule(): void
    {
        $slots = $this->extract('400 Oak Street, Austin TX');

        $this->assertSame('address', $slots['kind']);
        $this->assertSame('400 Oak Street, Austin TX', $slots['address']);
        $this->assertSame('', $slots['schedule_text']);
    }

    public function test_an_ambiguous_numeric_date_is_read_as_a_date_not_an_address(): void
    {
        // "5pm" is not a house number, so the address branch must not claim it.
        $slots = $this->extract('6-6 at 5pm');

        $this->assertSame('schedule_only', $slots['kind']);
        $this->assertNull($slots['address']);

        $applied = $this->applySchedule('6-6 at 5pm');
        $this->assertNotSame('none', $applied['outcome']);
        $this->assertSame('06-06', substr((string) $applied['data']['date'], 5), 'Ambiguous 6-6 must resolve US-first to 6 June.');
    }

    public function test_a_named_property_is_still_treated_as_a_property(): void
    {
        // Regression guard: not every property is a numbered street address.
        $slots = $this->extract('The Beach House');

        $this->assertSame('address', $slots['kind']);
        $this->assertSame('The Beach House', $slots['address']);
    }

    public function test_an_unparseable_schedule_phrase_does_not_fill_the_date(): void
    {
        $applied = $this->applySchedule('sometime whenever');

        $this->assertSame('none', $applied['outcome']);
        $this->assertArrayNotHasKey('date', $applied['data']);
    }

    public function test_extraction_creates_no_booking(): void
    {
        $this->extract('Book a shoot next Friday afternoon at 400 Oak Street, Austin TX');
        $this->applySchedule('next Friday afternoon');

        $this->assertSame(0, DB::table('shoots')->count(), 'Slot extraction must never create a shoot.');
    }

    /**
     * The A1 shoot-operator routing fix must survive this change: an explicit
     * shoot number is management, not a new booking, so it must never reach the
     * booking flow's slot extraction at all.
     *
     * Verified against production on 2 Aug 2026: "reschedule shoot #63" returned
     * "Shoot #63 overview / Property: 11 Wall Street".
     */
    public function test_an_explicit_shoot_number_stays_with_the_shoot_operator(): void
    {
        $method = new ReflectionMethod(ShootOperatorService::class, 'looksLikeShootOperatorRequest');
        $method->setAccessible(true);
        $operator = (new \ReflectionClass(ShootOperatorService::class))->newInstanceWithoutConstructor();

        foreach (['reschedule shoot #63', 'reschedule shoot #12 to Thursday'] as $message) {
            $this->assertTrue(
                $method->invoke($operator, $message, []),
                "Management intent must stay with the shoot operator: {$message}"
            );
        }

        // And the combined booking sentence must still be released to booking.
        $this->assertFalse(
            $method->invoke($operator, 'Book a shoot next Friday afternoon at 400 Oak Street, Austin TX', []),
            'A new booking request must still reach the booking flow.'
        );
    }
}
