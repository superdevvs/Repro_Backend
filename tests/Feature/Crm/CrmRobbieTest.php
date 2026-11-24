<?php

namespace Tests\Feature\Crm;

use App\Models\Crm\CrmActivity;
use App\Models\Crm\CrmAiProposal;
use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEmailSend;
use App\Models\Crm\CrmNote;
use App\Models\User;
use App\Services\Crm\Email\CrmSignatureService;
use App\Services\Crm\Email\CrmTemplateService;
use App\Services\ReproAi\LlmClient;
use App\Services\ReproAi\ToolDispatcher;

class CrmRobbieTest extends CrmTestCase
{
    /** A scripted model: each call returns the next response and records what it was sent. */
    private function fakeLlm(array $script): object
    {
        $fake = new class extends LlmClient
        {
            public array $script = [];

            public array $calls = [];

            public function __construct() {}

            public function chatCompletion(array $messages, array $tools = [], bool $stream = true, array $options = []): array|string
            {
                $this->calls[] = ['messages' => $messages, 'tools' => $tools, 'options' => $options];

                return array_shift($this->script) ?? ['choices' => [['message' => ['content' => 'Done.']]]];
            }
        };
        $fake->script = $script;
        $this->app->instance(LlmClient::class, $fake);

        return $fake;
    }

    private static function toolCall(string $name, array $args): array
    {
        return ['choices' => [['message' => ['content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args)]]]]]]];
    }

    private static function text(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content]]]];
    }

    public function test_a_reps_robbie_never_sees_another_reps_contacts(): void
    {
        $a = $this->rep();
        $b = $this->rep();
        $dana = $this->contactFor($a, ['name' => 'Dana Whitfield']);

        $fake = $this->fakeLlm([self::toolCall('crm_search_contacts', ['query' => 'Dana']), self::text('None found.'), self::toolCall('crm_get_contact', ['contact_id' => $dana->id]), self::text('Not found.')]);
        $this->actAs($b);
        $this->postJson('/api/crm/robbie/chat', ['message' => 'Find Dana'])->assertOk()->assertJsonPath('reply', 'None found.');
        $toolResult = collect($fake->calls[1]['messages'])->firstWhere('role', 'tool')['content'];
        $this->assertStringNotContainsString('Dana Whitfield', $toolResult);
        $this->postJson('/api/crm/robbie/chat', ['message' => 'Open contact '.$dana->id])->assertOk();
        $this->assertStringContainsString('Contact not found', collect($fake->calls[3]['messages'])->firstWhere('role', 'tool')['content']);

        $fake = $this->fakeLlm([self::toolCall('crm_search_contacts', ['query' => 'Dana']), self::text('Found Dana.')]);
        $this->actAs($a);
        $this->postJson('/api/crm/robbie/chat', ['message' => 'Find Dana', 'page' => 'contact', 'entity_type' => 'crm_contact', 'entity_id' => $dana->id])->assertOk();
        $this->assertStringContainsString('Dana Whitfield', collect($fake->calls[1]['messages'])->firstWhere('role', 'tool')['content']);
        $this->assertStringContainsString('looking at contact #'.$dana->id, $fake->calls[0]['messages'][0]['content']);
        $this->assertSame('gpt-6.1-terra', $fake->calls[0]['options']['model']);
        $this->assertSame('crm_robbie', $fake->calls[0]['options']['usage_feature']);
    }

    public function test_robbie_only_proposes_and_the_owner_confirms_once(): void
    {
        $rep = $this->rep();
        $contact = $this->contactFor($rep, ['name' => 'Dana Whitfield']);
        $this->fakeLlm([self::toolCall('crm_propose_note', ['contact_id' => $contact->id, 'body' => 'Prefers texts after 4pm']), self::text('I drafted a note for you to confirm.')]);
        $this->actAs($rep);

        $proposal = $this->postJson('/api/crm/robbie/chat', ['message' => 'Note that Dana prefers texts after 4pm'])->assertOk()->assertJsonCount(1, 'proposals')->json('proposals.0');
        $this->assertSame('pending', $proposal['status']);
        $this->assertSame(0, CrmNote::query()->count());

        $this->actAs($this->rep());
        $this->postJson("/api/crm/robbie/proposals/{$proposal['id']}/confirm")->assertNotFound();
        $this->assertSame(0, CrmNote::query()->count());

        $this->actAs($rep);
        $this->postJson("/api/crm/robbie/proposals/{$proposal['id']}/confirm", ['edits' => ['body' => 'Prefers texts after 4:30pm']])->assertOk()->assertJsonPath('proposal.status', 'confirmed');
        $this->assertSame('Prefers texts after 4:30pm', CrmNote::query()->value('body'));
        $this->assertTrue((bool) CrmNote::query()->value('via_robbie'));
        $this->assertTrue((bool) CrmActivity::query()->where('contact_id', $contact->id)->where('type', 'note')->value('via_robbie'));
        $this->postJson("/api/crm/robbie/proposals/{$proposal['id']}/confirm")->assertUnprocessable();
        $this->assertSame(1, CrmNote::query()->count());
    }

