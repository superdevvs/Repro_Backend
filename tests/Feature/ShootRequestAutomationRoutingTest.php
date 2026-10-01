<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Message;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\AutomationWorkflowConverter;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\ShootRequestRecipientRouting;
use Database\Seeders\MessagingSystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShootRequestAutomationRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_factory_rules_target_client_and_sales_rep(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        foreach (['SHOOT_REQUESTED', 'SHOOT_CANCELED'] as $trigger) {
            $rule = AutomationRule::where('trigger_type', $trigger)->firstOrFail();
            $roles = $trigger === 'SHOOT_CANCELED' ? ['client', 'rep', 'photographer'] : ['client', 'rep'];
            $this->assertSame($roles, $rule->recipients_json);
            $action = collect($rule->workflow_definition_json['nodes'])->firstWhere('type', 'action.email');
            $this->assertSame($roles, $action['config']['recipientRoles']);
        }
    }

    public function test_saved_rules_change_only_request_recipient_routing_and_migration_is_repeatable(): void
    {
        $rule = AutomationRule::create([
            'name' => 'Custom cancellation route', 'trigger_type' => 'SHOOT_CANCELLATION_REQUESTED', 'scope' => 'SYSTEM',
            'is_active' => false, 'recipients_json' => ['roles' => ['client', 'photographer', 'rep'], 'label' => 'Keep this'],
            'condition_json' => ['custom' => 'keep'], 'schedule_json' => ['offset' => '+2h'],
            'workflow_definition_json' => ['nodes' => [
                ['id' => 'email', 'type' => 'action.email', 'config' => ['recipientMode' => 'roles', 'recipientRoles' => ['photographer', 'rep'], 'bodyHtml' => '<p>Authored cancellation copy</p>']],
                ['id' => 'sms', 'type' => 'action.sms', 'config' => ['recipientMode' => 'context', 'contextKey' => 'photographer', 'bodyText' => 'Authored SMS']],
            ], 'edges' => [['source' => 'email', 'target' => 'sms']], 'meta' => ['custom' => 'keep']],
        ]);
        $unrelated = AutomationRule::create([
            'name' => 'Confirmed schedule', 'trigger_type' => 'SHOOT_SCHEDULED', 'scope' => 'SYSTEM', 'recipients_json' => ['photographer'],
        ]);
        $migration = require database_path('migrations/2026_10_01_120000_route_shoot_requests_to_sales_reps.php');
        $migration->up();
        $rule->refresh();
        $this->assertSame(['roles' => ['client', 'rep'], 'label' => 'Keep this'], $rule->recipients_json);
        $this->assertFalse($rule->is_active);
        $this->assertSame(['custom' => 'keep'], $rule->condition_json);
        $this->assertSame(['offset' => '+2h'], $rule->schedule_json);
        $workflow = $rule->workflow_definition_json;
        $this->assertSame(['rep'], $workflow['nodes'][0]['config']['recipientRoles']);
        $this->assertSame('<p>Authored cancellation copy</p>', $workflow['nodes'][0]['config']['bodyHtml']);
        $this->assertSame('rep', $workflow['nodes'][1]['config']['contextKey']);
        $this->assertSame('Authored SMS', $workflow['nodes'][1]['config']['bodyText']);
        $this->assertSame([['source' => 'email', 'target' => 'sms']], $workflow['edges']);
        $this->assertSame(['photographer'], $unrelated->fresh()->recipients_json);
        $expected = $rule->getAttributes();
        $migration->up();
        $this->assertSame($expected, $rule->fresh()->getAttributes());
    }

    public function test_migration_adds_rep_only_to_unmodified_factory_request_rule(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $stock = AutomationRule::where('trigger_type', 'SHOOT_REQUESTED')->firstOrFail();
        $stock->update(['recipients_json' => ['client'], 'is_active' => false]);
        $stock->update(['workflow_definition_json' => app(AutomationWorkflowConverter::class)->buildLegacyWorkflow($stock)]);
        $custom = $stock->replicate();
        $custom->name = 'Authored booking receipt';
        $workflow = $custom->workflow_definition_json;
        $workflow['nodes'][1]['config']['bodyHtml'] = '<p>Authored</p>';
        $custom->workflow_definition_json = $workflow;
        $custom->save();

        (require database_path('migrations/2026_10_01_120000_route_shoot_requests_to_sales_reps.php'))->up();
        $this->assertSame(['client', 'rep'], $stock->fresh()->recipients_json);
        $this->assertFalse($stock->fresh()->is_active);
        $this->assertSame(['client'], $custom->fresh()->recipients_json);
        $this->assertSame($workflow, $custom->fresh()->workflow_definition_json);
    }

    public function test_completed_cancellation_migration_keeps_photographers_without_broadening_client_only_rules(): void
    {
        $rule = AutomationRule::create([
            'name' => 'Authored final cancellation', 'trigger_type' => 'SHOOT_CANCELLED', 'scope' => 'SYSTEM', 'is_active' => false,
            'recipients_json' => ['client', 'photographer'], 'schedule_json' => ['offset' => '+2h'],
            'workflow_definition_json' => ['nodes' => [
                ['id' => 'email', 'type' => 'action.email', 'config' => ['recipientMode' => 'roles', 'recipientRoles' => ['client', 'photographer'], 'bodyHtml' => '<p>Saved cancellation copy</p>']],
                ['id' => 'sms', 'type' => 'action.sms', 'config' => ['recipientMode' => 'context', 'contextKey' => 'photographer', 'bodyText' => 'Saved cancellation SMS']],
            ], 'edges' => [['source' => 'email', 'target' => 'sms']]],
        ]);
        $clientOnly = AutomationRule::create(['name' => 'Only client', 'trigger_type' => 'SHOOT_CANCELED', 'scope' => 'SYSTEM', 'is_active' => false, 'recipients_json' => ['client']]);
        $repOnly = AutomationRule::create(['name' => 'Only rep', 'trigger_type' => 'SHOOT_CANCELED', 'scope' => 'SYSTEM', 'recipients_json' => ['rep']]);
        $migration = require database_path('migrations/2026_10_01_120000_route_shoot_requests_to_sales_reps.php');
        $migration->up();
        $rule->refresh();
        $this->assertSame(['client', 'photographer', 'rep'], $rule->recipients_json);
        $this->assertFalse($rule->is_active);
        $this->assertSame(['offset' => '+2h'], $rule->schedule_json);
        $nodes = $rule->workflow_definition_json['nodes'];
        $this->assertSame(['client', 'photographer', 'rep'], $nodes[0]['config']['recipientRoles']);
        $this->assertSame('<p>Saved cancellation copy</p>', $nodes[0]['config']['bodyHtml']);
        $this->assertSame('roles', $nodes[1]['config']['recipientMode']);
        $this->assertSame(['photographer', 'rep'], $nodes[1]['config']['recipientRoles']);
        $this->assertSame('Saved cancellation SMS', $nodes[1]['config']['bodyText']);
        $this->assertSame(['client'], $clientOnly->fresh()->recipients_json);
        $this->assertSame(['rep'], $repOnly->fresh()->recipients_json);
        $expected = $rule->getAttributes();
        $migration->up();
        $this->assertSame($expected, $rule->fresh()->getAttributes());
    }

    public function test_migration_preserves_saved_client_and_rep_audience_even_on_stock_named_cancellation_rule(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $stock = AutomationRule::where('trigger_type', 'SHOOT_CANCELED')->firstOrFail();
        $stock->update(['recipients_json' => ['client', 'rep'], 'is_active' => false]);
        $stock->update(['workflow_definition_json' => app(AutomationWorkflowConverter::class)->buildLegacyWorkflow($stock)]);
        $expected = $stock->fresh()->getAttributes();
        (require database_path('migrations/2026_10_01_120000_route_shoot_requests_to_sales_reps.php'))->up();
        $stock->refresh();
        $this->assertSame($expected, $stock->getAttributes());
        $this->assertSame(['client', 'rep'], $stock->recipients_json);
        $this->assertFalse($stock->is_active);
    }

    public function test_completed_cancellation_dispatches_to_client_rep_and_effective_service_photographers(): void
    {
        [$shoot, $client, $rep, $obsolete, $first, $second] = $this->cancellationFixture();
        $called = [];
        $mail = $this->mock(\App\Services\MailService::class);
        $mail->shouldReceive('sendShootCancelledEmail')->times(4)->andReturnUsing(function (User $recipient, Shoot $actualShoot, bool $fanout) use (&$called, $shoot, $second): bool {
            $called[] = $recipient->id;
            $this->assertSame($shoot->id, $actualShoot->id);
            $this->assertFalse($fanout);
            return $recipient->id !== $second->id;
        });
        $context = app(AutomationService::class)->buildShootContext($shoot);
        $result = (new \ReflectionMethod(AutomationWorkflowExecutor::class, 'dispatchProtectedTrigger'))->invoke(
            app(AutomationWorkflowExecutor::class), 'SHOOT_CANCELLED', ['client', 'rep', 'photographer'], $context
        );
        $this->assertEqualsCanonicalizing([$client->id, $rep->id, $first->id, $second->id], $called);
        $this->assertNotContains($obsolete->id, $called);
        $this->assertEqualsCanonicalizing([$client->email, $rep->email, $first->email], $result);
    }

    public function test_completed_cancellation_sms_uses_current_service_photographers_and_their_phone_numbers(): void
    {
        [$shoot, $client, $rep, $obsolete, $first, $second] = $this->cancellationFixture();
        $delivered = [];
        $messaging = $this->mock(MessagingService::class);
        $messaging->shouldReceive('sendSms')->times(4)->andReturnUsing(function (array $payload) use (&$delivered): Message {
            $delivered[] = $payload['to'];
            return new Message(['status' => 'SENT']);
        });
        $rule = AutomationRule::create(['name' => 'Cancellation SMS', 'scope' => 'SYSTEM', 'trigger_type' => 'SHOOT_CANCELED', 'recipients_json' => ['client', 'photographer']]);
        $context = app(AutomationService::class)->buildShootContext($shoot);
        // Delayed context contains the old primary; dispatch must refresh current assignments.
        $context['photographers'] = [$obsolete];
        $context['photographer'] = $obsolete;
        $result = (new \ReflectionMethod(AutomationWorkflowExecutor::class, 'executeSmsAction'))->invoke(
            app(AutomationWorkflowExecutor::class), $rule, ['id' => 'sms', 'config' => ['bodyText' => 'Shoot cancelled']], $context
        );
        $this->assertEqualsCanonicalizing([$client->phonenumber, $rep->phonenumber, $first->phonenumber, $second->phonenumber], $delivered);
        $this->assertEqualsCanonicalizing($delivered, $result['sent_to']);
        $this->assertSame([], $result['failed_to']);
        $this->assertNotContains($obsolete->phonenumber, $delivered);
    }

    public function test_legacy_completed_cancellation_sms_sends_to_phone_addresses(): void
    {
        [$shoot, $client, $rep, $obsolete, $first, $second] = $this->cancellationFixture();
        $delivered = [];
        $messaging = $this->mock(MessagingService::class);
        $messaging->shouldReceive('sendSms')->times(4)->andReturnUsing(function (array $payload) use (&$delivered): Message {
            $delivered[] = $payload['to'];
            return new Message(['status' => 'SENT']);
        });
        $rule = new AutomationRule(['name' => 'Legacy cancellation SMS', 'trigger_type' => 'SHOOT_CANCELED', 'recipients_json' => ['client', 'photographer']]);
        $rule->setRelation('template', new \App\Models\MessageTemplate(['channel' => 'SMS', 'body_text' => 'Shoot cancelled', 'is_active' => true]));
        $service = app(AutomationService::class);
        (new \ReflectionMethod($service, 'executeRule'))->invoke($service, $rule, $service->buildShootContext($shoot));
        $this->assertEqualsCanonicalizing([$client->phonenumber, $rep->phonenumber, $first->phonenumber, $second->phonenumber], $delivered);
        $this->assertNotContains($obsolete->phonenumber, $delivered);
    }

    private function cancellationFixture(): array
    {
        $client = User::factory()->create(['role' => 'client', 'phonenumber' => '+12025550101']);
        $rep = User::factory()->create(['role' => 'salesRep', 'phonenumber' => '+12025550102']);
        $obsolete = User::factory()->photographer()->create(['phonenumber' => '+12025550103']);
        $first = User::factory()->photographer()->create(['phonenumber' => '+12025550104']);
        $second = User::factory()->photographer()->create(['phonenumber' => '+12025550105']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'rep_id' => $rep->id, 'photographer_id' => $obsolete->id]);
        foreach ([$first, $second] as $photographer) {
            $shoot->services()->attach(Service::factory()->create()->id, ['photographer_id' => $photographer->id, 'price' => 100]);
        }
        return [$shoot, $client, $rep, $obsolete, $first, $second];
    }

    public function test_queued_request_without_rep_recovers_the_clients_account_rep(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client', 'metadata' => ['accountRepId' => (string) $rep->id]]);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'rep_id' => null, 'photographer_id' => $photographer->id]);

        $context = ShootRequestRecipientRouting::context('SHOOT_CANCELLATION_REQUESTED', ['shoot_id' => $shoot->id, 'rep' => null]);
        $this->assertSame($rep->id, $context['rep']->id);
        $context = ShootRequestRecipientRouting::context('SHOOT_CANCELLATION_REQUESTED', ['shoot' => $shoot, 'rep' => $photographer->toArray()]);
        $this->assertSame($rep->id, $context['rep']->id);
        $client->update(['metadata' => []]);
        $context = ShootRequestRecipientRouting::context('SHOOT_CANCELLATION_REQUESTED', ['shoot' => $shoot, 'rep' => $rep->toArray()]);
        $this->assertNull($context['rep']);
    }

    public function test_requested_admin_only_workflow_preserves_recipient_selection_and_reports_only_successes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $rep = User::factory()->create(['role' => 'salesRep']);
        $shoot = Shoot::factory()->create(['rep_id' => $rep->id]);
        $mail = $this->mock(\App\Services\MailService::class);
        $mail->shouldReceive('sendShootRequestedStaffEmail')->once()
            ->withArgs(fn (User $recipient, Shoot $selectedShoot) => $recipient->id === $admin->id && $selectedShoot->id === $shoot->id)
            ->andReturn(true);
        $mail->shouldNotReceive('sendShootRequestedAdminNotificationEmails');
        $sent = (new \ReflectionMethod(AutomationWorkflowExecutor::class, 'dispatchProtectedTrigger'))->invoke(
            app(AutomationWorkflowExecutor::class), 'SHOOT_REQUESTED', ['admin'], ['shoot' => $shoot, 'rep' => $rep]
        );
        $this->assertSame([$admin->email], $sent);
    }
}
