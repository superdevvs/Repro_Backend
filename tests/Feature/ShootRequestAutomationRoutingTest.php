<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\AutomationWorkflowConverter;
use App\Services\Messaging\AutomationWorkflowExecutor;
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
            $this->assertSame(['client', 'rep'], $rule->recipients_json);
            $action = collect($rule->workflow_definition_json['nodes'])->firstWhere('type', 'action.email');
            $this->assertSame(['client', 'rep'], $action['config']['recipientRoles']);
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
