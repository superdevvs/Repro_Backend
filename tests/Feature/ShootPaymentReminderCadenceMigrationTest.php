<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\PaymentReminder;
use App\Models\Shoot;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShootPaymentReminderCadenceMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const STOCK = [
        'reminder_days' => [1, 3, 7, 14, 21, 28],
        'monthly_day_of_week' => 0,
        'time' => '09:00',
    ];

    private const WEEKLY = [
        'reminder_days' => [1, 3, 7],
        'repeat_after_day' => 7,
        'repeat_every_days' => 7,
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_stock_rule_moves_to_weekly_and_cancels_obsolete_future_monthly_rows(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        AutomationRule::query()->where('trigger_type', 'SHOOT_PAYMENT_REMINDER')->delete();
        $workflow = [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger.shoot_payment_reminder', 'config' => ['schedule' => self::STOCK]],
                ['id' => 'email', 'type' => 'action.email', 'config' => ['subject' => 'Authored copy']],
            ],
            'edges' => [['source' => 'trigger', 'target' => 'email']],
        ];
        $rule = AutomationRule::create([
            'scope' => 'SYSTEM',
            'name' => 'Shoot Payment Reminder',
            'trigger_type' => 'SHOOT_PAYMENT_REMINDER',
            'is_active' => true,
            'schedule_json' => self::STOCK,
            'workflow_definition_json' => $workflow,
            'recipients_json' => ['client', 'rep'],
        ]);
        $shoot = Shoot::factory()->create([
            'payment_status' => 'unpaid',
            'bypass_paywall' => false,
            'shoot_ready_notified_at' => now(),
        ]);
        $obsolete = PaymentReminder::create([
            'shoot_id' => $shoot->id,
            'scheduled_date' => '2026-02-22',
            'scheduled_at' => '2026-02-22 09:00:00',
            'status' => PaymentReminder::STATUS_PENDING,
        ]);

        $migration = require database_path('migrations/2026_09_30_201000_make_stock_shoot_reminders_weekly.php');
        $migration->up();
        $migration->up();

        $rule->refresh();
        $this->assertSame(self::WEEKLY, $rule->schedule_json);
        $this->assertSame(self::WEEKLY, $rule->workflow_definition_json['nodes'][0]['config']['schedule']);
        $this->assertSame($workflow['nodes'][1], $rule->workflow_definition_json['nodes'][1]);
        $this->assertSame($workflow['edges'], $rule->workflow_definition_json['edges']);
        $this->assertSame(['client', 'rep'], $rule->recipients_json);
        $this->assertSame(PaymentReminder::STATUS_CANCELLED, $obsolete->fresh()->status);
        $this->assertDatabaseHas('payment_reminders', [
            'shoot_id' => $shoot->id,
            'scheduled_date' => '2026-02-05',
            'status' => PaymentReminder::STATUS_PENDING,
        ]);
    }

    public function test_authored_cadence_and_visual_schedule_are_preserved(): void
    {
        AutomationRule::query()->where('trigger_type', 'SHOOT_PAYMENT_REMINDER')->delete();
        $authored = ['reminder_days' => [2, 5], 'monthly_day_of_week' => 5, 'time' => '14:35'];
        $authoredTime = [...self::STOCK, 'time' => '14:35'];
        $rule = AutomationRule::create([
            'scope' => 'SYSTEM',
            'name' => 'Shoot Payment Reminder',
            'trigger_type' => 'SHOOT_PAYMENT_REMINDER',
            'is_active' => false,
            'schedule_json' => $authored,
            'workflow_definition_json' => [
                'nodes' => [['id' => 'trigger', 'type' => 'trigger.shoot_payment_reminder', 'config' => ['schedule' => $authored]]],
                'edges' => [],
            ],
        ]);
        $timeRule = AutomationRule::create([
            'scope' => 'SYSTEM',
            'name' => 'Shoot Payment Reminder',
            'trigger_type' => 'SHOOT_PAYMENT_REMINDER',
            'is_active' => false,
            'schedule_json' => $authoredTime,
            'workflow_definition_json' => [
                'nodes' => [['id' => 'trigger', 'type' => 'trigger.shoot_payment_reminder', 'config' => ['schedule' => $authoredTime]]],
                'edges' => [],
            ],
        ]);
        $visualRule = AutomationRule::create([
            'scope' => 'SYSTEM',
            'name' => 'Shoot Payment Reminder',
            'trigger_type' => 'SHOOT_PAYMENT_REMINDER',
            'is_active' => false,
            'schedule_json' => self::STOCK,
            'workflow_definition_json' => [
                'nodes' => [['id' => 'trigger', 'type' => 'trigger.shoot_payment_reminder', 'config' => ['schedule' => $authored]]],
                'edges' => [],
            ],
        ]);
        $migration = require database_path('migrations/2026_09_30_201000_make_stock_shoot_reminders_weekly.php');
        $migration->up();

        $this->assertSame($authored, $rule->fresh()->schedule_json);
        $this->assertSame($authored, $rule->fresh()->workflow_definition_json['nodes'][0]['config']['schedule']);
        $this->assertFalse($rule->fresh()->is_active);
        $this->assertSame($authoredTime, $timeRule->fresh()->schedule_json);
        $this->assertSame($authoredTime, $timeRule->fresh()->workflow_definition_json['nodes'][0]['config']['schedule']);
        $this->assertSame(self::STOCK, $visualRule->fresh()->schedule_json);
        $this->assertSame($authored, $visualRule->fresh()->workflow_definition_json['nodes'][0]['config']['schedule']);
    }
}
