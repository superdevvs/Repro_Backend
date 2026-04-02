<?php

namespace Tests\Unit\Messaging;

use App\Models\AutomationRule;
use App\Services\Messaging\AutomationWorkflowConverter;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\AutomationWorkflowValidator;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Messaging\TemplateVariableResolver;
use ReflectionMethod;
use Tests\TestCase;

class AutomationWorkflowExecutorRecipientTest extends TestCase
{
    public function test_shoot_updated_email_actions_skip_unchecked_client_recipients(): void
    {
        $executor = $this->makeExecutor();
        $automation = new AutomationRule([
            'trigger_type' => 'SHOOT_UPDATED',
            'recipients_json' => ['client', 'photographer'],
        ]);

        $recipients = $this->resolveActionRecipients($executor, $automation, [], [
            'client' => ['email' => 'client@example.com', 'name' => 'Client User'],
            'photographer' => ['email' => 'photographer@example.com', 'name' => 'Photographer User'],
            'notify_client' => false,
            'notify_photographer' => true,
        ]);

        $this->assertSame(['photographer@example.com'], array_values(array_column($recipients, 'email')));
    }

    public function test_shoot_updated_email_actions_skip_unchecked_photographer_recipients(): void
    {
        $executor = $this->makeExecutor();
        $automation = new AutomationRule([
            'trigger_type' => 'SHOOT_UPDATED',
            'recipients_json' => ['client', 'photographer'],
        ]);

        $recipients = $this->resolveActionRecipients($executor, $automation, [], [
            'client' => ['email' => 'client@example.com', 'name' => 'Client User'],
            'photographer' => ['email' => 'photographer@example.com', 'name' => 'Photographer User'],
            'notify_client' => true,
            'notify_photographer' => false,
        ]);

        $this->assertSame(['client@example.com'], array_values(array_column($recipients, 'email')));
    }

    private function makeExecutor(): AutomationWorkflowExecutor
    {
        return new AutomationWorkflowExecutor(
            $this->createMock(MessagingService::class),
            $this->createMock(TemplateRenderer::class),
            $this->createMock(TemplateVariableResolver::class),
            $this->createMock(AutomationWorkflowConverter::class),
            $this->createMock(AutomationWorkflowValidator::class),
        );
    }

    private function resolveActionRecipients(
        AutomationWorkflowExecutor $executor,
        AutomationRule $automation,
        array $config,
        array $context
    ): array {
        $method = new ReflectionMethod($executor, 'resolveActionRecipients');
        $method->setAccessible(true);

        return $method->invoke($executor, $automation, $config, $context, 'email');
    }
}