    public function test_stage_and_drip_proposals_run_through_normal_rules(): void
    {
        $rep = $this->rep();
        $contact = $this->contactFor($rep, ['stage' => CrmContact::STAGE_INQUIRY]);
        $this->actAs($rep);
        $service = app(\App\Services\Crm\Robbie\CrmProposalService::class);

        $lost = $service->propose($rep, 'stage', $contact, ['stage' => 'lost', 'lost_reason' => 'price'], 'Mark lost');
        $this->postJson("/api/crm/robbie/proposals/{$lost->id}/confirm")->assertOk();
        $this->assertSame(CrmContact::STAGE_LOST, $contact->fresh()->stage);
        $this->assertSame(1, CrmActivity::query()->where('contact_id', $contact->id)->where('via_robbie', true)->count());

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->propose($rep, 'drip', $contact, ['campaign_id' => 999], 'Enrol');
    }

    public function test_robbie_email_drafts_still_pass_the_content_check(): void
    {
        $rep = $this->rep();
        $super = $this->superAdmin();
        $sig = app(CrmSignatureService::class)->submit($rep, ['name' => $rep->name, 'title' => 'Sales', 'sender_email' => 'rep@reprophotos.com']);
        app(CrmSignatureService::class)->approve($sig, $super);
        $admin = $this->admin();
        $template = app(CrmTemplateService::class)->create('Check-in', 'one_off', ['subject' => 'Checking in', 'body_html' => '<p>Hi {{first_name}}, how are listings going?</p>'], $admin);
        app(CrmTemplateService::class)->approve(app(CrmTemplateService::class)->submit($template, $admin), $admin);
        $contact = $this->contactFor($rep, ['name' => 'Dana Whitfield', 'email' => 'dana@x.com']);

        $this->fakeLlm([self::text(json_encode(['subject' => 'Spring listings', 'body_html' => '<p>Hi {{first_name}}, we are the fastest photographers in Bethesda, guaranteed.</p>']))]);
        $this->actAs($rep);
        $proposal = $this->postJson("/api/crm/robbie/contacts/{$contact->id}/draft-email", ['template_id' => $template->id, 'instruction' => 'mention spring'])
            ->assertCreated()->assertJsonPath('drafted', true)->json('proposal');

        $this->postJson("/api/crm/robbie/proposals/{$proposal['id']}/confirm")->assertUnprocessable();
        $this->assertSame(0, CrmEmailSend::query()->count());
        $this->assertSame('pending', CrmAiProposal::query()->find($proposal['id'])->status);

        $this->postJson("/api/crm/robbie/proposals/{$proposal['id']}/confirm", ['edits' => ['body_html' => '<p>Hi {{first_name}}, spring is a good time for twilight shoots.</p>']])->assertOk();
        $this->assertStringContainsString('Hi Dana', CrmEmailSend::query()->value('html'));
        $this->assertTrue((bool) CrmActivity::query()->where('contact_id', $contact->id)->where('type', 'email_sent')->value('via_robbie'));
    }

    public function test_crm_tools_refuse_without_the_signed_in_user(): void
    {
        $rep = $this->rep();
        $this->contactFor($rep, ['name' => 'Dana Whitfield']);

        $this->expectException(\RuntimeException::class);
        app(ToolDispatcher::class)->dispatch('crm_search_contacts', ['query' => 'Dana'], ['user' => $rep]);
    }

    public function test_insights_and_plan_day_are_scoped(): void
    {
        $rep = $this->rep();
        $other = $this->rep();
        $mine = $this->contactFor($rep, ['name' => 'Mine']);
        $theirs = $this->contactFor($other, ['name' => 'Theirs']);
        $events = app(\App\Services\Crm\CrmEventService::class);
        $events->schedule($mine, ['type' => 'call', 'starts_at' => now()->subDays(2)->toIso8601String()], $rep);
        $events->schedule($theirs, ['type' => 'call', 'starts_at' => now()->subDays(2)->toIso8601String()], $other);

        $this->actAs($rep);
        $this->getJson('/api/crm/robbie/insights')->assertOk()->assertJsonPath('data.0.key', 'overdue')->assertJsonPath('data.0.count', 1);
        $plan = $this->getJson('/api/crm/robbie/plan-day')->assertOk()->json('data');
        $this->assertSame(['Mine'], collect($plan)->pluck('contact.name')->filter()->unique()->values()->all());
        $this->getJson("/api/crm/robbie/contacts/{$mine->id}/brief")->assertOk()->assertJsonPath('ai', false);
        $this->getJson("/api/crm/robbie/contacts/{$theirs->id}/brief")->assertNotFound();
        $this->assertNotNull(User::query()->find($other->id));
    }
}
