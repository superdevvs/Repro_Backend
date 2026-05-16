<?php

namespace Tests\Unit\Messaging;

use App\Models\AiChatSession;
use App\Models\User;
use App\Services\Messaging\AiSms\SmsConfirmationGate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsConfirmationGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_affirmative_matches_yes_variants(): void
    {
        $gate = new SmsConfirmationGate();

        foreach (['yes', 'YES', 'y', 'confirm', 'go ahead', 'OK!', 'sure', 'yep.', 'do it'] as $body) {
            $this->assertTrue($gate->isAffirmative($body), "expected affirmative for [{$body}]");
        }

        foreach (['no', 'maybe', 'cancel that', 'reschedule please', ''] as $body) {
            $this->assertFalse($gate->isAffirmative($body), "expected non-affirmative for [{$body}]");
        }
    }

    public function test_queue_persists_pending_action_with_summary_and_expiry(): void
    {
        $gate = new SmsConfirmationGate();
        $session = $this->makeSession();

        config()->set('services.telnyx.ai_pending_action_ttl_minutes', 10);

        $entry = $gate->queue($session, 'reschedule_shoot', [
            'shoot_id' => 42,
            'new_date' => '2026-06-01',
            'new_time' => '14:00',
        ], 'Reply YES to confirm rescheduling shoot #42 to 2026-06-01 14:00.');

        $this->assertSame('reschedule_shoot', $entry['tool']);
        $this->assertStringContainsString('YES to confirm', $entry['summary']);

        $session->refresh();
        $persisted = $gate->pending($session);
        $this->assertNotNull($persisted);
        $this->assertSame('reschedule_shoot', $persisted['tool']);
        $this->assertSame(42, $persisted['payload']['shoot_id']);
    }

    public function test_pending_returns_null_after_expiry_and_clears(): void
    {
        $gate = new SmsConfirmationGate();
        $session = $this->makeSession();

        config()->set('services.telnyx.ai_pending_action_ttl_minutes', 1);

        $gate->queue($session, 'cancel_shoot', ['shoot_id' => 7], 'Reply YES to confirm cancelling shoot #7.');

        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        $this->assertNull($gate->pending($session));

        $session->refresh();
        $this->assertArrayNotHasKey('pending_action', $session->meta ?? []);

        Carbon::setTestNow(null);
    }

    public function test_clear_removes_pending_action_meta(): void
    {
        $gate = new SmsConfirmationGate();
        $session = $this->makeSession();

        $gate->queue($session, 'book_shoot', ['address' => '123 Main'], 'Reply YES to confirm booking at 123 Main.');
        $session->refresh();
        $this->assertNotNull($gate->pending($session));

        $gate->clear($session);
        $session->refresh();
        $this->assertNull($gate->pending($session));
    }

    private function makeSession(): AiChatSession
    {
        $user = User::factory()->create(['role' => 'client']);

        return AiChatSession::create([
            'user_id' => $user->id,
            'title' => 'SMS conversation',
            'topic' => 'general',
            'engine' => 'sms-agent',
            'channel' => 'SMS',
            'phone_e164' => '+12025550155',
            'last_inbound_at' => now(),
        ]);
    }
}
