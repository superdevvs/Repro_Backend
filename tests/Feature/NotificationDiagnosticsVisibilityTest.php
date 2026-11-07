<?php

namespace Tests\Feature;

use App\Events\SmsMessageReceived;
use App\Events\SmsMessageSent;
use App\Http\Resources\Messaging\SmsMessageResource;
use App\Models\AutomationDispatch;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\User;
use App\Services\Messaging\SmsDeliveryFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\Support\IsolatedSecurityTestCase;

class NotificationDiagnosticsVisibilityTest extends IsolatedSecurityTestCase
{
    use RefreshDatabase;

    public function test_sms_diagnostics_require_the_effective_superadmin_and_are_absent_from_shared_broadcasts(): void
    {
        $message = Message::create([
            'channel' => 'SMS', 'direction' => 'OUTBOUND', 'status' => 'FAILED',
            'to_address' => '+12025550123', 'body_text' => 'Reminder fixture',
            'provider_message_id' => 'provider-fixture', 'failed_at' => now(),
            'error_message' => SmsDeliveryFailure::message(['40002']),
            'metadata' => ['telnyx_error_codes' => ['40002'], 'delivery' => ['status' => 'FAILED']],
        ]);
        $before = $message->refresh()->getRawOriginal();
        foreach ([null, 'client', 'photographer', 'sales_rep', 'editing_manager', 'admin', 'superadmin'] as $role) {
            $request = Request::create('/');
            $request->setUserResolver(fn () => $role ? new User(['role' => $role]) : null);
            // An original admin identity must not override an impersonated role.
            $request->attributes->set('original_admin_user', new User(['role' => 'superadmin']));
            $resource = SmsMessageResource::make($message)->resolve($request);
            $this->assertSame('Reminder fixture', $resource['body']);
            if ($role === 'superadmin') {
                $this->assertSame('FAILED', $resource['status']);
                $this->assertSame($message->error_message, $resource['errorMessage']);
                $this->assertNotNull($resource['failedAt']);
                $this->assertSame('provider-fixture', $resource['providerMessageId']);
            } else {
                foreach (['status', 'failedAt', 'errorMessage', 'providerMessageId'] as $field) {
                    $this->assertArrayNotHasKey($field, $resource);
                }
                $this->assertStringNotContainsString('40002', json_encode($resource));
            }
        }
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        request()->setUserResolver(fn () => auth()->user());
        foreach ([new SmsMessageReceived($message), new SmsMessageSent($message)] as $event) {
            $broadcast = $event->broadcastWith()['message'];
            foreach (['status', 'failedAt', 'errorMessage', 'providerMessageId'] as $field) {
                $this->assertArrayNotHasKey($field, $broadcast);
            }
        }
        $this->assertSame($before, $message->fresh()->getRawOriginal());
    }

    public function test_sms_thread_api_omits_delivery_diagnostics_for_an_admin(): void
    {
        $contact = Contact::create(['name' => 'Fixture', 'phone' => '+12025550123']);
        $thread = MessageThread::create(['channel' => 'SMS', 'contact_id' => $contact->id, 'status' => 'OPEN']);
        Message::create(['channel' => 'SMS', 'direction' => 'OUTBOUND', 'status' => 'FAILED',
            'thread_id' => $thread->id, 'to_address' => $contact->phone, 'body_text' => 'Reminder fixture',
            'error_message' => SmsDeliveryFailure::message(['40002']), 'failed_at' => now()]);
        Sanctum::actingAs(User::factory()->admin()->create());
        $response = $this->getJson('/api/messaging/sms/threads/'.$thread->id)->assertOk();
        $response->assertJsonPath('messages.0.body', 'Reminder fixture');
        foreach (['status', 'failedAt', 'errorMessage', 'providerMessageId'] as $field) {
            $response->assertJsonMissingPath('messages.0.'.$field);
        }
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->getJson('/api/messaging/sms/threads/'.$thread->id)->assertOk()->assertJsonPath('messages.0.status', 'FAILED');
    }

