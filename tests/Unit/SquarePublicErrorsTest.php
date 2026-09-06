<?php

namespace Tests\Unit;

use App\Support\SquarePublicErrors;
use PHPUnit\Framework\TestCase;

class SquarePublicErrorsTest extends TestCase
{
    public function test_known_declines_preserve_guidance_without_provider_detail_fields(): void
    {
        $sdkError = new class {
            public function getCode(): string { return 'VERIFY_CVV_FAILURE'; }
            public function getDetail(): string { throw new \LogicException('Provider details must never be read.'); }
        };
        $safe = SquarePublicErrors::from([$sdkError, ['code' => 'CARD_EXPIRED', 'detail' => 'secret-canary', 'request' => ['token' => 'secret-canary']]]);
        $this->assertSame('VERIFY_CVV_FAILURE', $safe[0]['code']);
        $this->assertSame('Check the card security code and try again.', $safe[0]['detail']);
        $this->assertSame('This card has expired. Use a current card.', $safe[1]['detail']);
        $this->assertStringNotContainsString('secret-canary', json_encode($safe));
    }

    public function test_unknown_or_malformed_provider_errors_use_fixed_generic_code_and_message(): void
    {
        foreach ([[['code' => 'secret-canary', 'detail' => 'secret-canary']], [['code' => ['secret-canary']]], 'secret-canary', null] as $value) {
            $safe = SquarePublicErrors::from($value);
            $this->assertSame('PAYMENT_FAILED', $safe[0]['code']);
            $this->assertStringNotContainsString('secret-canary', json_encode($safe));
        }
    }
}
