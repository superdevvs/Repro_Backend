<?php

namespace Tests\Unit\Messaging;

use App\Models\Message;
use App\Services\Messaging\ClientNotificationTimingPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClientNotificationTimingPolicyTest extends TestCase
{
    #[DataProvider('notificationChannels')]
    public function test_email_is_immediate_and_other_transports_keep_routine_quiet_hours(
        string $channel,
        string $provider,
        bool $requiresQuietHours,
    ): void {
        $message = new Message([
            'channel' => $channel,
            'provider' => $provider,
            'direction' => 'OUTBOUND',
            'send_source' => 'SHOOT_DELIVERED',
        ]);

        $this->assertSame($requiresQuietHours,
            (new ClientNotificationTimingPolicy)->requiresQuietHours($message, 'America/New_York'));
    }

    public static function notificationChannels(): array
    {
        return [
            'email' => ['EMAIL', 'CAKEMAIL', false],
            'sms' => ['SMS', 'TELNYX', true],
            'push' => ['PUSH', 'MOBILE_PUSH', true],
        ];
    }
}