    public function test_admin_can_edit_workflows_but_cannot_read_operational_failures(): void
    {
        $rule = AutomationRule::create(['name' => 'Visibility fixture', 'trigger_type' => 'CUSTOM_EVENT', 'scope' => 'SYSTEM', 'is_active' => true]);
        AutomationRun::create(['automation_rule_id' => $rule->id, 'status' => 'failed', 'error_message' => 'private-canary']);
        AutomationDispatch::create(['automation_rule_id' => $rule->id, 'trigger_type' => 'CUSTOM_EVENT', 'period_key' => 'visibility',
            'scheduled_for' => now(), 'command' => 'fixture', 'status' => 'failed', 'error_message' => 'private-canary']);
        Sanctum::actingAs(User::factory()->admin()->create());
        $list = $this->getJson('/api/messaging/automations?trigger_type=CUSTOM_EVENT')->assertOk();
        $detail = $this->getJson('/api/messaging/automations/'.$rule->id)->assertOk();
        $toggle = $this->postJson('/api/messaging/automations/'.$rule->id.'/toggle')->assertOk();
        foreach ([$list->json('0'), $detail->json(), $toggle->json()] as $data) {
            $this->assertSame([], $data['recent_runs']);
            $this->assertArrayNotHasKey('latest_dispatch', $data);
            $this->assertStringNotContainsString('private-canary', json_encode($data));
        }
        $this->getJson('/api/messaging/automations/'.$rule->id.'/runs')->assertForbidden();

        $admin = auth()->user();
        $superadmin = User::factory()->superAdmin()->create();
        Sanctum::actingAs($superadmin);
        $this->getJson('/api/messaging/automations/'.$rule->id, ['X-Impersonate-User-Id' => (string) $admin->id])
            ->assertOk()->assertJsonPath('recent_runs', [])->assertJsonMissingPath('latest_dispatch');
        Sanctum::actingAs($superadmin);
        $this->getJson('/api/messaging/automations/'.$rule->id.'/runs', ['X-Impersonate-User-Id' => (string) $admin->id])
            ->assertForbidden();
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->getJson('/api/messaging/automations/'.$rule->id)->assertOk()
            ->assertJsonPath('recent_runs.0.status', 'failed')->assertJsonPath('latest_dispatch.status', 'failed');
        $this->getJson('/api/messaging/automations/'.$rule->id.'/runs')->assertOk()->assertJsonPath('data.0.status', 'failed');
    }

    public function test_overview_hides_sms_diagnostics_and_failure_counts_from_the_effective_admin(): void
    {
        $message = Message::create([
            'channel' => 'SMS', 'direction' => 'OUTBOUND', 'status' => 'FAILED',
            'to_address' => '+12025550123', 'body_text' => 'Overview fixture',
            'provider_message_id' => 'provider-overview-fixture', 'failed_at' => now(),
            'error_message' => SmsDeliveryFailure::message(['40002']),
            'metadata' => ['telnyx_error_codes' => ['40002'], 'delivery' => ['status' => 'FAILED']],
        ]);
        $before = $message->fresh()->getRawOriginal();
        $admin = User::factory()->admin()->create();
        $superadmin = User::factory()->superAdmin()->create();
        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/messaging/overview')->assertOk();
        $response->assertJsonPath('recent_activity.0.body_text', 'Overview fixture');
        foreach (['total_failed_today', 'sms_failed_today'] as $field) {
            $response->assertJsonMissingPath($field);
        }
        foreach (['status', 'failed_at', 'error_message', 'provider_message_id', 'metadata'] as $field) {
            $response->assertJsonMissingPath('recent_activity.0.'.$field);
        }
        Sanctum::actingAs($superadmin);
        $this->getJson('/api/messaging/overview')->assertOk()
            ->assertJsonPath('sms_failed_today', 1)
            ->assertJsonPath('recent_activity.0.status', 'FAILED')
            ->assertJsonPath('recent_activity.0.metadata.telnyx_error_codes.0', '40002');
        Sanctum::actingAs($superadmin);
        $this->getJson('/api/messaging/overview', ['X-Impersonate-User-Id' => (string) $admin->id])->assertOk()
            ->assertJsonMissingPath('sms_failed_today')->assertJsonMissingPath('recent_activity.0.metadata');
        $this->assertSame($before, $message->fresh()->getRawOriginal());
    }
}
