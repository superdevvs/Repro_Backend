<?php

namespace Tests\Unit;

use App\Services\IguideLab\LabTourText;
use Tests\TestCase;

/** Contract text rule (tourContract.ts:81, D19): replaced, never emitted raw; UTF-16 bounds like zod's .max(). */
class IguideLabTourTextTest extends TestCase
{
    public function test_unsafe_words_and_urls_use_the_fallback(): void
    {
        $unsafe = ['bearer', 'Bearer', 'Bearer abc', 'accessToken', 'ACCESSTOKEN', 'api key', 'api_key', 'api-key', 'apikey', 'API-Key',
            'secret', 'Secretary desk', 'http://', 'https://', 'see https://example.test/a', 'HTTP://X'];
        foreach ($unsafe as $text) {
            $this->assertFalse(LabTourText::safe($text), $text);
            $this->assertSame('Item', LabTourText::clean($text, 1, 60, 'Item'), $text);
        }
        // No partial stripping: the whole string is replaced.
        $this->assertSame('Surface', LabTourText::clean('Counter by https://x.test', 1, 60, 'Surface'));
        $this->assertNull(LabTourText::clean('api key', 1, 120, null));
        $this->assertTrue(LabTourText::safe('Dish soap by the sink'));
        $this->assertSame('Dish soap', LabTourText::clean('Dish soap', 1, 60, 'Item'));
        $this->assertSame('api', LabTourText::clean('api', 1, 60, 'Item'));
        $this->assertSame('Kitchen', LabTourText::clean('Kitchen', 1, 120, null));
    }

    public function test_control_characters_become_single_spaces(): void
    {
        $this->assertSame('Bright window', LabTourText::clean("Bright\nwindow\t", 1, 60, 'X'));
        $this->assertSame('a b c', LabTourText::clean("  a\x00\x01b \x7f\r\n c ", 1, 60, 'X'));
        $this->assertSame('Bay window', LabTourText::clean("Bay\u{00A0}\u{2003}window", 1, 60, 'X'));
        $this->assertFalse(LabTourText::safe("a\nb"));
        $this->assertFalse(LabTourText::safe("a\x7fb"));
    }

    public function test_utf16_length_counts_astral_characters_twice(): void
    {
        $this->assertSame(2, LabTourText::utf16Length('😀'));
        $this->assertSame(5, LabTourText::utf16Length('ab😀c'));
        $this->assertSame(1, LabTourText::utf16Length('é'));
        $this->assertSame(1, LabTourText::utf16Length('…'));
        $this->assertSame(0, LabTourText::utf16Length(''));
    }

    public function test_truncation_never_splits_a_surrogate_pair(): void
    {
        $text = str_repeat('a', 59).'😀';
        $clean = LabTourText::clean($text, 1, 60, 'Item');
        $this->assertSame(str_repeat('a', 59).'…', $clean);
        $this->assertSame(60, LabTourText::utf16Length($clean));
        $fits = str_repeat('a', 58).'😀';
        $this->assertSame($fits, LabTourText::clean($fits, 1, 60, 'Item'));
        $emojiAtLimit = str_repeat('a', 57).'😀😀';
        $this->assertSame(str_repeat('a', 57).'😀…', LabTourText::clean($emojiAtLimit, 1, 60, 'Item'));

        // truncate(): UTF-16 units, no ellipsis, never half a pair.
        $this->assertSame(str_repeat('a', 59), LabTourText::truncate($text, 60));
        $this->assertSame($text, LabTourText::truncate($text, 61));
        $this->assertSame('a', LabTourText::truncate('a😀', 2));
        $this->assertSame('a😀', LabTourText::truncate('a😀😀', 4));
        $this->assertSame(str_repeat('é', 200), LabTourText::truncate(str_repeat('é', 201), 200));
        $this->assertSame('', LabTourText::truncate('😀', 1));
        foreach ([$clean, LabTourText::truncate('a😀', 2)] as $out) $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
    }

    public function test_label_boundaries_60_and_61(): void
    {
        $sixty = str_repeat('b', 60);
        $this->assertSame($sixty, LabTourText::clean($sixty, 1, 60, 'Item'));
        $cut = LabTourText::clean(str_repeat('b', 61), 1, 60, 'Item');
        $this->assertSame(str_repeat('b', 59).'…', $cut);
        $this->assertSame(60, LabTourText::utf16Length($cut));
        // The kept prefix is right-trimmed before the ellipsis.
        $this->assertSame(str_repeat('x', 58).'…', LabTourText::clean(str_repeat('x', 58).' yz', 1, 60, 'Item'));
        $this->assertSame(str_repeat('d', 159).'…', LabTourText::clean(str_repeat('d', 400), 0, 160, ''));
    }

    public function test_empty_after_cleaning_uses_the_fallback(): void
    {
        $this->assertSame('Item', LabTourText::clean("\n\t", 1, 60, 'Item'));
        $this->assertSame('Item', LabTourText::clean('', 1, 60, 'Item'));
        $this->assertSame('Item', LabTourText::clean('   ', 1, 60, 'Item'));
        $this->assertNull(LabTourText::clean("\x00", 1, 120, null));
        // A 0..160 description may be empty.
        $this->assertSame('', LabTourText::clean("\n", 0, 160, ''));
        // Not a string, or not UTF-8.
        foreach ([null, 42, 1.5, true, ['a'], (object) []] as $value) $this->assertSame('Item', LabTourText::clean($value, 1, 60, 'Item'));
        $this->assertSame('Item', LabTourText::clean("\xC3\x28", 1, 60, 'Item'));
    }
}
