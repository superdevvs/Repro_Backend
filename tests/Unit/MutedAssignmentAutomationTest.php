<?php

namespace Tests\Unit;

use App\Models\AutomationRule;
use App\Services\Messaging\AutomationService;
use Tests\TestCase;

class MutedAssignmentAutomationTest extends TestCase
{
    public function test_explicit_mute_flags_filter_new_assignment_and_update_automation_recipients(): void
    {
        $automation = (new \ReflectionClass(AutomationService::class))->newInstanceWithoutConstructor();
        foreach (['SHOOT_SCHEDULED', 'SHOOT_UPDATED', 'PHOTOGRAPHER_ASSIGNED'] as $trigger) {
            $rule = new AutomationRule(['trigger_type' => $trigger]);
            foreach (['Client' => 'notify_client', 'Photographer' => 'notify_photographer'] as $recipient => $flag) {
                $method = new \ReflectionMethod($automation, 'shouldInclude'.$recipient.'Recipient');
                $this->assertFalse($method->invoke($automation, $rule, [$flag => false]), $trigger.' '.$recipient);
                $this->assertTrue($method->invoke($automation, $rule, [$flag => true]), $trigger.' '.$recipient);
            }
        }
    }
}
