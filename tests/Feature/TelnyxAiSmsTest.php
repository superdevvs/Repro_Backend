<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MessageThread;
use App\Models\Setting;
use App\Models\SmsNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TelnyxAiSmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sms_settings_save_ai_agent_controls_and_sender_override(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->postJson('/api/messaging/settings/sms', [
            'numbers' => [[
                'phone_number' => '+18883426998',
                'label' => 'Main Telnyx',
                'provider' => 'TELNYX',
                'is_default' => true,
                'sms_ai_enabled' => false,
            ]],
            'ai' => [
                'static_replies' => [
                    'stop' => 'Custom stop reply',
                    'start' => 'Custom start reply',
                    'help' => 'Custom help reply',
                ],
                'allowed_tools' => ['get_shoot_details', 'get_availability'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('numbers.0.sms_ai_enabled', false)
            ->assertJsonPath('ai.static_replies.stop', 'Custom stop reply')
            ->assertJsonPath('ai.allowed_tools.1', 'get_availability');

        $this->assertDatabaseHas('sms_numbers', [
            'phone_number' => '+18883426998',
            'sms_ai_enabled' => false,
        ]);

        $setting = Setting::query()->where('key', 'messaging.telnyx_ai_sms')->first();
        $this->assertNotNull($setting);
        $this->assertSame('json', $setting->type);
    }

    public function test_contact_ai_toggle_updates_sms_ai_enabled(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $contact = Contact::create([
            'name' => 'Client Contact',
            'phone' => '+12025550188',
            'type' => 'client',
            'sms_ai_enabled' => false,
        ]);

        $response = $this->putJson("/api/messaging/contacts/{$contact->id}", [
            'sms_ai_enabled' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('contact.smsAiEnabled', true);

        $this->assertTrue((bool) $contact->refresh()->sms_ai_enabled);
    }

    public function test_resume_ai_endpoint_clears_thread_pause(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $contact = Contact::create([
            'name' => 'Client Contact',
            'phone' => '+12025550188',
            'type' => 'client',
        ]);

        $thread = MessageThread::create([
            'channel' => 'SMS',
            'contact_id' => $contact->id,
            'last_message_at' => now(),
            'ai_paused_until' => now()->addHours(2),
        ]);

        $response = $this->postJson("/api/messaging/sms/threads/{$thread->id}/resume-ai");

        $response->assertOk()
            ->assertJsonPath('thread.aiPausedUntil', null);

        $this->assertNull($thread->refresh()->ai_paused_until);
    }
}
