<?php

namespace Tests\Unit;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Schedule\ScheduleDateScopeService;
use App\Services\Schedule\ScheduleInstantResolver;
use App\Support\Timezone;
use Carbon\Carbon;
use Tests\TestCase;

class SchedulingTimezoneAliasTest extends TestCase
{
    public function test_exact_iana_aliases_normalize_without_reinterpreting_supplied_timestamps(): void
    {
        $input = ['timezone' => 'Asia/Calcutta', 'scheduled_at' => '2026-10-03T04:30:00.000Z',
            'service_items' => [['scheduled_at' => '2026-10-03T12:45:00.000Z']],
            'complimentary_service_options' => ['timezone' => 'US/Eastern', 'scheduled_at' => '2026-11-01T01:30:00-05:00']];
        $expected = $input;
        $expected['timezone'] = 'Asia/Kolkata';
        $expected['complimentary_service_options']['timezone'] = 'America/New_York';
        $this->assertSame($expected, Timezone::scheduleInput($input));
        foreach (['Invalid/Zone', 'asia/calcutta', '+05:30', null, ['Asia/Calcutta']] as $invalid) {
            $this->assertSame($invalid, Timezone::canonical($invalid));
        }
    }

    public function test_legacy_alias_reads_keep_the_stored_value_and_use_the_real_zone(): void
    {
        $shoot = new Shoot(['scheduled_at' => '2026-10-03 18:45:00', 'timezone' => 'Asia/Calcutta']);
        $shoot->setRelation('photographer', new User(['timezone' => 'Asia/Calcutta']));
        $instant = app(ScheduleInstantResolver::class)->forShoot($shoot);
        $this->assertSame('2026-10-04 00:15:00', $instant->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Kolkata', $instant->timezoneName);
        $this->assertSame('Asia/Calcutta', $shoot->timezone);
        $this->assertSame('2026-10-04', app(ScheduleDateScopeService::class)->localDateForScheduledAt(
            Carbon::parse('2026-10-03T18:45:00Z'), 'Asia/Calcutta'
        ));
    }
}
