<?php

namespace Tests\Feature;

use App\Models\AutomationDispatch;
use App\Models\AutomationRule;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\MessageTemplate;
use App\Models\Shoot;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\PayoutReportService;
use App\Services\SalesReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class ScheduledAutomationCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-28 10:00:00', 'UTC'));
        config(['app.timezone' => 'UTC']);
        Mail::fake();
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        AutomationRule::query()->update(['is_active' => false]);
        MessageChannel::create([
            'type' => 'EMAIL', 'provider' => 'LOCAL_SMTP', 'display_name' => 'Test email',
            'from_email' => 'hello@example.com', 'owner_scope' => 'GLOBAL',
            'is_default' => true, 'config_json' => [],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_invoice_due_uses_saved_day_and_time_and_deduplicates_each_rule(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $rule = $this->rule('INVOICE_DUE', ['days_before' => 1, 'time' => '11:00']);
        $invoice = $this->invoice($client, 1);
        $this->invoice($client, 0);
        $this->invoice($client, 1, ['status' => 'draft']);
        $this->invoice($client, 1, ['amount_paid' => 100, 'is_paid' => true]);

        Artisan::call('messaging:invoice-reminders');
        $this->assertSame(0, Message::count());
        Carbon::setTestNow(now()->setTime(11, 0));
        Artisan::call('messaging:invoice-reminders');
        Artisan::call('messaging:invoice-reminders');
        $this->assertSame(1, Message::count());
        $this->assertSame($invoice->id, Message::first()->related_invoice_id);
        $this->assertSame('due', Message::first()->metadata['reminder_stage']);

        $rule->update(['is_active' => false]);
        $this->rule('INVOICE_DUE', ['days_before' => 1, 'time' => '11:00'], 'Second due notice');
        Artisan::call('messaging:invoice-reminders');
        $this->assertSame(2, Message::count(), 'A separate enabled rule owns its own delivery record.');
    }

    public function test_default_overdue_cadence_and_saved_override_are_honored(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $rule = $this->rule('INVOICE_OVERDUE', ['time' => '09:30']);
        $invoices = collect([1, 2, 3, 5, 7, 14, 30, 60])->mapWithKeys(fn ($day) => [$day => $this->invoice($client, -$day)]);

        Artisan::call('messaging:invoice-reminders');
        $this->assertEqualsCanonicalizing(
            $invoices->except([2, 5])->pluck('id')->all(), Message::pluck('related_invoice_id')->all(),
        );
        $rule->update(['schedule_json' => ['overdue_days' => [2, 5], 'repeat_every_days' => 0, 'time' => '09:30']]);
        Artisan::call('messaging:invoice-reminders');
        Artisan::call('messaging:invoice-reminders');
        $this->assertSame(8, Message::count());
    }

    public function test_property_reminder_uses_saved_lead_days_skips_resolved_and_finished_shoots_and_sends_once(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $this->rule('PROPERTY_CONTACT_REMINDER', ['days_before' => 3, 'time' => '10:00']);
        $base = [
            'client_id' => $client->id, 'scheduled_date' => now()->addDays(3)->toDateString(),
            'scheduled_at' => now()->addDays(3)->setTime(15, 0), 'time' => '15:00', 'timezone' => 'UTC',
            'status' => Shoot::STATUS_SCHEDULED, 'workflow_status' => Shoot::WORKFLOW_BOOKED,
            'property_details' => [],
        ];
        $missing = Shoot::factory()->create($base);
        Shoot::factory()->create(array_merge($base, ['property_details' => ['presenceOption' => 'self']]));
        Shoot::factory()->create(array_merge($base, ['property_details' => ['lockboxCode' => '4821']]));
        Shoot::factory()->create(array_merge($base, ['workflow_status' => Shoot::WORKFLOW_COMPLETED]));
        Shoot::factory()->create(array_merge($base, ['status' => 'cancelled']));
        Shoot::factory()->create(array_merge($base, ['scheduled_date' => now()->addDays(2)->toDateString()]));

        Artisan::call('messaging:property-contact-reminders');
        Artisan::call('messaging:property-contact-reminders');
        $this->assertSame(1, Message::count());
        $this->assertSame($missing->id, Message::first()->related_shoot_id);
    }

    public function test_property_reminder_email_goes_to_the_client_and_rep_only(): void
    {
        $client = User::factory()->create(['role' => 'client', 'email' => 'client@example.test']);
        $rep = User::factory()->create(['role' => 'salesRep', 'email' => 'rep@example.test']);
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.test']);
        $photographer = User::factory()->create(['role' => 'photographer', 'email' => 'photo@example.test']);
        $this->rule('PROPERTY_CONTACT_REMINDER', ['days_before' => 0, 'time' => '10:00'], 'Saved automation copy', ['client', 'admin', 'photographer']);
        Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $rep->id,
            'photographer_id' => $photographer->id,
            'scheduled_date' => now()->toDateString(),
            'scheduled_at' => now()->setTime(15, 0),
            'time' => '15:00',
            'timezone' => 'UTC',
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::WORKFLOW_BOOKED,
            'property_details' => [],
        ]);

        Artisan::call('messaging:property-contact-reminders');

        $this->assertEqualsCanonicalizing(
            ['client@example.test', 'rep@example.test'],
            Message::query()->pluck('to_address')->all(),
        );
        $this->assertNull(Message::query()->where('to_address', $admin->email)->first());
    }

    public function test_weekly_reports_execute_saved_actions_skip_empty_reports_and_keep_disabled_rules_disabled(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $emptyRep = User::factory()->create(['role' => 'salesRep']);
        $rule = $this->rule('WEEKLY_SALES_REPORT', ['type' => 'weekly', 'day_of_week' => 1, 'time' => '11:00'],
            'Edited report {{report_total_shoots}}', ['rep']);
        // Legacy command is deliberately irrelevant; no command string from the DB runs.
        $rule->update(['condition_json' => ['command' => 'nonexistent:must-not-run']]);
        $reports = Mockery::mock(SalesReportService::class);
        $reports->shouldReceive('getLastCompletedWeek')->andReturn([now()->subWeek()->startOfWeek(), now()->subDay()]);
        $reports->shouldReceive('generateWeeklyReportsForAllSalesReps')->andReturn(collect([
            $this->report($rep, 3), $this->report($emptyRep, 0),
        ]));
        $reports->shouldReceive('isSalesRep')->withArgs(fn ($user) => $user->id === $rep->id)->andReturnTrue();
        $this->app->instance(SalesReportService::class, $reports);

        $this->assertSame(0, Artisan::call('automations:run-system'));
        $this->assertSame(0, Message::count());
        Carbon::setTestNow(now()->setTime(11, 0));
        $this->assertSame(0, Artisan::call('automations:run-system'));
        $this->assertSame(0, Artisan::call('automations:run-system'));
        $this->assertSame(1, Message::count());
        $this->assertSame('Edited report 3', Message::first()->subject);
        $this->assertSame($rep->email, Message::first()->to_address);
        $rule->update(['is_active' => false]);
        $count = AutomationRule::count();
        Artisan::call('automations:run-system');
        $this->assertFalse($rule->fresh()->is_active);
        $this->assertSame($count, AutomationRule::count());
    }

    public function test_weekly_invoicing_generates_without_direct_email_and_only_notifies_for_new_nonzero_invoices(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $this->rule('WEEKLY_AUTOMATED_INVOICING', ['type' => 'weekly', 'day_of_week' => 1, 'time' => '01:00'],
            'Editable invoice {{invoice_total}}', ['photographer']);
        $service = Mockery::mock(InvoiceService::class);
        $service->shouldReceive('generateForLastCompletedWeek')->once()->with(false)->andReturnUsing(function () use ($photographer) {
            return collect([
                $this->invoice($photographer, 0, ['role' => 'photographer', 'photographer_id' => $photographer->id, 'total_amount' => 100]),
                $this->invoice($photographer, 0, ['role' => 'photographer', 'photographer_id' => $photographer->id, 'total_amount' => 0]),
                $this->invoice($photographer, 0, ['role' => 'photographer', 'photographer_id' => $photographer->id, 'total_amount' => 50, 'created_at' => now()->subDay()]),
            ]);
        });
        $this->app->instance(InvoiceService::class, $service);

        $this->assertSame(0, Artisan::call('automations:run-system'));
        $this->assertSame(0, Artisan::call('automations:run-system'));
        $this->assertSame(1, Message::count());
        $this->assertSame('Editable invoice $100.00', Message::first()->subject);
        $this->assertSame('completed', AutomationDispatch::first()->status);
    }

    public function test_due_weekly_invoice_rules_share_one_generation_batch(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $schedule = ['type' => 'weekly', 'day_of_week' => 1, 'time' => '01:00'];
        $this->rule('WEEKLY_AUTOMATED_INVOICING', $schedule, 'First rule', ['photographer']);
        $this->rule('WEEKLY_AUTOMATED_INVOICING', $schedule, 'Second rule', ['photographer']);
        $service = Mockery::mock(InvoiceService::class);
        $service->shouldReceive('generateForLastCompletedWeek')->once()->with(false)->andReturnUsing(function () use ($photographer) {
            $invoice = $this->invoice($photographer, 0, ['role' => 'photographer', 'photographer_id' => $photographer->id]);
            Carbon::setTestNow(now()->addMinute());

            return collect([$invoice]);
        });
        $this->app->instance(InvoiceService::class, $service);

        $this->assertSame(0, Artisan::call('automations:run-system'));
        $this->assertSame(0, Artisan::call('automations:run-system'));
        $this->assertEqualsCanonicalizing(['First rule', 'Second rule'], Message::pluck('subject')->all());
    }

    public function test_invoice_prepared_for_recap_still_gets_one_scheduled_invoice_notice(): void
    {
        $photographer = User::factory()->photographer()->create();
        $this->rule('WEEKLY_AUTOMATED_INVOICING', ['type'=>'weekly','day_of_week'=>1,'time'=>'01:00'], 'Invoice notice', ['photographer']);
        $invoice = $this->invoice($photographer, 0, ['role'=>'photographer','photographer_id'=>$photographer->id,'created_at'=>now()->subDay()]);
        $invoice->recordAuditEvent('generated', null, 'Reconciled before recap', ['reconciliation_version'=>1]);
        $service = Mockery::mock(InvoiceService::class);
        $service->shouldReceive('generateForLastCompletedWeek')->twice()->with(false)->andReturn(collect([$invoice]));
        $this->app->instance(InvoiceService::class, $service);
        $this->assertSame(0, Artisan::call('automations:run-system'));
        $this->assertSame(0, Artisan::call('automations:run-system', ['--force'=>true]));
        $this->assertSame(1, Message::where('to_address', $photographer->email)->count());
    }

    public function test_weekly_summaries_exclude_staff_and_draft_invoices_and_keep_each_client_scoped(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $clients = User::factory()->count(2)->create(['role' => 'client']);
        foreach ($clients as $index => $client) {
            $shoot = Shoot::factory()->create(['client_id' => $client->id, 'rep_id' => $rep->id]);
            $this->invoice($client, 5, ['shoot_id' => $shoot->id, 'issue_date' => '2026-09-22', 'invoice_number' => 'CLIENT-'.$index]);
            $this->invoice($client, 5, ['shoot_id' => $shoot->id, 'issue_date' => '2026-09-22', 'invoice_number' => 'PRIVATE-PAYOUT-'.$index,
                'role' => 'photographer', 'photographer_id' => User::factory()->create(['role' => 'photographer'])->id]);
            $this->invoice($client, 5, ['shoot_id' => $shoot->id, 'issue_date' => '2026-09-22', 'invoice_number' => 'DRAFT-'.$index, 'status' => 'draft']);
            $internal = Shoot::factory()->create(['client_id' => $client->id, 'rep_id' => $rep->id, 'shoot_type' => Shoot::SHOOT_TYPE_INTERNAL_TEST]);
            $this->invoice($client, 5, ['shoot_id' => $internal->id, 'issue_date' => '2026-09-22', 'invoice_number' => 'INTERNAL-'.$index]);
        }
        AutomationRule::whereIn('trigger_type', ['INVOICE_SUMMARY', 'WEEKLY_REP_INVOICE'])->update(['is_active' => true]);

        $this->assertSame(0, Artisan::call('automations:run-system'));
        $this->assertSame(0, Artisan::call('messaging:invoice-summaries'));
        $this->assertSame(3, Message::count());
        foreach ($clients as $index => $client) {
            $body = Message::where('to_address', $client->email)->firstOrFail()->body_html;
            $this->assertStringContainsString('CLIENT-'.$index, $body);
            $this->assertStringNotContainsString('CLIENT-'.(1 - $index), $body);
            $this->assertStringNotContainsString('PRIVATE-PAYOUT', $body);
            $this->assertStringNotContainsString('DRAFT-', $body);
            $this->assertStringNotContainsString('INTERNAL-', $body);
        }
        $repBody = Message::where('to_address', $rep->email)->firstOrFail()->body_html;
        $this->assertStringContainsString('CLIENT-0', $repBody);
        $this->assertStringContainsString('CLIENT-1', $repBody);
        $this->assertStringNotContainsString('PRIVATE-PAYOUT', $repBody);
    }

    public function test_payout_workflows_use_saved_time_recipients_copy_and_period_deduplication(): void
    {
        $people = collect(['photographer', 'editor', 'salesRep'])->mapWithKeys(fn ($role) => [$role => User::factory()->create(['role' => $role])]);
        $service = Mockery::mock(PayoutReportService::class);
        foreach (['Photographer' => 'photographer', 'Editor' => 'editor', 'SalesRep' => 'salesRep'] as $method => $role) {
            $person = $people[$role];
            $service->shouldReceive('build'.$method.'Summaries')->andReturn(collect([[
                'id' => $person->id, 'name' => $person->name, 'email' => $person->email, 'role' => $role,
                'shoot_count' => 2, 'service_count' => 3, 'gross_total' => 321.45, 'average_value' => 160.725,
                'commission_rate' => 10, 'commission_total' => 32.15, 'compensation_total' => 0, 'payout_total' => 321.45,
            ]]));
        }
        $this->app->instance(PayoutReportService::class, $service);
        AutomationRule::whereIn('trigger_type', ['WEEKLY_PAYOUT_REPORT', 'WEEKLY_PAYOUT_DIGEST'])->update([
            'is_active' => true,
            'schedule_json' => json_encode(['type' => 'weekly', 'day_of_week' => 1, 'time' => '11:00', 'accounting_email' => 'edited-accounting@example.test']),
        ]);
        MessageTemplate::where('slug', 'payout-report')->update(['subject' => 'Edited payout report']);
        MessageTemplate::where('slug', 'payout-digest')->update(['subject' => 'Edited payout digest']);

        $this->assertSame(0, Artisan::call('automations:run-system'));
        $this->assertSame(0, Message::count());
        Carbon::setTestNow(now()->setTime(11, 0));
        $this->assertSame(0, Artisan::call('automations:run-system'));
        $this->assertSame(0, Artisan::call('payouts:send'));
        $this->assertSame(3, Message::count());
        $this->assertSame(0, Message::where('to_address', $people['photographer']->email)->count());
        foreach ($people->except('photographer') as $person) {
            $message = Message::where('to_address', $person->email)->firstOrFail();
            $this->assertSame('Edited payout report', $message->subject);
            $this->assertStringContainsString('$321.45', $message->body_html);
        }
        $digest = Message::where('to_address', 'edited-accounting@example.test')->firstOrFail();
        $this->assertSame('Edited payout digest', $digest->subject);
        foreach ($people as $person) {
            $this->assertStringContainsString($person->name, $digest->body_html);
        }
    }

    public function test_empty_and_disabled_payout_rules_do_not_send(): void
    {
        $service = Mockery::mock(PayoutReportService::class);
        foreach (['Photographer', 'Editor', 'SalesRep'] as $method) {
            $service->shouldReceive('build'.$method.'Summaries')->andReturn(collect());
        }
        $this->app->instance(PayoutReportService::class, $service);
        $this->assertSame(0, Artisan::call('payouts:send'));
        $this->assertSame(0, Message::count());
        AutomationRule::whereIn('trigger_type', ['WEEKLY_PAYOUT_REPORT', 'WEEKLY_PAYOUT_DIGEST'])->update(['is_active' => true]);
        $this->assertSame(0, Artisan::call('payouts:send'));
        $this->assertSame(0, Message::count());
    }

    public function test_sunday_invoice_time_follows_eastern_daylight_saving_time(): void
    {
        $rule = $this->rule('WEEKLY_AUTOMATED_INVOICING', ['type'=>'weekly','day_of_week'=>0,'time'=>'10:00','timezone'=>'America/New_York'], 'Sunday invoice', ['photographer']);
        $person = User::factory()->photographer()->create();
        $invoice = $this->invoice($person, 0, ['role'=>'photographer','photographer_id'=>$person->id]);
        $invoice->recordAuditEvent('generated', null, 'Reconciled', ['reconciliation_version'=>1]);
        $service = Mockery::mock(InvoiceService::class);
        $service->shouldReceive('generateForLastCompletedWeek')->twice()->with(false)->andReturn(collect([$invoice]));
        $this->app->instance(InvoiceService::class, $service);
        foreach (['2026-10-18 14:00:00','2026-11-01 15:00:00'] as $utcTime) {
            Carbon::setTestNow(Carbon::parse($utcTime,'UTC')->subMinute());
            $before = Message::count();
            $this->assertSame(0, Artisan::call('automations:run-system'));
            $this->assertSame($before, Message::count());
            Carbon::setTestNow(Carbon::parse($utcTime,'UTC'));
            // The same invoice remains protected from duplicate delivery even on a later week.
            $this->assertSame(0, Artisan::call('automations:run-system'));
            $this->assertSame($utcTime, AutomationDispatch::where('automation_rule_id',$rule->id)->latest('scheduled_for')->first()->scheduled_for->setTimezone('UTC')->format('Y-m-d H:i:s'));
        }
    }

    public function test_recipient_week_email_includes_supplemental_invoices_and_pending_work_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-18 14:00:00','UTC'));
        $schedule=['type'=>'weekly','day_of_week'=>0,'time'=>'10:00','timezone'=>'America/New_York','delivery_mode'=>'recipient_week'];
        $first=$this->rule('WEEKLY_AUTOMATED_INVOICING',$schedule,'One invoice',['photographer']);
        $this->rule('WEEKLY_AUTOMATED_INVOICING',$schedule,'Duplicate rule',['photographer']);
        $workflow=$first->workflow_definition_json;
        $workflow['nodes'][1]['config']['bodyText']='{{invoice_total}} {{invoice_items_text}} {{invoice_reconciliation_text}}';
        $first->update(['workflow_definition_json'=>$workflow]);
        $person=User::factory()->photographer()->create();
        $other=User::factory()->photographer()->create();
        $period=['role'=>'photographer','billing_period_start'=>'2026-10-11','billing_period_end'=>'2026-10-17'];
        $invoice=$this->invoice($person,0,$period+['photographer_id'=>$person->id,'total_amount'=>100]);
        $supplement=$this->invoice($person,0,$period+['photographer_id'=>$person->id,'total_amount'=>35]);
        $invoice->items()->create(['type'=>'charge','description'=>'First capture','quantity'=>1,'unit_amount'=>100,'total_amount'=>100]);
        $supplement->items()->create(['type'=>'expense','description'=>'Approved parking reimbursement','quantity'=>1,'unit_amount'=>35,'total_amount'=>35]);
        $foreign=$this->invoice($other,0,$period+['photographer_id'=>$other->id,'total_amount'=>45]);
        $foreign->items()->create(['type'=>'charge','description'=>'Other photographer private work','quantity'=>1,'unit_amount'=>45,'total_amount'=>45]);
        $service=\App\Models\Service::factory()->create(['name'=>'Pending floorplan','price'=>50,'pricing_type'=>'fixed','photographer_pay'=>22.5]);
        $shoot=Shoot::factory()->create(['photographer_id'=>$person->id,'scheduled_date'=>'2026-10-17','status'=>'scheduled','workflow_status'=>'scheduled','shoot_type'=>'standard','completed_at'=>null,'admin_verified_at'=>null,'photos_uploaded_at'=>null]);
        $shoot->services()->attach($service->id,['photographer_id'=>$person->id,'price'=>50,'quantity'=>1]);
        $generator=Mockery::mock(InvoiceService::class);
        $generator->shouldReceive('generateForLastCompletedWeek')->twice()->with(false)->andReturn(collect([$invoice,$supplement,$foreign]));
        $this->app->instance(InvoiceService::class,$generator);
        $this->assertSame(0,Artisan::call('automations:run-system'));
        $this->assertSame(0,Artisan::call('automations:run-system',['--force'=>true]));
        $messages=Message::where('to_address',$person->email)->get();
        $this->assertCount(1,$messages);
        $body=$messages->sole()->body_text;
        $this->assertStringContainsString('$135.00',$body);
        $this->assertStringContainsString('First capture',$body);
        $this->assertStringContainsString('parking reimbursement',$body);
        $this->assertStringContainsString('1 shoot awaiting photographer completion',$body);
        $this->assertStringContainsString('$22.50',$body);
        $this->assertStringNotContainsString('Other photographer private work',$body);
        $this->assertSame(2,Message::count());
    }

    public function test_new_effective_date_does_not_replay_this_weeks_old_invoice_email(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 07:00:00','UTC'));
        $this->rule('WEEKLY_AUTOMATED_INVOICING',['type'=>'weekly','day_of_week'=>0,'time'=>'10:00','timezone'=>'America/New_York','effective_from'=>'2026-10-18','delivery_mode'=>'recipient_week'],'Next Sunday',['photographer']);
        $service=Mockery::mock(InvoiceService::class);
        $service->shouldNotReceive('generateForLastCompletedWeek');
        $this->app->instance(InvoiceService::class,$service);
        $this->assertSame(0,Artisan::call('automations:run-system'));
        $this->assertSame(0,Artisan::call('automations:run-system',['--force'=>true]));
        $this->assertSame(0,Message::count());
    }

    public function test_consolidation_migration_preserves_other_recaps_and_existing_messages(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 07:00:00','UTC'));
        $rule=$this->rule('WEEKLY_AUTOMATED_INVOICING',['type'=>'weekly','day_of_week'=>1,'time'=>'01:00'],'Old invoice',['photographer']);
        $rule->update(['name'=>'Weekly Automated Invoicing']);
        $recap=$this->rule('WEEKLY_PAYOUT_REPORT',['type'=>'weekly','day_of_week'=>0,'time'=>'05:00'],'Recap',['photographer','editor','rep']);
        $migration=require database_path('migrations/2026_10_12_090000_consolidate_photographer_weekly_invoice_email.php');
        $migration->up();
        $this->assertSame('10:00',$rule->fresh()->schedule_json['time']);
        $this->assertSame('America/New_York',$rule->fresh()->schedule_json['timezone']);
        $this->assertSame('2026-10-18',$rule->fresh()->schedule_json['effective_from']);
        $this->assertSame(['editor','rep'],$recap->fresh()->recipients_json);
        $this->assertSame(['editor','rep'],$recap->fresh()->workflow_definition_json['nodes'][1]['config']['recipientRoles']);
        $this->assertSame('05:00',$recap->fresh()->schedule_json['time']);
        $edited=$rule->fresh()->schedule_json; $edited['time']='11:00'; $rule->update(['schedule_json'=>$edited]);
        $migration->up();
        $this->assertSame('11:00',$rule->fresh()->schedule_json['time']);
        $this->assertSame(0,Message::count());
    }

    private function invoice(User $client, int $dueInDays, array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'client_id' => $client->id, 'user_id' => $client->id, 'shoot_id' => null,
            'due_date' => now()->addDays($dueInDays)->toDateString(), 'total' => 100,
            'total_amount' => 100, 'amount_paid' => 0, 'status' => 'sent',
        ], $overrides));
    }

    private function rule(string $trigger, array $schedule, string $subject = 'Saved automation copy', array $roles = ['client']): AutomationRule
    {
        return AutomationRule::create([
            'name' => $trigger, 'trigger_type' => $trigger, 'scope' => 'SYSTEM', 'is_active' => true,
            'editor_mode' => 'visual', 'engine_version' => 2, 'schedule_json' => $schedule,
            'recipients_json' => $roles,
            'workflow_definition_json' => [
                'nodes' => [
                    ['id' => 'trigger', 'type' => str_starts_with($trigger, 'WEEKLY_') ? 'trigger.schedule' : 'trigger.event',
                        'config' => ['triggerType' => $trigger, 'schedule' => $schedule]],
                    ['id' => 'send', 'type' => 'action.email', 'config' => [
                        'subject' => $subject, 'bodyText' => 'Edited body', 'recipientMode' => 'roles', 'recipientRoles' => $roles,
                    ]],
                    ['id' => 'end', 'type' => 'end', 'config' => []],
                ],
                'edges' => [['id' => 'one', 'source' => 'trigger', 'target' => 'send'], ['id' => 'two', 'source' => 'send', 'target' => 'end']],
            ],
        ]);
    }

    private function report(User $rep, int $shoots): array
    {
        return ['sales_rep' => ['id' => $rep->id], 'period' => ['start' => '2026-09-20', 'end' => '2026-09-26'],
            'summary' => ['total_shoots' => $shoots, 'completed_shoots' => $shoots, 'total_revenue' => $shoots * 100,
                'total_paid' => 0, 'outstanding_balance' => $shoots * 100]];
    }
}
