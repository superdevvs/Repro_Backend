<?php

namespace Tests\Feature\Auth;

use App\Models\AutomationRule;
use App\Models\Message;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileTermsNotificationTest extends TestCase
{
    use RefreshDatabase;

    public static function ruleStates(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('ruleStates')]
    public function test_terms_notification_uses_the_saved_rule_and_honors_pause(bool $active): void
    {
        $user = User::factory()->create(['role' => 'client', 'metadata' => []]);
        AutomationRule::create([
            'name' => 'Terms confirmation', 'trigger_type' => 'TERMS_ACCEPTED', 'scope' => 'SYSTEM',
            'is_active' => $active, 'recipients_json' => ['account'], 'engine_version' => 2,
            'workflow_definition_json' => [
                'nodes' => [
                    ['id' => 'trigger', 'type' => 'trigger.event', 'config' => ['triggerType' => 'TERMS_ACCEPTED']],
                    ['id' => 'email', 'type' => 'action.email', 'config' => ['recipientMode' => 'automation_default', 'subject' => 'Saved terms confirmation', 'bodyText' => 'Your terms acceptance is recorded.']],
                    ['id' => 'end', 'type' => 'end', 'config' => []],
                ],
                'edges' => [['id' => 't-e', 'source' => 'trigger', 'target' => 'email'], ['id' => 'e-end', 'source' => 'email', 'target' => 'end']],
            ],
        ]);
        $this->mock(MailService::class)->shouldReceive('sendTermsAcceptedEmail')->never();
        $this->mock(MessagingService::class)->shouldReceive('sendEmail')->times($active ? 1 : 0)
            ->andReturnUsing(fn (array $payload) => new Message(['status' => 'SENT', 'to_address' => $payload['to']]));

        $this->withToken($user->createToken('profile')->plainTextToken)
            ->putJson('/api/profile', ['terms_accepted' => true])->assertOk();
        $this->assertNotEmpty($user->fresh()->metadata['terms_accepted_at']);
    }
}
