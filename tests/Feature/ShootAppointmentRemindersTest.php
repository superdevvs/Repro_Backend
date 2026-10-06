<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Message;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\ShootAppointmentReminderSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShootAppointmentRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        AutomationRule::query()->update(['is_active' => false]);
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->andReturnUsing(fn (array $payload) => Message::create([
            'channel' => 'EMAIL', 'direction' => 'OUTBOUND', 'provider' => 'FAKE', 'status' => 'SENT',
            'send_source' => 'AUTOMATION', 'to_address' => $payload['to'], 'subject' => $payload['subject'],
            'body_text' => $payload['body_text'], 'tags_json' => $payload['tags_json'],
            'related_shoot_id' => $payload['related_shoot_id'],
        ]));
        $this->app->instance(MessagingService::class, $messaging);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_morning_client_emails_use_previous_day_evening_and_two_hours_before_without_extra_day_of(): void
    {
        [$shoot, $client, $photographer] = $this->appointment('2026-10-03 10:00');
        foreach (['2026-10-02 06:59', '2026-10-02 07:00', '2026-10-02 10:00', '2026-10-02 19:00', '2026-10-03 07:00', '2026-10-03 08:00'] as $at) {
            $this->tick($at);
        }
        $this->assertSame(3, Message::where('to_address', $client->email)->count());
        $this->assertSame(1, Message::where('to_address', $photographer->email)->count(), 'Photographer retains the original 24-hour reminder.');
        $this->assertSame([$shoot->id], Message::pluck('related_shoot_id')->unique()->all());
        $this->assertStringContainsString('10:00 AM EDT', Message::where('to_address', $client->email)->first()->body_text);
    }

    public function test_afternoon_client_emails_use_7am_previous_day_and_day_of_only(): void
    {
        [, $client, $photographer] = $this->appointment('2026-10-03 15:00');
        foreach (['2026-10-02 07:00', '2026-10-02 15:00', '2026-10-02 19:00', '2026-10-03 07:00', '2026-10-03 13:00'] as $at) {
            $this->tick($at);
        }
        $this->assertSame(2, Message::where('to_address', $client->email)->count());
        $this->assertSame(1, Message::where('to_address', $photographer->email)->count());
    }

    public function test_disabled_cancelled_and_finished_shoots_do_not_send(): void
    {
        [$shoot] = $this->appointment('2026-10-03 10:00');
        $shoot->update(['status' => 'cancelled']);
        $this->tick('2026-10-02 07:00');
        $shoot->update(['status' => 'scheduled', 'workflow_status' => 'delivered']);
        $this->tick('2026-10-02 19:00');
        $shoot->update(['workflow_status' => 'scheduled']);
        AutomationRule::query()->update(['is_active' => false]);
        $this->tick('2026-10-03 08:00');
        $this->assertSame(0, Message::count());
    }

    public function test_service_items_share_one_client_email_per_appointment_but_different_times_stay_separate(): void
    {
        [$shoot, $client, $photographer] = $this->appointment('2026-10-03 10:00');
        foreach (['Photos' => '10:00', 'Floor plan' => '10:00', 'Video' => '11:00'] as $name => $time) {
            $shoot->serviceItems()->create([
                'service_id' => \App\Models\Service::factory()->create(['name' => $name])->id,
                'scheduled_at' => Carbon::parse('2026-10-03 '.$time, 'America/New_York')->utc(),
                'workflow_status' => 'scheduled', 'photographer_id' => $photographer->id, 'price' => 100,
            ]);
        }
        $this->tick('2026-10-02 07:00');
        $messages = Message::where('to_address', $client->email)->get();
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('Photos, Floor plan', $messages[0]->body_text);
        $this->assertStringNotContainsString('Video', $messages[0]->body_text);
        $this->assertStringContainsString('Video', $messages[1]->body_text);
        $this->tick('2026-10-02 10:00');
        $this->assertSame(1, Message::where('to_address', $photographer->email)->count());
        $this->assertStringContainsString('Photos, Floor plan', Message::where('to_address', $photographer->email)->first()->body_text);
        $this->tick('2026-10-02 11:00');
        $this->assertSame(2, Message::where('to_address', $photographer->email)->count());
    }

    public function test_rescheduled_appointment_cancels_waiting_reminder_before_delivery(): void
    {
        [$shoot] = $this->appointment('2026-10-03 10:00');
        $rule = AutomationRule::active()->where('trigger_type', 'SHOOT_REMINDER')->firstOrFail();
        $workflow = $rule->workflow_definition_json;
        $workflow['nodes'][] = ['id' => 'wait', 'type' => 'wait.duration', 'config' => ['amount' => 60, 'unit' => 'minutes']];
        $workflow['edges'][0]['target'] = 'wait';
        $workflow['edges'][] = ['source' => 'wait', 'target' => 'email'];
        $rule->update(['workflow_definition_json' => $workflow]);
        $this->tick('2026-10-02 07:00');
        $this->assertSame(1, \App\Models\AutomationRun::where('status', 'waiting')->count());
        $shoot->update(['scheduled_at' => $shoot->scheduled_at->addDay(), 'scheduled_date' => '2026-10-04']);
        Carbon::setTestNow(Carbon::parse('2026-10-02 08:00', 'America/New_York')->utc());
        app(\App\Services\Messaging\AutomationWorkflowExecutor::class)->resumeDueSteps();
        $this->assertSame(0, Message::count());
        $this->assertSame(1, \App\Models\AutomationRun::where('status', 'cancelled')->count());
    }

    #[DataProvider('reminderChannels')]
    public function test_multiple_services_send_one_message_per_recipient_and_appointment(string $trigger, string $channel): void
    {
        [$shoot, $client, $photographer] = $this->appointment('2026-10-03 10:00');
        $client->update(['phonenumber' => '+12025550123']);
        $photographer->update(['phonenumber' => '+12025550124']);
        $other = User::factory()->create(['role' => 'photographer', 'phonenumber' => '+12025550125']);
        foreach ([['Photos', '10:00', $photographer, 'scheduled'], ['Floor plan', '10:00', $photographer, 'scheduled'],
            ['Drone', '10:00', $other, 'scheduled'], ['Video', '11:00', $photographer, 'scheduled'],
            ['Cancelled service', '10:00', $photographer, 'cancelled']] as [$name, $time, $assigned, $status]) {
            $shoot->serviceItems()->create([
                'service_id' => \App\Models\Service::factory()->create(['name' => $name])->id,
                'scheduled_at' => Carbon::parse('2026-10-03 '.$time, 'America/New_York')->utc(),
                'workflow_status' => $status, 'photographer_id' => $assigned->id, 'price' => 100,
            ]);
        }
        $rule = AutomationRule::active()->where('trigger_type', 'SHOOT_REMINDER')->firstOrFail();
        $workflow = $rule->workflow_definition_json;
        $workflow['nodes'][0]['config'] = ['triggerType' => $trigger, 'schedule' => ['offset' => '-2h']];
        $workflow['nodes'][1] = ['id' => 'email', 'type' => 'action.'.$channel, 'config' => [
            'subject' => 'Appointment reminder',
            'bodyText' => '{{shoot_time}}: {{shoot_services}} / {{services_provided}}',
            'recipientMode' => 'roles', 'recipientRoles' => $trigger === 'SHOOT_REMINDER' ? ['client', 'photographer'] : ['photographer'],
        ]];
        $rule->update(['trigger_type' => $trigger, 'schedule_json' => ['offset' => '-2h'], 'workflow_definition_json' => $workflow]);
        app(MessagingService::class)->shouldReceive('sendSms')->andReturnUsing(fn (array $payload) => Message::create([
            'channel' => 'SMS', 'direction' => 'OUTBOUND', 'provider' => 'FAKE', 'status' => 'SENT',
            'send_source' => 'AUTOMATION', 'to_address' => $payload['to'], 'body_text' => $payload['body_text'],
            'tags_json' => $payload['tags_json'], 'related_shoot_id' => $payload['related_shoot_id'],
        ]));
        $this->tick('2026-10-03 08:00');
        $expected = $trigger === 'SHOOT_REMINDER' ? 3 : 2;
        $this->assertSame($expected, Message::count());
        $address = $channel === 'sms' ? $photographer->phonenumber : $photographer->email;
        $this->assertSame(1, Message::where('to_address', $address)->count());
        foreach (Message::all() as $message) {
            $this->assertStringContainsString('Photos, Floor plan, Drone', $message->body_text);
            $this->assertStringNotContainsString('Video', $message->body_text);
            $this->assertStringNotContainsString('Cancelled service', $message->body_text);
        }
        $this->tick('2026-10-03 09:00');
        $this->assertSame($expected + ($trigger === 'SHOOT_REMINDER' ? 2 : 1), Message::count());
        $this->assertStringContainsString('Video', Message::latest('id')->first()->body_text);
        $this->assertSame(0, \App\Models\AutomationRun::where('status', 'failed')->count());
    }

    public static function reminderChannels(): array
    {
        return [
            ['SHOOT_REMINDER', 'sms'], ['PHOTOGRAPHER_SHOOT_REMINDER', 'sms'],
            ['SHOOT_REMINDER', 'email'], ['PHOTOGRAPHER_SHOOT_REMINDER', 'email'],
        ];
    }

    public function test_held_and_unapproved_shoots_never_send_appointment_reminders(): void
    {
        [$shoot] = $this->appointment('2026-10-03 10:00');
        foreach (['hold_on', 'on_hold', 'requested'] as $status) {
            $shoot->update(['status' => $status, 'workflow_status' => 'scheduled']);
            $this->tick('2026-10-02 07:00');
            $shoot->update(['status' => 'scheduled', 'workflow_status' => $status]);
            $this->tick('2026-10-02 07:00');
        }
        $this->assertSame(0, Message::count());
        $this->assertSame(0, \App\Models\AutomationRun::count());
    }

    public function test_client_email_stages_do_not_repeat_sms_actions(): void
    {
        [, $client] = $this->appointment('2026-10-03 10:00');
        $client->update(['phonenumber' => '+12025550123']);
        $rule = AutomationRule::active()->where('trigger_type', 'SHOOT_REMINDER')->firstOrFail();
        $workflow = $rule->workflow_definition_json;
        $workflow['nodes'][] = ['id' => 'sms', 'type' => 'action.sms', 'config' => [
            'bodyText' => 'Appointment reminder', 'recipientMode' => 'roles', 'recipientRoles' => ['client'],
        ]];
        $workflow['edges'][1]['target'] = 'sms';
        $workflow['edges'][] = ['source' => 'sms', 'target' => 'end'];
        $rule->update(['workflow_definition_json' => $workflow]);
        app(MessagingService::class)->shouldReceive('sendSms')->once()->with(Mockery::on(fn ($payload) => $payload['to'] === $client->phonenumber))
            ->andReturn(new Message(['status' => 'SENT']));
        foreach (['2026-10-02 07:00', '2026-10-02 10:00', '2026-10-02 19:00', '2026-10-03 08:00'] as $at) {
            $this->tick($at);
        }
        $this->assertSame(3, Message::where('to_address', $client->email)->count());
        $this->assertSame(0, \App\Models\AutomationRun::where('status', 'failed')->count());
    }

    public function test_noon_and_dst_transition_use_local_calendar_dates(): void
    {
        $scheduler = new ShootAppointmentReminderSchedule;
        $at = Carbon::parse('2026-11-01 12:00', 'America/New_York');
        $occurrences = $scheduler->occurrences($at, ['client_email_schedule' => ShootAppointmentReminderSchedule::CLIENT_DEFAULTS], true);
        $this->assertArrayNotHasKey('client_day_of', $occurrences);
        $this->assertSame('2026-10-31 07:00 -04:00', $occurrences['client_previous_day']['at']->format('Y-m-d H:i P'));
        $this->assertSame('2026-10-31 19:00 -04:00', $occurrences['client_previous_evening']['at']->format('Y-m-d H:i P'));
        $this->assertSame('2026-11-01 10:00 -05:00', $occurrences['client_before_shoot']['at']->format('Y-m-d H:i P'));
    }

    public function test_migration_preserves_authored_actions_disabled_state_and_custom_offsets(): void
    {
        $rule = $this->rule(['offset' => '-24h']);
        $rule->update(['is_active' => false]);
        $custom = $this->rule(['offset' => '-3h']);
        $action = $rule->workflow_definition_json['nodes'][1];
        $migration = require database_path('migrations/2026_10_01_120100_schedule_client_appointment_emails.php');
        $migration->up();
        $migration->up();
        $this->assertFalse($rule->fresh()->is_active);
        $this->assertSame($action, $rule->fresh()->workflow_definition_json['nodes'][1]);
        $this->assertSame(ShootAppointmentReminderSchedule::CLIENT_DEFAULTS, $rule->fresh()->schedule_json['client_email_schedule']);
        $this->assertSame(['offset' => '-3h'], $custom->fresh()->schedule_json);
    }

    private function appointment(string $local): array
    {
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->create(['role' => 'photographer', 'timezone' => 'America/New_York']);
        $at = Carbon::parse($local, 'America/New_York');
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id, 'photographer_id' => $photographer->id,
            'status' => 'scheduled', 'workflow_status' => 'scheduled', 'timezone' => 'America/New_York',
            'scheduled_at' => $at->copy()->utc(), 'scheduled_date' => $at->toDateString(), 'time' => $at->format('H:i'),
        ]);
        $this->rule(['offset' => '-24h', 'client_email_schedule' => ShootAppointmentReminderSchedule::CLIENT_DEFAULTS]);

        return [$shoot, $client, $photographer];
    }

    private function tick(string $local): void
    {
        Carbon::setTestNow(Carbon::parse($local, 'America/New_York')->utc());
        app(AutomationService::class)->triggerShootReminders();
        app(AutomationService::class)->triggerShootReminders();
    }

    private function rule(array $schedule): AutomationRule
    {
        return AutomationRule::create([
            'name' => 'Shoot Reminder', 'scope' => 'SYSTEM', 'trigger_type' => 'SHOOT_REMINDER',
            'is_active' => true, 'recipients_json' => ['client', 'photographer'], 'schedule_json' => $schedule,
            'workflow_definition_json' => [
                'nodes' => [
                    ['id' => 'trigger', 'type' => 'trigger.event', 'config' => ['triggerType' => 'SHOOT_REMINDER', 'schedule' => $schedule]],
                    ['id' => 'email', 'type' => 'action.email', 'config' => ['subject' => 'Appointment reminder', 'bodyText' => '{{shoot_address}} {{shoot_time}} {{shoot_services}}']],
                    ['id' => 'end', 'type' => 'end', 'config' => []],
                ],
                'edges' => [['source' => 'trigger', 'target' => 'email'], ['source' => 'email', 'target' => 'end']],
            ],
        ]);
    }
}
