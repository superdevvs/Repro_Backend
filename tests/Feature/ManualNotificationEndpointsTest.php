<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Endpoint coverage for the manual shoot-notification routes mounted on
 * MessageTemplateController (Req 12.1, 12.5, 12.6, 12.7).
 *
 * Verifies that:
 *  - manual-send routes through MessagingService::sendEmail / sendSms with the right payload
 *  - manual-preview returns rendered subject/body without dispatching
 *  - admin role middleware on the messaging template route group still applies
 */
class ManualNotificationEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function template(string $slug, array $overrides = []): MessageTemplate
    {
        return MessageTemplate::create(array_merge([
            'channel' => 'EMAIL',
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'description' => null,
            'category' => 'GENERAL',
            'subject' => 'Subject for ' . $slug,
            'body_html' => '<p>Hello {{recipient_first_name}}</p>',
            'body_text' => 'Hello {{recipient_first_name}}',
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
        ], $overrides));
    }

    public function test_manual_send_endpoint_dispatches_through_messaging_service(): void
    {
        $this->template('shoot-scheduled');
        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create([
            'email' => 'client@example.com',
            'name' => 'Casey Client',
        ]);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);

        $captured = null;
        $this->mock(MessagingService::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('sendEmail')
                ->once()
                ->withArgs(function (array $payload) use (&$captured): bool {
                    $captured = $payload;

                    return true;
                })
                ->andReturn(Message::make([
                    'id' => 4242,
                    'channel' => 'EMAIL',
                    'to_address' => 'client@example.com',
                    'status' => 'SENT',
                ]));
        });

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/messaging/notifications/manual-send', [
                'shoot_id'       => $shoot->id,
                'type'           => 'shoot_scheduled',
                'recipient_type' => 'client',
                'channel'        => 'email',
            ]);

        $response->assertOk();
        $response->assertJson([
            'status'         => 'sent',
            'channel'        => 'email',
            'recipient_type' => 'client',
        ]);

        $this->assertSame('client@example.com', $captured['to']);
        $this->assertSame('MANUAL', $captured['send_source']);
        $this->assertSame($shoot->id, $captured['related_shoot_id']);
    }

    public function test_manual_send_endpoint_routes_to_sms_for_photographer(): void
    {
        $this->template('shoot-scheduled', ['channel' => 'SMS', 'body_html' => null]);
        $admin = User::factory()->create(['role' => 'admin']);
        $photographer = User::factory()->create(['phonenumber' => '+15551234567']);
        $shoot = Shoot::factory()->create(['photographer_id' => $photographer->id]);

        $captured = null;
        $this->mock(MessagingService::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('sendSms')
                ->once()
                ->withArgs(function (array $payload) use (&$captured): bool {
                    $captured = $payload;

                    return true;
                })
                ->andReturn(Message::make([
                    'id' => 1,
                    'channel' => 'SMS',
                    'to_address' => '+15551234567',
                    'status' => 'SENT',
                ]));
        });

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/messaging/notifications/manual-send', [
                'shoot_id'       => $shoot->id,
                'type'           => 'shoot_scheduled',
                'recipient_type' => 'photographer',
                'channel'        => 'sms',
            ]);

        $response->assertOk();
        $this->assertSame('+15551234567', $captured['to']);
        $this->assertSame('photographer', $captured['contact_type']);
    }

    public function test_manual_preview_endpoint_returns_rendered_body_without_dispatching(): void
    {
        // Avoid shadowing from any seeded shoot-scheduled template.
        MessageTemplate::where('slug', 'shoot-scheduled')->delete();
        $this->template('shoot-scheduled', [
            'subject' => 'Hi {{recipient_first_name}}',
            'body_html' => '<p>Welcome aboard, {{recipient_first_name}}.</p>',
            'body_text' => 'Welcome aboard, {{recipient_first_name}}.',
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create([
            'email' => 'client@example.com',
            'name' => 'Casey Client',
        ]);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);

        // Strict mock: any call to send* is an unexpected dispatch and would fail the test.
        $this->mock(MessagingService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('sendEmail');
            $mock->shouldNotReceive('sendSms');
        });

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/messaging/notifications/manual-preview', [
                'shoot_id'       => $shoot->id,
                'type'           => 'shoot_scheduled',
                'recipient_type' => 'client',
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['subject', 'body_html', 'body_text', 'missing_variables']);
        $body = $response->json();
        $this->assertSame('Hi Casey', $body['subject']);
        $this->assertStringContainsString('Welcome aboard, Casey', (string) $body['body_html']);
        $this->assertStringContainsString('Welcome aboard, Casey', (string) $body['body_text']);

        $this->assertSame(0, Message::count());
    }

    public function test_manual_send_rejects_unknown_type_with_validation_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/messaging/notifications/manual-send', [
                'shoot_id'       => $shoot->id,
                'type'           => 'not_a_type',
                'recipient_type' => 'client',
                'channel'        => 'email',
            ]);

        $response->assertStatus(422);
    }

    public function test_manual_send_requires_admin_role(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create();

        $response = $this->actingAs($client, 'sanctum')
            ->postJson('/api/messaging/notifications/manual-send', [
                'shoot_id'       => $shoot->id,
                'type'           => 'shoot_scheduled',
                'recipient_type' => 'client',
                'channel'        => 'email',
            ]);

        $response->assertForbidden();
    }

    public function test_manual_preview_requires_admin_role(): void
    {
        // Req 6.1 / 6.2 — manual-preview, like manual-send, is admin/superadmin only.
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create();

        $response = $this->actingAs($client, 'sanctum')
            ->postJson('/api/messaging/notifications/manual-preview', [
                'shoot_id'       => $shoot->id,
                'type'           => 'shoot_scheduled',
                'recipient_type' => 'client',
            ]);

        $response->assertForbidden();
    }
}
