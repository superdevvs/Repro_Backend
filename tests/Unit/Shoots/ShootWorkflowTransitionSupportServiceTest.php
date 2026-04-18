<?php

namespace Tests\Unit\Shoots;

use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Shoots\ShootWorkflowTransitionSupportService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class ShootWorkflowTransitionSupportServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_decline_side_effects_fire_request_declined_automation_when_active(): void
    {
        $client = new User([
            'id' => 11,
            'role' => 'client',
            'email' => 'client@example.com',
            'name' => 'Client User',
        ]);
        $admin = new User([
            'id' => 22,
            'role' => 'admin',
            'name' => 'Admin User',
        ]);
        $shoot = new Shoot([
            'id' => 33,
            'client_id' => $client->id,
            'declined_reason' => 'Outside service area',
        ]);
        $shoot->setRelation('client', $client);
        $shoot->setRelation('photographer', null);
        $shoot->setRelation('services', collect());

        $mailService = Mockery::mock(MailService::class);
        $mailService->shouldIgnoreMissing();
        $mailService->shouldReceive('sendShootRequestDeclinedEmail')->never();

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldReceive('buildShootContext')
            ->once()
            ->andReturn([
                'shoot' => $shoot,
                'shoot_id' => $shoot->id,
                'client' => $client,
            ]);
        $automationService->shouldReceive('hasActiveTrigger')
            ->zeroOrMoreTimes()
            ->andReturnTrue();
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->withArgs(function (string $triggerType, array $context) use ($shoot, $admin) {
                return $triggerType === 'SHOOT_REQUEST_DECLINED'
                    && ($context['shoot_id'] ?? null) === $shoot->id
                    && ($context['decline_reason'] ?? null) === 'Outside service area'
                    && ($context['user'] ?? null) === $admin;
            })
            ->andReturn([
                'trigger_type' => 'SHOOT_REQUEST_DECLINED',
                'active_rule_count' => 0,
                'run_count' => 0,
                'completed_run_count' => 0,
                'waiting_run_count' => 0,
                'failed_run_count' => 0,
                'handled' => false,
                'errors' => [],
                'email_sent_to' => [],
                'client_email_sent' => false,
                'photographer_email_sent' => false,
            ]);
        $automationService->shouldReceive('shouldUseFallback')->zeroOrMoreTimes()->andReturnFalse();

        $service = new ShootWorkflowTransitionSupportService($mailService, $automationService);
        $service->sendDeclineSideEffects($shoot, $admin);
    }
}
