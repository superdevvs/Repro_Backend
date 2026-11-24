<?php

namespace App\Services\Crm\Robbie;

use App\Models\Crm\CrmAiProposal;
use App\Models\Crm\CrmContact;
use App\Models\User;
use App\Services\ReproAi\LlmClient;
use App\Services\ReproAi\ToolDispatcher;
use App\Services\ReproAi\Tools\CrmTools;
use App\Services\RobbieConfigService;

/**
 * Robbie's side panel in the CRM (frame R1). Knows the page and record the
 * user is on, uses only the CRM tools, and answers sales reps too (the main
 * chat keeps reps to help topics). Writes only proposals.
 */
class CrmRobbieChat
{
    private const MAX_ROUNDS = 4;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly ToolDispatcher $tools,
        private readonly RobbieConfigService $config,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @param  array{page?: string|null, entity_type?: string|null, entity_id?: int|null}  $where
     * @return array{reply: string, proposals: array<int, array>, tools_used: array<int, string>}
     */
    public function reply(User $user, string $message, array $history, array $where): array
    {
        $messages = [['role' => 'system', 'content' => $this->systemPrompt($user, $where)]];
        foreach (array_slice($history, -12) as $turn) {
            if (in_array($turn['role'] ?? '', ['user', 'assistant'], true) && trim((string) ($turn['content'] ?? '')) !== '') {
                $messages[] = ['role' => $turn['role'], 'content' => mb_substr((string) $turn['content'], 0, 4000)];
            }
        }
        $messages[] = ['role' => 'user', 'content' => mb_substr($message, 0, 4000)];

        $definitions = $this->allowedTools($user);
        $proposalIds = [];
        $used = [];
        $reply = '';

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $response = $this->llm->chatCompletion($messages, $definitions, false, [
                'model' => (string) config('crm.robbie.model', 'gpt-6.1-terra'),
                'reasoning_effort' => (string) config('crm.robbie.reasoning_effort', 'medium'),
                'usage_feature' => (string) config('crm.robbie.usage_feature', 'crm_robbie'),
                'max_tokens' => 1800,
                'timeout' => 60,
            ]);
            $choice = $response['choices'][0]['message'] ?? [];
            $calls = $choice['tool_calls'] ?? [];
            if (! $calls) {
                $reply = trim((string) ($choice['content'] ?? ''));
                break;
            }
            $messages[] = ['role' => 'assistant', 'content' => $choice['content'] ?? null, 'tool_calls' => $calls];
            foreach ($calls as $call) {
                $name = (string) ($call['function']['name'] ?? '');
                $args = json_decode((string) ($call['function']['arguments'] ?? '{}'), true) ?: [];
                $result = $this->runTool($user, $name, $args, $definitions);
                $used[] = $name;
                if (! empty($result['proposal_id'])) {
                    $proposalIds[] = (int) $result['proposal_id'];
                }
                $messages[] = ['role' => 'tool', 'tool_call_id' => (string) ($call['id'] ?? $name), 'content' => mb_substr(json_encode($result) ?: '{}', 0, 12000)];
            }
        }
        if ($reply === '') {
            $reply = $proposalIds ? 'Here is what I suggest. Nothing happens until you confirm.' : 'I couldn’t finish that. Try asking in a different way.';
        }

        $service = app(CrmProposalService::class);
        $proposals = CrmAiProposal::query()->whereIn('id', $proposalIds)->where('user_id', $user->id)->get()->map(fn ($p) => $service->present($p))->all();

        return ['reply' => $reply, 'proposals' => $proposals, 'tools_used' => array_values(array_unique($used))];
    }

    private function runTool(User $user, string $name, array $args, array $definitions): array
    {
        $allowed = collect($definitions)->pluck('function.name')->all();
        if (! in_array($name, $allowed, true)) {
            return ['success' => false, 'error' => 'That tool is not available here.'];
        }
        try {
            return $this->tools->dispatch($name, $args, ['user' => $user, 'channel' => 'crm_panel']);
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'error' => 'That didn’t work.'];
        }
    }

    /** CRM tools, minus any the Robbie settings deny for this role. */
    private function allowedTools(User $user): array
    {
        $settings = $this->config->getMergedConfigForRole($user->role)['tools'] ?? [];
        if (($settings['enabled'] ?? true) === false) {
            return [];
        }
        $allow = array_filter((array) ($settings['allow'] ?? []), fn ($t) => str_starts_with((string) $t, 'crm_'));
        $deny = (array) ($settings['deny'] ?? []);

        return array_values(array_filter(CrmTools::definitions(), function ($d) use ($allow, $deny) {
            $name = $d['function']['name'];

            return ! in_array($name, $deny, true) && ($allow === [] || in_array($name, $allow, true));
        }));
    }

    private function systemPrompt(User $user, array $where): string
    {
        $tz = config('app.timezone', 'America/New_York');
        $lines = [
            'You are Robbie, the assistant inside R/E Pro Photos’ Sales CRM. You help '.$user->name.' work their contacts: real estate agents, brokerages and businesses who buy property photography.',
            'Today is '.now()->timezone($tz)->format('l j F Y, g:i a').' ('.$tz.').',
            'Use the crm_ tools to look things up. Only talk about contacts the tools return; if a contact is not found, say so and do not guess.',
            'You cannot change anything yourself. To add a note, schedule a follow-up, change a stage, send an email or enrol a drip, call the matching crm_propose_ tool; the user confirms it in the panel. Say that it is waiting for their confirmation.',
            'Emails must start from an approved template (crm_list_email_templates). Keep any wording changes inside the template’s body: plain, accurate, no claims about being fastest, cheapest, guaranteed or best, and no prices that are not in the template.',
            'Never assign contacts to reps, delete anything, approve anything or promise discounts.',
            'Be brief: short sentences, lists for several items, contact names as they appear in the CRM.',
        ];
        $page = trim((string) ($where['page'] ?? ''));
        if ($page !== '') {
            $lines[] = 'The user is on the CRM page: '.mb_substr($page, 0, 80).'.';
        }
        if (($where['entity_type'] ?? null) === 'crm_contact' && ! empty($where['entity_id'])) {
            $contact = CrmContact::query()->find((int) $where['entity_id']);
            if ($contact) {
                $lines[] = 'They are looking at contact #'.$contact->id.', '.$contact->name.($contact->company ? ' ('.$contact->company.')' : '').', stage '.$contact->stageLabel().'. "This contact" or "them" means this person.';
            }
        }

        return implode("\n", $lines);
    }
}
