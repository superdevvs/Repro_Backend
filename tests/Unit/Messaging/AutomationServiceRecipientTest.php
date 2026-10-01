<?php

namespace Tests\Unit\Messaging;

use App\Models\AutomationRule;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Messaging\TemplateVariableResolver;
use ReflectionMethod;
use Tests\TestCase;

class AutomationServiceRecipientTest extends TestCase
{
    public function test_shoot_requested_automations_route_staff_recipients_to_sales_rep(): void
    {
        $service = $this->makeService();
        $rule = new AutomationRule([
            'trigger_type' => 'SHOOT_REQUESTED',
            'recipients_json' => ['client', 'photographer'],
        ]);

        $recipients = $this->resolveRecipients($service, $rule, [
            'client' => ['email' => 'client@example.com', 'name' => 'Client User'],
            'photographer' => ['email' => 'photographer@example.com', 'name' => 'Photographer User'],
            'rep' => ['email' => 'rep@example.com', 'name' => 'Sales Rep', 'phonenumber' => '+12025550123'],
        ]);

        $this->assertSame(['client@example.com', 'rep@example.com'], array_values(array_column($recipients, 'email')));
        $this->assertSame('+12025550123', $recipients[1]['phone']);
    }

    public function test_pending_cancellation_and_hold_rules_never_fall_back_to_photographers_without_a_rep(): void
    {
        foreach (['SHOOT_CANCELLATION_REQUESTED', 'HOLD_REQUESTED', 'SHOOT_RESCHEDULE_REQUESTED'] as $trigger) {
            $rule = new AutomationRule(['trigger_type' => $trigger, 'recipients_json' => ['photographer']]);
            $this->assertSame([], $this->resolveRecipients($this->makeService(), $rule, [
                'photographer' => ['email' => 'photographer@example.com'],
            ]), $trigger);
        }
    }

    public function test_actual_hold_notifies_client_and_all_assigned_photographers_without_adding_rep(): void
    {
        $rule = new AutomationRule(['trigger_type' => 'SHOOT_ON_HOLD', 'recipients_json' => ['client', 'photographer']]);
        $context = [
            'client' => ['email' => 'client@example.com'],
            'photographer' => ['email' => 'superseded@example.com'],
            'photographers' => [
                ['email' => 'first@example.com', 'phonenumber' => '+12025550101'],
                ['email' => 'second@example.com', 'phonenumber' => '+12025550102'],
            ],
            'rep' => ['email' => 'rep@example.com'],
        ];

        $recipients = $this->resolveRecipients($this->makeService(), $rule, $context);
        $this->assertSame(['client@example.com', 'first@example.com', 'second@example.com'], array_column($recipients, 'email'));
        $this->assertSame('+12025550102', $recipients[2]['phone']);

        unset($context['rep']);
        $this->assertSame($recipients, $this->resolveRecipients($this->makeService(), $rule, $context));
    }

    public function test_completed_cancellation_keeps_photographers_and_adds_sales_rep(): void
    {
        foreach (['SHOOT_CANCELLED', 'SHOOT_CANCELED'] as $trigger) {
            $rule = new AutomationRule(['trigger_type' => $trigger, 'recipients_json' => ['client', 'photographer']]);
            $recipients = $this->resolveRecipients($this->makeService(), $rule, [
                'client' => ['email' => 'client@example.com'],
                'photographers' => [
                    ['email' => 'first@example.com', 'phonenumber' => '+12025550101'],
                    ['email' => 'second@example.com', 'phonenumber' => '+12025550102'],
                ],
                'rep' => ['email' => 'rep@example.com', 'phonenumber' => '+12025550103'],
            ]);
            $this->assertSame(['client@example.com', 'first@example.com', 'second@example.com', 'rep@example.com'], array_column($recipients, 'email'));
            $this->assertSame('+12025550102', $recipients[2]['phone']);
        }
    }

    public function test_shoot_updated_automations_still_resolve_client_and_photographer(): void
    {
        $service = $this->makeService();
        $rule = new AutomationRule([
            'trigger_type' => 'SHOOT_UPDATED',
            'recipients_json' => ['client', 'photographer'],
        ]);

        $recipients = $this->resolveRecipients($service, $rule, [
            'client' => ['email' => 'client@example.com', 'name' => 'Client User'],
            'photographer' => ['email' => 'photographer@example.com', 'name' => 'Photographer User'],
        ]);

        $this->assertSame(
            ['client@example.com', 'photographer@example.com'],
            array_values(array_column($recipients, 'email'))
        );
    }

