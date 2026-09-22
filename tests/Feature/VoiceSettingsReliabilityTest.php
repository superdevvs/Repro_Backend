<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\TelnyxAi\VoiceSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceSettingsReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_updates_preserve_other_rules_and_quiet_hour_boundaries(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $settings = app(VoiceSettingsService::class);
        $before = $settings->all();
        $this->patchJson('/api/voice/settings', ['quiet_hours' => ['enabled' => false], 'automation_toggles' => ['shoot_reminder' => true]])
            ->assertOk()
            ->assertJsonPath('quiet_hours.start', $before['quiet_hours']['start'])
            ->assertJsonPath('quiet_hours.timezone', $before['quiet_hours']['timezone'])
            ->assertJsonPath('automation_toggles.missed_call_callback', true)
            ->assertJsonPath('automation_toggles.shoot_reminder', true);
    }

    public function test_closed_days_and_removed_holidays_do_not_reappear_from_defaults(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $this->patchJson('/api/voice/settings', ['holidays' => [['date' => '2026-12-25', 'label' => 'Closed']]])->assertOk();
        $this->patchJson('/api/voice/settings', ['business_hours' => ['weekly' => ['monday' => []]], 'holidays' => []])
            ->assertOk()->assertJsonPath('holidays', [])->assertJsonPath('business_hours.weekly.monday', [])
            ->assertJsonPath('business_hours.weekly.tuesday', [['09:00', '18:00']]);
        $this->getJson('/api/voice/settings')->assertOk()->assertJsonPath('business_hours.weekly.monday', []);
    }

    public function test_invalid_timezones_and_malformed_windows_are_rejected_without_changing_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $before = app(VoiceSettingsService::class)->all();
        $this->patchJson('/api/voice/settings', ['quiet_hours' => ['timezone' => 'Not/AZone']])->assertUnprocessable()->assertJsonValidationErrors('quiet_hours.timezone');
        $this->patchJson('/api/voice/settings', ['business_hours' => ['timezone' => 'Not/AZone']])->assertUnprocessable()->assertJsonValidationErrors('business_hours.timezone');
        $this->patchJson('/api/voice/settings', ['business_hours' => ['weekly' => ['monday' => [['29:00', '18:00']]]]])->assertUnprocessable();
        $this->assertSame($before, app(VoiceSettingsService::class)->all());
    }

    public function test_timezone_alias_from_application_defaults_can_be_saved_and_read_back(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $runtimeTimezone = config('app.timezone');
        try {
            config(['app.timezone' => 'Asia/Calcutta']);
            $defaults = app(VoiceSettingsService::class)->all();
        } finally {
            // Exercise the legacy voice default without leaving Laravel's request
            // lifecycle on an OS timezone alias that this host may not install.
            config(['app.timezone' => $runtimeTimezone]);
        }
        $this->assertSame('Asia/Kolkata', $defaults['business_hours']['timezone']);
        $this->assertSame('Asia/Kolkata', $defaults['quiet_hours']['timezone']);
        $this->patchJson('/api/voice/settings', [
            'business_hours' => ['timezone' => 'Asia/Calcutta'], 'quiet_hours' => ['timezone' => 'Asia/Calcutta'],
        ])->assertOk()
            ->assertJsonPath('business_hours.timezone', 'Asia/Kolkata')
            ->assertJsonPath('quiet_hours.timezone', 'Asia/Kolkata');
        $this->getJson('/api/voice/settings')->assertOk()
            ->assertJsonPath('business_hours.timezone', 'Asia/Kolkata')
            ->assertJsonPath('quiet_hours.timezone', 'Asia/Kolkata');
        $stored = json_decode(Setting::query()->where('key', VoiceSettingsService::SETTINGS_KEY)->value('value'), true);
        $this->assertSame('Asia/Kolkata', $stored['business_hours']['timezone']);
        $this->assertSame('Asia/Kolkata', $stored['quiet_hours']['timezone']);
    }

    public function test_saved_legacy_timezone_aliases_are_normalized_for_scheduling_without_rewriting_on_read(): void
    {
        $raw = json_encode(['business_hours' => ['timezone' => 'Asia/Calcutta'], 'quiet_hours' => ['timezone' => 'US/Eastern']]);
        Setting::query()->create(['key' => VoiceSettingsService::SETTINGS_KEY, 'value' => $raw, 'type' => 'json']);
        $settings = app(VoiceSettingsService::class)->all();
        $this->assertSame('Asia/Kolkata', $settings['business_hours']['timezone']);
        $this->assertSame('America/New_York', $settings['quiet_hours']['timezone']);
        $this->assertSame($raw, Setting::query()->where('key', VoiceSettingsService::SETTINGS_KEY)->value('value'));
    }
}
