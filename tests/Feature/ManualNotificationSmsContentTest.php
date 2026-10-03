<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\ManualNotificationService;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ManualNotificationSmsContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_manual_types_use_separate_editable_sms_templates_and_preserve_email_copy(): void
    {
        $client = User::factory()->create(['name' => 'Casey Client', 'phonenumber' => '+12025550101']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id, 'address' => '104 Test Lane',
            'property_details' => ['accessContactName' => 'Lauren', 'accessContactPhone' => '4435045965'],
        ]);
        $service = app(ManualNotificationService::class);

        foreach (ManualNotificationService::TYPES as $type => $slug) {
            if (! in_array('sms', ManualNotificationService::CATALOGUE[$type]['channels'], true)) {
                continue;
            }
            $email = MessageTemplate::create([
                'slug' => $slug, 'name' => $slug, 'channel' => 'EMAIL', 'scope' => 'SYSTEM',
                'is_system' => true, 'is_active' => true, 'subject' => 'Email subject',
                'body_html' => '<p>Detailed email copy with Services, Access and Notes.</p>',
                'body_text' => 'Detailed email copy with Services, Access and Notes.',
            ]);
            $emailBefore = $email->fresh()->getAttributes();
            $preview = $service->preview($shoot, $type, ManualNotificationService::CATALOGUE[$type]['recipients'][0], 'sms');
            $sms = $service->resolveTemplate($type, 'sms');

            $this->assertSame($slug.'-sms', $sms->slug);
            $this->assertSame('SMS', $sms->channel);
            $this->assertStringContainsString('104 Test Lane', $preview['body_text'], $type);
            $this->assertStringNotContainsString('Detailed email copy', $preview['body_text'], $type);
            $this->assertStringNotContainsString('google.com/maps', $preview['body_text'], $type);
            $this->assertStringNotContainsString('{{', $preview['body_text'], $type);
            $this->assertSame($emailBefore, $email->fresh()->getAttributes(), $type);
        }
    }

    public function test_sms_preview_matches_send_and_resolves_shoot_contact_and_details_link(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['name' => 'Casey Client', 'phonenumber' => '+12025550101']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id, 'address' => '104 Test Lane',
            'property_details' => ['accessContactName' => 'Lauren', 'accessContactPhone' => '4435045965'],
        ]);
        $captured = null;
        $this->mock(MessagingService::class, function ($mock) use (&$captured): void {
            $mock->shouldNotReceive('sendEmail');
            $mock->shouldReceive('sendSms')->once()->withArgs(function (array $payload) use (&$captured): bool {
                $captured = $payload;

                return true;
            })->andReturn(Message::make(['channel' => 'SMS', 'status' => 'SENT']));
        });

        $preview = $this->actingAs($admin, 'sanctum')->postJson('/api/messaging/notifications/manual-preview', [
            'shoot_id' => $shoot->id, 'type' => 'shoot_scheduled', 'recipient_type' => 'client', 'channel' => 'sms',
        ])->assertOk()->json();
        app(ManualNotificationService::class)->send($shoot, 'shoot_scheduled', 'client', 'sms', $admin);

        $this->assertSame($preview['body_text'], $captured['body_text']);
        $this->assertStringContainsString('Lauren', $preview['body_text']);
        $this->assertStringContainsString('4435045965', $preview['body_text']);
        $this->assertStringContainsString('/shoots/'.$shoot->id, $preview['body_text']);
        $this->assertSame([], $preview['missing_variables']);
    }

    public function test_saved_custom_sms_is_preserved_and_disabled_sms_cannot_fall_back_to_email(): void
    {
        $service = app(ManualNotificationService::class);
        $sms = $service->resolveTemplate('shoot_scheduled', 'sms');
        $sms->update(['body_text' => 'My saved shoot text', 'variables_json' => []]);

        $this->assertSame('My saved shoot text', $service->resolveTemplate('shoot_scheduled', 'sms')->body_text);
        $sms->update(['is_active' => false]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The selected SMS template is disabled.');
        $service->resolveTemplate('shoot_scheduled', 'sms');
    }

    public function test_legacy_sms_template_copy_and_disabled_state_are_respected(): void
    {
        $legacy = MessageTemplate::create([
            'slug' => 'shoot-scheduled', 'name' => 'Saved SMS', 'channel' => 'SMS', 'scope' => 'SYSTEM',
            'is_active' => true, 'body_text' => 'Operator-authored SMS',
        ]);
        $service = app(ManualNotificationService::class);
        $this->assertSame($legacy->id, $service->resolveTemplate('shoot_scheduled', 'sms')->id);
        $this->assertSame('Operator-authored SMS', $legacy->fresh()->body_text);
        $legacy->update(['is_active' => false]);
        $this->expectException(RuntimeException::class);
        $service->resolveTemplate('shoot_scheduled', 'sms');
    }
}