    public function test_shoot_updated_automations_skip_client_when_client_notifications_are_disabled(): void
    {
        $service = $this->makeService();
        $rule = new AutomationRule([
            'trigger_type' => 'SHOOT_UPDATED',
            'recipients_json' => ['client', 'photographer'],
        ]);

        $recipients = $this->resolveRecipients($service, $rule, [
            'client' => ['email' => 'client@example.com', 'name' => 'Client User'],
            'photographer' => ['email' => 'photographer@example.com', 'name' => 'Photographer User'],
            'notify_client' => false,
            'notify_photographer' => true,
        ]);

        $this->assertSame(['photographer@example.com'], array_values(array_column($recipients, 'email')));
    }

    public function test_shoot_request_modified_automations_only_resolve_client_recipients(): void
    {
        $service = $this->makeService();
        $rule = new AutomationRule([
            'trigger_type' => 'SHOOT_REQUEST_MODIFIED',
            'recipients_json' => ['client', 'photographer'],
        ]);

        $recipients = $this->resolveRecipients($service, $rule, [
            'client' => ['email' => 'client@example.com', 'name' => 'Client User'],
            'photographer' => ['email' => 'photographer@example.com', 'name' => 'Photographer User'],
        ]);

        $this->assertSame(['client@example.com'], array_values(array_column($recipients, 'email')));
    }

    public function test_shoot_updated_automations_skip_photographer_when_photographer_notifications_are_disabled(): void
    {
        $service = $this->makeService();
        $rule = new AutomationRule([
            'trigger_type' => 'SHOOT_UPDATED',
            'recipients_json' => ['client', 'photographer'],
        ]);

        $recipients = $this->resolveRecipients($service, $rule, [
            'client' => ['email' => 'client@example.com', 'name' => 'Client User'],
            'photographer' => ['email' => 'photographer@example.com', 'name' => 'Photographer User'],
            'notify_client' => true,
            'notify_photographer' => false,
        ]);

        $this->assertSame(['client@example.com'], array_values(array_column($recipients, 'email')));
    }

    public function test_photographer_changed_automations_only_resolve_affected_photographers(): void
    {
        $service = $this->makeService();
        $rule = new AutomationRule([
            'trigger_type' => 'PHOTOGRAPHER_CHANGED',
            'recipients_json' => ['client', 'photographer'],
        ]);

        $recipients = $this->resolveRecipients($service, $rule, [
            'client' => ['email' => 'client@example.com', 'name' => 'Client User'],
            'affected_photographers' => [
                ['email' => 'previous@example.com', 'name' => 'Previous Photographer'],
                ['email' => 'new@example.com', 'name' => 'New Photographer'],
            ],
        ]);

        $this->assertSame(
            ['previous@example.com', 'new@example.com'],
            array_values(array_column($recipients, 'email'))
        );
    }

    public function test_non_matrix_triggers_keep_their_existing_recipient_behavior(): void
    {
        $service = $this->makeService();
        $rule = new AutomationRule([
            'trigger_type' => 'PHOTOGRAPHER_ASSIGNED',
            'recipients_json' => ['photographer'],
        ]);

        $recipients = $this->resolveRecipients($service, $rule, [
            'photographer' => ['email' => 'photographer@example.com', 'name' => 'Photographer User'],
        ]);

        $this->assertSame(['photographer@example.com'], array_values(array_column($recipients, 'email')));
    }

    private function makeService(): AutomationService
    {
        return new AutomationService(
            $this->createMock(MessagingService::class),
            $this->createMock(TemplateRenderer::class),
            $this->createMock(TemplateVariableResolver::class),
            $this->createMock(AutomationWorkflowExecutor::class),
        );
    }

    private function resolveRecipients(AutomationService $service, AutomationRule $rule, array $context): array
    {
        $method = new ReflectionMethod($service, 'resolveRecipients');
        $method->setAccessible(true);

        return $method->invoke($service, $rule, $context);
    }
}
