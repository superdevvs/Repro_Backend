<?php

namespace Tests\Unit\Support;

use App\Support\SupportContact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SupportContactTest extends TestCase
{
    #[DataProvider('supportPhoneVariantProvider')]
    public function test_known_support_phone_variants_use_one_display_format(string $variant): void
    {
        $this->assertSame(
            'Call '.SupportContact::PHONE_DISPLAY.' for support.',
            SupportContact::normalizeReferences("Call {$variant} for support.")
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function supportPhoneVariantProvider(): array
    {
        return [
            'obsolete dashed' => ['202-868-1113'],
            'obsolete parenthesized' => ['(202) 868-1113'],
            'obsolete dotted' => ['202.868.1113'],
            'obsolete spaced' => ['202 868 1113'],
            'obsolete e164' => ['+12028681113'],
            'current dashed' => ['202-868-1663'],
            'current parenthesized' => ['(202) 868-1663'],
            'current dotted' => ['202.868.1663'],
            'current spaced' => ['202 868 1663'],
            'current e164' => ['+12028681663'],
        ];
    }

    public function test_tel_links_use_e164_target_and_canonical_visible_copy(): void
    {
        $html = '<a href="tel:202-868-1113">202-868-1113</a>';

        $this->assertSame(
            '<a href="tel:'.SupportContact::PHONE_E164.'">'.SupportContact::PHONE_DISPLAY.'</a>',
            SupportContact::normalizeReferences($html)
        );
    }

    public function test_unrelated_phone_numbers_are_not_changed(): void
    {
        $content = 'Client (202) 555-0199; photographer +12025550188; access 484-868-7901.';

        $this->assertSame($content, SupportContact::normalizeReferences($content));
    }
}
