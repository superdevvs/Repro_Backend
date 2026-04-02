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
    public function test_shoot_requested_automations_only_resolve_client_recipients(): void
    {
        $service = $this->makeService();
        $rule = new AutomationRule([
            'trigger_type' => 'SHOOT_REQUESTED',
            'recipients_json' => ['client', 'photographer'],
        ]);

        $recipients = $this->resolveRecipients($service, $rule, [
            'client' => ['email' => 'client@example.com', 'name' => 'Client User'],
            'photographer' => ['email' => 'photographer@example.com', 'name' => 'Photographer User'],
        ]);

        $this->assertSame(['client@example.com'], array_values(array_column($recipients, 'email')));
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
