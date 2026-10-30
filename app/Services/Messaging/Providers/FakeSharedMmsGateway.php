<?php

namespace App\Services\Messaging\Providers;

use App\Models\SmsNumber;
use App\Services\Messaging\TelnyxSharedMmsGateway;
use LogicException;

/** Testing starts with provider I/O unavailable; HTTP contract tests opt in explicitly. */
class FakeSharedMmsGateway extends TelnyxSharedMmsGateway
{
    public function sender(SmsNumber $number): array { throw new LogicException('Inject a group MMS test double.'); }
    public function recipients(array $numbers, array $sender): void { throw new LogicException('Inject a group MMS test double.'); }
    public function send(string $from, array $to, string $text, string $id): array { throw new LogicException('Inject a group MMS test double.'); }
}
