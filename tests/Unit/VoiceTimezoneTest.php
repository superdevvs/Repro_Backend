<?php

namespace Tests\Unit;

use App\Services\Voice\VoiceTimezone;
use PHPUnit\Framework\TestCase;

class VoiceTimezoneTest extends TestCase
{
    public function test_known_aliases_resolve_without_loading_or_constructing_the_alias_timezone(): void
    {
        // Simulate a production host whose available zoneinfo contains only
        // canonical files. The helper must not need DateTimeZone($alias).
        $available = ['Asia/Kolkata', 'Asia/Kathmandu', 'Europe/Kyiv', 'America/New_York'];
        foreach (['Asia/Calcutta', 'Asia/Katmandu', 'Europe/Kiev', 'US/Eastern'] as $index => $alias) {
            $this->assertNotContains($alias, $available);
            $this->assertSame($available[$index], VoiceTimezone::normalize($alias));
        }
        $this->assertSame('UTC', VoiceTimezone::normalize('UTC'));
        $this->assertSame('America/New_York', VoiceTimezone::normalize('America/New_York'));
    }

    public function test_unknown_or_malformed_values_remain_invalid_instead_of_falling_back(): void
    {
        foreach (['Invalid/Zone', 'asia/calcutta', ' Asia/Calcutta ', '+05:30', '', null, ['Asia/Calcutta']] as $value) {
            $this->assertSame($value, VoiceTimezone::normalize($value));
        }
        $this->assertSame(['quiet_hours' => 'invalid'], VoiceTimezone::normalizeWindows(['quiet_hours' => 'invalid']));
    }
}
