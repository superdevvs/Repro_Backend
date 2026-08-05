<?php

namespace Tests\Unit;

use App\Services\ReproAi\ShootOperatorService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Which Robbie requests the shoot operator should claim.
 *
 * The operator runs before the booking and management orchestrators, and its
 * keyword gate matched the bare word "shoot". So "I want to book a shoot" was
 * claimed here, and since no existing shoot matched, the reply could only ever
 * be "I could not confidently match that to a shoot" — the booking flow never
 * ran and Robbie appeared broken (A1.docx Robbie screenshot).
 *
 * These cover the routing decision only, so the service is not constructed:
 * the two methods under test touch no dependencies.
 */
class ShootOperatorRoutingTest extends TestCase
{
    private function claimsRequest(string $message, array $context = []): bool
    {
        $method = new ReflectionMethod(ShootOperatorService::class, 'looksLikeShootOperatorRequest');
        $method->setAccessible(true);

        $service = (new \ReflectionClass(ShootOperatorService::class))->newInstanceWithoutConstructor();

        return $method->invoke($service, $message, $context);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bookingRequests')]
    public function test_it_releases_new_booking_requests_to_the_booking_flow(string $message): void
    {
        $this->assertFalse(
            $this->claimsRequest($message),
            "Expected the operator to release a booking request: {$message}"
        );
    }

    public static function bookingRequests(): array
    {
        return [
            'plain booking' => ['I want to book a shoot'],
            'booking with date and address' => ['I want to book a shoot next Friday afternoon at 400 Oak Street, Austin TX'],
            'book shoot shorthand' => ['book shoot for tomorrow morning'],
            'new shoot' => ['can you set up a new shoot at 12 Elm Road'],
            'create a shoot' => ['create a shoot for 900 Lake Ave on Tuesday'],
            'schedule a shoot' => ['schedule a shoot for next week please'],
            'another one' => ['book another shoot for the same client'],
            'polite phrasing' => ["I'd like to book a shoot on the 14th"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('operatorRequests')]
    public function test_it_still_claims_operations_on_an_existing_shoot(string $message): void
    {
        $this->assertTrue(
            $this->claimsRequest($message),
            "Expected the operator to claim: {$message}"
        );
    }

    public static function operatorRequests(): array
    {
        return [
            'reschedule an existing shoot' => ['reschedule the shoot at 123 Main Street'],
            'reschedule by id' => ['reschedule shoot #12 to Thursday'],
            // An explicit id names a record to act on, so it stays with the
            // operator even when the wording also reads like a booking.
            'booking wording with an explicit id' => ['book a shoot slot for shoot #7'],
            'raw uploads' => ['show me the raw uploads'],
            'iguide sync' => ['sync iguide for this property'],
            'floorplan' => ['is the floor plan ready'],
            'overview' => ['open the overview'],
            'issue' => ['flag an issue on this shoot'],
        ];
    }

    public function test_shoot_details_context_always_claims_the_request(): void
    {
        // On a shoot detail page even a booking-sounding message is about the
        // shoot in view.
        $this->assertTrue($this->claimsRequest('book a shoot', ['page' => 'shoot_details']));
        $this->assertTrue($this->claimsRequest('book a shoot', ['entityType' => 'shoot']));
    }

    public function test_unrelated_messages_are_not_claimed(): void
    {
        $this->assertFalse($this->claimsRequest('what is my outstanding balance'));
        $this->assertFalse($this->claimsRequest('how do I add a photographer'));
    }
}
