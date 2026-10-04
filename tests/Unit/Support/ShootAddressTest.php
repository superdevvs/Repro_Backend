<?php

namespace Tests\Unit\Support;

use App\Models\Shoot;
use App\Support\ShootAddress;
use PHPUnit\Framework\TestCase;

class ShootAddressTest extends TestCase
{
    public function test_complete_unit_designators_and_boundaries(): void
    {
        $this->assertTrue(ShootAddress::streetContainsUnit('10 Main St Apartment 103', 'Apartment 103'));
        $this->assertFalse(ShootAddress::streetContainsUnit('10 Main St Apt 1-A', '1'));
        $this->assertSame('# 12', ShootAddress::formatUnitLabel('# 12'));
        $this->assertSame('Unit Unity', ShootAddress::formatUnitLabel('Unity'));
    }

    public function test_appends_unit_when_missing_from_street(): void
    {
        $this->assertSame(
            '15 Rainflower Path, Unit 103',
            ShootAddress::streetWithAptSuite('15 Rainflower Path', ['aptSuite' => '103'])
        );
    }

    public function test_reads_snake_case_apt_suite(): void
    {
        $this->assertSame(
            '900 N Stafford Street, Unit 1819',
            ShootAddress::streetWithAptSuite('900 N Stafford Street', ['apt_suite' => '1819'])
        );
    }

    public function test_does_not_double_hash_unit(): void
    {
        $this->assertSame(
            '6636 Washington Blvd #93',
            ShootAddress::streetWithAptSuite('6636 Washington Blvd #93', ['aptSuite' => '93'])
        );
    }

    public function test_does_not_double_unit_word(): void
    {
        $this->assertSame(
            '12800 Middlebrook Road Unit 206',
            ShootAddress::streetWithAptSuite('12800 Middlebrook Road Unit 206', ['aptSuite' => '206'])
        );
    }

    public function test_preserves_prelabeled_apt_value(): void
    {
        $this->assertSame(
            '10 Monroe St, Unit 4',
            ShootAddress::streetWithAptSuite('10 Monroe St', ['aptSuite' => 'Unit 4'])
        );
    }

    public function test_format_full_address_includes_unit(): void
    {
        $shoot = new Shoot([
            'address' => '15 Rainflower Path',
            'city' => 'Sparks Glencoe',
            'state' => 'MD',
            'zip' => '21152',
            'property_details' => ['aptSuite' => '103'],
        ]);

        $this->assertSame(
            '15 Rainflower Path, Unit 103, Sparks Glencoe, MD 21152',
            ShootAddress::formatFullAddress($shoot)
        );
    }
}
