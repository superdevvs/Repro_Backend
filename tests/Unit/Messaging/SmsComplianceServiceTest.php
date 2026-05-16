<?php

namespace Tests\Unit\Messaging;

use App\Services\Messaging\AiSms\SmsComplianceService;
use Tests\TestCase;

class SmsComplianceServiceTest extends TestCase
{
    public function test_detects_stop_keywords_case_insensitively(): void
    {
        $svc = new SmsComplianceService();

        foreach (['STOP', 'stop', 'Stop ', 'UNSUBSCRIBE', 'cancel', 'optout', 'END'] as $body) {
            $this->assertSame('stop', $svc->detectKeyword($body), "expected 'stop' for [{$body}]");
        }
    }

    public function test_detects_start_and_help_keywords(): void
    {
        $svc = new SmsComplianceService();

        $this->assertSame('start', $svc->detectKeyword('START'));
        $this->assertSame('start', $svc->detectKeyword('subscribe'));
        $this->assertSame('help', $svc->detectKeyword('HELP'));
        $this->assertSame('help', $svc->detectKeyword('info'));
    }

    public function test_returns_null_for_non_keyword_messages(): void
    {
        $svc = new SmsComplianceService();

        $this->assertNull($svc->detectKeyword('Hi there, can you reschedule shoot 42?'));
        $this->assertNull($svc->detectKeyword('stop the booking please')); // multi-word
        $this->assertNull($svc->detectKeyword(''));
    }

    public function test_static_replies_use_config_or_defaults(): void
    {
        $svc = new SmsComplianceService();

        config()->set('services.telnyx.ai_static_replies.stop', 'Custom stop reply.');
        $this->assertSame('Custom stop reply.', $svc->staticReplyFor('stop'));

        config()->set('services.telnyx.ai_static_replies.stop', '');
        $this->assertStringContainsString('unsubscribed', strtolower($svc->staticReplyFor('stop')));
        $this->assertStringContainsString('start', strtolower($svc->staticReplyFor('stop')));
    }
}
