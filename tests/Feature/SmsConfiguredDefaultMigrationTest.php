<?php

namespace Tests\Feature;

use App\Models\SmsNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsConfiguredDefaultMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_lines_are_preserved_when_configured_main_is_registered_once(): void
    {
        foreach (['+12025550101', '+12025550102', '+12025550103', '+12025550104'] as $phone) {
            SmsNumber::create(['phone_number' => $phone, 'provider' => 'TELNYX', 'is_default' => false]);
        }
        $before = SmsNumber::orderBy('id')->get()->toArray();
        config(['services.telnyx.from_number' => '(202) 555-0199', 'services.telnyx.phone_number_id' => 'configured-number-id',
            'services.telnyx.messaging_profile_id' => 'configured-profile-id', 'services.telnyx.default_label' => 'Main']);
        $migration = $this->migration();
        $migration->up();
        $migration->up();
        $this->assertDatabaseCount('sms_numbers', 5);
        $this->assertSame($before, SmsNumber::where('is_default', false)->orderBy('id')->get()->toArray());
        $default = SmsNumber::where('is_default', true)->sole();
        $this->assertSame('+12025550199', $default->phone_number);
        $this->assertSame('configured-number-id', $default->telnyx_phone_number_id);
        $this->assertSame('configured-profile-id', $default->messaging_profile_id);
        $migration->down();
        $this->assertDatabaseCount('sms_numbers', 5);
    }

    public function test_normalized_matching_line_is_reused_and_existing_profile_is_preserved(): void
    {
        $matching = SmsNumber::create(['phone_number' => '+1 (202) 555-0199', 'provider' => 'TELNYX',
            'label' => 'Existing label', 'is_default' => false, 'messaging_profile_id' => 'existing-profile']);
        config(['services.telnyx.from_number' => '+12025550199', 'services.telnyx.phone_number_id' => 'configured-number-id',
            'services.telnyx.messaging_profile_id' => 'global-profile']);
        $this->migration()->up();
        $this->assertDatabaseCount('sms_numbers', 1);
        $default = SmsNumber::where('is_default', true)->sole();
        $this->assertSame($matching->id, $default->id);
        $this->assertSame('existing-profile', $default->messaging_profile_id);
        $this->assertSame('configured-number-id', $default->telnyx_phone_number_id);
        $this->assertSame('Existing label', $default->label);
    }

    public function test_an_existing_default_is_never_changed_or_duplicated(): void
    {
        SmsNumber::create(['phone_number' => '+12025550101', 'provider' => 'TELNYX', 'is_default' => true]);
        SmsNumber::create(['phone_number' => '+12025550102', 'provider' => 'TELNYX', 'is_default' => false]);
        $before = SmsNumber::orderBy('id')->get()->toArray();
        config(['services.telnyx.from_number' => '+12025550199']);
        $this->migration()->up();
        $this->assertSame($before, SmsNumber::orderBy('id')->get()->toArray());
    }

    public function test_invalid_config_does_not_create_a_sender_or_select_an_unrelated_line(): void
    {
        SmsNumber::create(['phone_number' => '+12025550101', 'provider' => 'TELNYX', 'is_default' => false]);
        config(['services.telnyx.from_number' => 'invalid-main-number']);
        $this->migration()->up();
        $this->assertDatabaseCount('sms_numbers', 1);
        $this->assertSame(0, SmsNumber::where('is_default', true)->count());
    }

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        return require base_path('database/migrations/2026_10_17_140000_register_configured_main_sms_sender.php');
    }
}
