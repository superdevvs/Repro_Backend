<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\AutomationRule;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\PropertyAccessReminderEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PropertyAccessReminderEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_access_choices_and_legacy_keys_share_one_missing_details_rule(): void
    {
        $eligibility = app(PropertyAccessReminderEligibility::class);
        foreach ([
            [[], true],
            [['presenceOption' => 'self'], false],
            [['presence_option' => ' SELF '], false],
            [['presenceOption' => 'other', 'accessContactName' => 'Alex'], true],
            [['presenceOption' => 'other', 'accessContactName' => 'Alex', 'accessContactPhone' => '   '], true],
            [['presenceOption' => 'other', 'accessContactName' => 'Alex', 'accessContactPhone' => '2025550100'], false],
            [['presence_option' => 'other', 'access_contact_name' => 'Alex', 'access_contact_phone' => '2025550100'], false],
            [['presenceOption' => 'lockbox', 'lockboxLocation' => 'Front gate'], true],
            [['presenceOption' => 'lockbox', 'lockboxCode' => '1234'], false],
            [['lockbox_code' => '1234'], false],
            [['presenceOption' => 'other', 'lockboxCode' => 'stale-code'], true],
        ] as [$details, $expected]) {
            $this->assertSame($expected, $eligibility->missing($details), json_encode($details));
        }
    }

    public function test_queued_access_email_and_sms_are_cancelled_after_self_access_is_saved(): void
    {
        Mail::fake();
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id, 'property_details' => [],
            'status' => Shoot::STATUS_SCHEDULED, 'workflow_status' => Shoot::WORKFLOW_BOOKED,
            'scheduled_at' => now()->addDays(2), 'scheduled_date' => now()->addDays(2)->toDateString(),
        ]);
        $messages = collect(['EMAIL', 'SMS'])->map(fn ($channel) => Message::create([
            'channel' => $channel, 'direction' => 'OUTBOUND', 'send_source' => 'AUTOMATION',
            'provider' => $channel === 'EMAIL' ? 'LOCAL_SMTP' : 'TELNYX', 'status' => 'SCHEDULED',
            'related_shoot_id' => $shoot->id, 'related_account_id' => $client->id,
            'to_address' => $channel === 'EMAIL' ? $client->email : '+12025550111',
            'tags_json' => ['PROPERTY_CONTACT_REMINDER:'.$shoot->id],
        ]));
        $shoot->update(['property_details' => ['presenceOption' => 'self']]);

        foreach ($messages as $message) {
            $result = $message->channel === 'EMAIL'
                ? app(MessagingService::class)->dispatchStoredEmailMessage($message)
                : app(MessagingService::class)->dispatchStoredSmsMessage($message);
            $this->assertSame('CANCELLED', $result->status);
            $this->assertSame('Property access reminder is no longer current.', $result->error_message);
        }
        Mail::assertNothingSent();
    }

    public function test_saved_access_does_not_cancel_ordinary_shoot_reminders(): void
    {
        $shoot = Shoot::factory()->create(['property_details' => ['presenceOption' => 'self']]);
        $message = new Message([
            'send_source' => 'AUTOMATION', 'related_shoot_id' => $shoot->id,
            'metadata' => ['notification' => ['trigger' => 'SHOOT_REMINDER']],
            'tags_json' => ['SHOOT_REMINDER:'.$shoot->id],
        ]);
        $this->assertFalse(app(PropertyAccessReminderEligibility::class)->storedReminderIsStale($message));
    }

    public function test_delayed_workflow_rechecks_access_instead_of_using_its_old_missing_details_context(): void
    {
        Mail::fake();
        $shoot = Shoot::factory()->create([
            'property_details' => [], 'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::WORKFLOW_BOOKED, 'scheduled_at' => now()->addDays(2),
        ]);
        $context = app(AutomationService::class)->buildShootContext($shoot);
        $shoot->update(['property_details' => ['presenceOption' => 'self']]);
        $rule = AutomationRule::create([
            'name' => 'Access confirmation', 'trigger_type' => 'PROPERTY_CONTACT_REMINDER',
            'scope' => 'SYSTEM', 'is_active' => true, 'editor_mode' => 'visual', 'engine_version' => 2,
            'workflow_definition_json' => [
                'nodes' => [
                    ['id' => 'trigger', 'type' => 'trigger.event', 'config' => ['triggerType' => 'PROPERTY_CONTACT_REMINDER']],
                    ['id' => 'send', 'type' => 'action.email', 'config' => [
                        'subject' => 'Confirm access', 'bodyText' => 'Please confirm access.',
                        'recipientMode' => 'roles', 'recipientRoles' => ['client'],
                    ]],
                    ['id' => 'end', 'type' => 'end', 'config' => []],
                ],
                'edges' => [['id' => 'one', 'source' => 'trigger', 'target' => 'send'], ['id' => 'two', 'source' => 'send', 'target' => 'end']],
            ],
        ]);
        $run = app(AutomationWorkflowExecutor::class)->executeAutomation($rule, $context);
        $this->assertSame('completed', $run->status, $run->error_message ?? '');
        $this->assertSame(0, Message::count());
        Mail::assertNothingSent();
    }
}
