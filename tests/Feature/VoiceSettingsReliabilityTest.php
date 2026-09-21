<?php

namespace Tests\Feature;

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
        config(['app.timezone' => 'Asia/Calcutta']);
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $defaults = app(VoiceSettingsService::class)->all();
        $this->assertSame('Asia/Calcutta', $defaults['business_hours']['timezone']);
        $this->assertSame('Asia/Calcutta', $defaults['quiet_hours']['timezone']);
        $this->patchJson('/api/voice/settings', [
            'business_hours' => $defaults['business_hours'], 'quiet_hours' => $defaults['quiet_hours'],
        ])->assertOk()
            ->assertJsonPath('business_hours.timezone', 'Asia/Calcutta')
            ->assertJsonPath('quiet_hours.timezone', 'Asia/Calcutta');
        $this->getJson('/api/voice/settings')->assertOk()
            ->assertJsonPath('business_hours.timezone', 'Asia/Calcutta')
            ->assertJsonPath('quiet_hours.timezone', 'Asia/Calcutta');
    }
}
