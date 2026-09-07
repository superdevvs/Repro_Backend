<?php

namespace Tests\Unit\Messaging;

use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\AutomationRule;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\AutomationWorkflowConverter;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\AutomationWorkflowValidator;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Messaging\TemplateVariableResolver;
use App\Services\MailService;
use App\Services\SystemEmails\ProtectedAutomationEmailMap;
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

    public function test_dispatch_summary_tracks_when_client_and_photographer_emails_were_actually_sent(): void
    {
        $executor = $this->makeExecutor();
        $method = new ReflectionMethod($executor, 'summarizeEmailDeliveryByRole');
        $method->setAccessible(true);

        $run = new AutomationRun([
            'status' => 'completed',
            'context_json' => [
                'client' => ['email' => 'client@example.com'],
                'photographer' => ['email' => 'lead@example.com'],
                'photographers' => [
                    ['email' => 'lead@example.com'],
                    ['email' => 'second@example.com'],
                ],
            ],
        ]);
        $run->setRelation('steps', collect([
            new AutomationRunStep([
                'output_json' => [
                    'channel' => 'email',
                    'sent_to' => [
                        'CLIENT@example.com',
                        'second@example.com',
                        'ops@example.com',
                    ],
                ],
            ]),
            new AutomationRunStep([
                'output_json' => [
                    'channel' => 'internal',
                    'sent_to' => ['lead@example.com'],
                ],
            ]),
        ]));

        $summary = $method->invoke($executor, [$run]);

        $this->assertSame([
            'client@example.com',
            'second@example.com',
            'ops@example.com',
        ], $summary['email_sent_to']);
        $this->assertTrue($summary['client_email_sent']);
        $this->assertFalse($summary['photographer_email_sent'], 'A partial delivery must leave photographer fallback enabled.');

        $run->steps->push(new AutomationRunStep([
            'output_json' => ['channel' => 'email', 'sent_to' => ['LEAD@example.com']],
        ]));
        $summary = $method->invoke($executor, [$run]);
        $this->assertTrue($summary['photographer_email_sent']);
    }

    public function test_scheduled_dispatch_does_not_report_a_failed_photographer_as_sent_when_client_succeeds(): void
    {
        $mail = $this->createMock(MailService::class);
        $executor = $this->makeExecutor($mail);
        $client = new User(['email' => 'client@example.com']);
        $shoot = new Shoot();
        $mail->expects($this->once())->method('sendShootScheduledEmail')
            ->with($client, $shoot, 'https://app.test/pay', false)->willReturn(true);
        $mail->expects($this->once())->method('sendAssignedPhotographerShootScheduledEmailsWithRecipients')
            ->with($shoot)->willReturn([]);

        $method = new ReflectionMethod($executor, 'dispatchProtectedTrigger');
        $sentTo = $method->invoke($executor, 'SHOOT_SCHEDULED', ['client', 'photographer'], [
            'shoot' => $shoot,
            'client' => $client,
            'payment_link' => 'https://app.test/pay',
        ]);

        $this->assertSame([$client->email], $sentTo);
    }

    public function test_scheduled_dispatch_keeps_photographer_delivery_independent_of_client_failure(): void
    {
        $mail = $this->createMock(MailService::class);
        $executor = $this->makeExecutor($mail);
        $client = new User(['email' => 'client@example.com']);
        $shoot = new Shoot();
        $mail->expects($this->once())->method('sendShootScheduledEmail')
            ->with($client, $shoot, 'https://app.test/pay', false)->willReturn(false);
        $mail->expects($this->once())->method('sendAssignedPhotographerShootScheduledEmailsWithRecipients')
            ->with($shoot)->willReturn(['photographer@example.com']);

        $method = new ReflectionMethod($executor, 'dispatchProtectedTrigger');
        $sentTo = $method->invoke($executor, 'SHOOT_SCHEDULED', ['client', 'photographer'], [
            'shoot' => $shoot,
            'client' => $client,
            'payment_link' => 'https://app.test/pay',
        ]);

        $this->assertSame(['photographer@example.com'], $sentTo);
    }

    private function makeExecutor(?MailService $mailService = null): AutomationWorkflowExecutor
    {
        return new AutomationWorkflowExecutor(
            $this->createMock(MessagingService::class),
            $this->createMock(TemplateRenderer::class),
            $this->createMock(TemplateVariableResolver::class),
            $this->createMock(AutomationWorkflowConverter::class),
            $this->createMock(AutomationWorkflowValidator::class),
            $mailService ?? $this->createMock(MailService::class),
            $this->createMock(ProtectedAutomationEmailMap::class),
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
