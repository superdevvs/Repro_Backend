<?php

namespace Tests\Unit;

use App\Models\Message;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Services\Users\PhoneNumberChangedNotificationService;
use Mockery;
use PHPUnit\Framework\TestCase;

class PhoneNumberChangedNotificationServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_notifies_the_previous_and_new_numbers(): void
    {
        $payloads = [];
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendSms')->twice()->andReturnUsing(function (array $payload) use (&$payloads) {
            $payloads[] = $payload;
            return new Message();
        });

        $user = new User(['name' => 'Test User', 'email' => 'user@example.com', 'role' => 'photographer']);
        $user->id = 42;
        $actor = new User();
        $actor->id = 7;

        $result = (new PhoneNumberChangedNotificationService($messaging))->dispatch(
            $user,
            '(206) 736-2333',
            '(301) 555-0199',
            $actor
        );

        self::assertTrue($result['attempted']);
        self::assertTrue($result['previous']['sent']);
        self::assertTrue($result['new']['sent']);
        self::assertSame('+12067362333', $payloads[0]['to']);
        self::assertSame('PHONE_NUMBER_CHANGED_PREVIOUS', $payloads[0]['send_source']);
        self::assertSame(
            'Did you just change your phone number? If not, please contact support immediately. — R/E Pro Dashboard',
            $payloads[0]['body_text']
        );
        self::assertSame('+13015550199', $payloads[1]['to']);
        self::assertSame('PHONE_NUMBER_CHANGED_NEW', $payloads[1]['send_source']);
        self::assertSame(
            'Success! Your phone number has been updated. — R/E Pro Dashboard',
            $payloads[1]['body_text']
        );
    }

    public function test_it_ignores_formatting_only_changes(): void
    {
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendSms')->never();
        $user = new User(['name' => 'Test User', 'email' => 'user@example.com', 'role' => 'client']);

        $result = (new PhoneNumberChangedNotificationService($messaging))->dispatch(
            $user,
            '(206) 736-2333',
            '206-736-2333'
        );

        self::assertFalse($result['attempted']);
    }
}
