<?php

namespace Tests\Feature;

use App\Models\AiChatSession;
use App\Models\User;
use App\Services\ReproAi\RuleBasedOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 19.1 — SupportFaqFlow must be reachable.
 *
 * The flow class existed but was never registered/dispatched, so no knowledge-base
 * question could ever reach it. These tests prove the rule-based orchestrator now
 * routes the `support_faq` intent to SupportFaqFlow and returns FAQ content.
 */
class SupportFaqFlowDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(): AiChatSession
    {
        $user = User::factory()->create(['role' => 'client']);

        return AiChatSession::create([
            'user_id' => $user->id,
            'title' => 'Support test',
        ]);
    }

    public function test_dispatches_support_faq_intent_to_faq_flow(): void
    {
        $session = $this->makeSession();
        $orchestrator = app(RuleBasedOrchestrator::class);

        $result = $orchestrator->handle($session, 'Who owns the copyright to my images?', [
            'intent' => 'support_faq',
        ]);

        $content = collect($result['messages'] ?? [])->pluck('content')->implode("\n");

        $this->assertStringContainsStringIgnoringCase('copyright', $content);
    }

    public function test_guesses_support_faq_for_human_handoff_without_explicit_intent(): void
    {
        $session = $this->makeSession();
        $orchestrator = app(RuleBasedOrchestrator::class);

        $result = $orchestrator->handle($session, 'I need to speak to a human please', []);

        $content = collect($result['messages'] ?? [])->pluck('content')->implode("\n");

        // SupportFaqFlow's escalation path asks the user to describe what they need.
        $this->assertStringContainsStringIgnoringCase('team member', $content);
    }
}
