<?php

namespace App\Services\MobileAssistant;

use App\Models\AiChatSession;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\ReproAi\LlmClient;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Siri has its own narrow tool executor; it never enters the transactional general-chat dispatcher. */
final class AssistantRobbie
{
    public function __construct(private AssistantCapabilities $capabilities, private AssistantQueries $queries, private AssistantActions $actions) {}

    public function ask(User $actor, array $input): array
    {
        $data = Validator::make($input, ['prompt' => 'required|string|min:1|max:2000', 'request_id' => 'required|uuid',
            'session_id' => 'nullable|integer|min:1', 'context' => 'nullable|array', 'context.shoot_id' => 'nullable|integer|min:1'])->validate();
        $this->capabilities->require($actor, 'robbie.ask');
        $session = ! empty($data['session_id']) ? AiChatSession::where('user_id', $actor->id)->findOrFail($data['session_id'])
            : AiChatSession::where('user_id', $actor->id)->where('meta->siri_initial_request_id', $data['request_id'])->first();
        $session ??= AiChatSession::create(['user_id' => $actor->id, 'title' => Str::limit($data['prompt'], 70), 'engine' => 'openai',
            'meta' => ['surface' => 'siri', 'siri_initial_request_id' => $data['request_id']]]);
        $prior = AiMessage::where('chat_session_id', $session->id)->where('metadata->siri_request_id', $data['request_id'])->where('sender', 'user')->first();
        abort_if($prior && $prior->content !== $data['prompt'], 409, 'This request ID belongs to a different question.');
        if (! $prior) {
            AiMessage::create(['chat_session_id' => $session->id, 'sender' => 'user', 'content' => $data['prompt'], 'metadata' => ['surface' => 'siri', 'siri_request_id' => $data['request_id']]]);
        }
        $actions = array_values(array_filter($this->capabilities->actions($actor), fn ($a) => $a['id'] !== 'robbie.ask'));
        $context = [];
        if (! empty($data['context']['shoot_id'])) {
            $shoot = $this->queries->shoot($actor, (int) $data['context']['shoot_id']);
            $context = ['shoot_id' => $shoot->id, 'address' => $shoot->address];
        }
        $result = null;
        try {
            $route = $this->simpleRoute($data['prompt']);
            if (! $route && config('services.openai.api_key')) {
                $schema = ['type' => 'function', 'function' => ['name' => 'workspace_action',
                    'description' => 'Select one authorized read or prepare one change. Never invent a record, recipient, sender or time. If information is missing return action clarification and a question.',
                    'parameters' => ['type' => 'object', 'properties' => [
                        'action' => ['type' => 'string', 'enum' => [...array_column($actions, 'id'), 'clarification']],
                        'params' => ['type' => 'object', 'additionalProperties' => true],
                        'question' => ['type' => 'string'],
                    ], 'required' => ['action', 'params']]]];
                $response = app(LlmClient::class)->chatCompletion([
                    ['role' => 'system', 'content' => 'You select Siri workspace tools; you do not execute actions. The actor is '.$this->capabilities->role($actor)
                        .'. Today is '.now($this->capabilities->timezone())->toDateString().' in '.$this->capabilities->timezone().'. '
                        .'Parameters: reads use query, shoot_id, date, next, status; note.create uses shoot_id, content; availability.create uses photographer_id, date, start_time, end_time, timezone, status; sms.send uses thread_id, body; support.create uses subject, body, category. Other changes must ask for missing fields. Use shoot.search to resolve names before preparing changes. Context: '.json_encode($context)],
                    ['role' => 'user', 'content' => $data['prompt']],
                ], [$schema], false, ['usage_feature' => 'mobile_assistant', 'max_tokens' => 500, 'temperature' => 0]);
                $tool = data_get($response, 'choices.0.message.tool_calls.0');
                if (data_get($tool, 'function.name') === 'workspace_action') {
                    $route = json_decode(data_get($tool, 'function.arguments', '{}'), true);
                }
            }
            if (is_array($route) && ($route['action'] ?? '') === 'clarification') {
                $question = Str::limit((string) ($route['question'] ?? 'Which shoot or person do you mean?'), 400);
                $result = $this->queries->result($actor, $question, [], ['requires_app' => true]);
            } elseif (is_array($route) && is_string($route['action'] ?? null) && is_array($route['params'] ?? null)) {
                abort_unless(in_array($route['action'], array_column($actions, 'id'), true), 403, 'This tool is not available.');
                $capability = $this->capabilities->require($actor, $route['action']);
                if ($capability['mode'] === 'read') {
                    $result = $this->queries->query($actor, ['action' => $route['action'], 'lock_safe' => false] + $route['params']);
                } else {
                    // A model can propose only. The same explicit native confirmation is required for every write.
                    $proposal = $this->actions->prepare($actor, $data['request_id'], $route['action'], $route['params']);
                    $result = $this->queries->result($actor, $proposal['summary'], [], ['proposal' => $proposal]);
                }
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            $result = $this->queries->result($actor, 'A few details are needed. Continue in R/E Pro to review this request.', [], ['requires_app' => true]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            if ($e->getStatusCode() === 403 || $e->getStatusCode() === 404) {
                throw $e;
            }
            $result = $this->queries->result($actor, 'This request needs review in R/E Pro.', [], ['requires_app' => true]);
        } catch (\Throwable) {
            // A delayed model response is a handoff, not a reason to retry an action or expose provider errors.
        }
        $result ??= $this->queries->result($actor, 'I saved your question. Continue with Robbie in R/E Pro.', [], ['requires_app' => true]);
        $result['session_id'] = (string) $session->id;
        $result['route'] = 'robbie-chat';
        $reply = AiMessage::where('chat_session_id', $session->id)->where('metadata->siri_request_id', $data['request_id'])->where('sender', 'assistant')->first();
        ($reply ?? new AiMessage)->fill(['chat_session_id' => $session->id, 'sender' => 'assistant', 'content' => $result['spoken'],
            'metadata' => ['surface' => 'siri', 'siri_request_id' => $data['request_id'], 'action_receipt_id' => $result['proposal']['id'] ?? null]])->save();

        return $result;
    }

    private function simpleRoute(string $prompt): ?array
    {
        $text = strtolower(trim($prompt));
        if (preg_match('/^(what(?:\x27s| is) (?:my |the )?next shoot|next shoot)[?.!]*$/', $text)) {
            return ['action' => 'briefing', 'params' => ['next' => true]];
        }
        if (preg_match('/^(my day|today(?:\x27s)? (?:shoots|schedule)|what(?:\x27s| is) on (?:my )?schedule)[?.!]*$/', $text)) {
            return ['action' => 'briefing', 'params' => []];
        }
        if (preg_match('/^(my (?:editing |assigned )?(?:work|edits|tasks)|editing queue)[?.!]*$/', $text)) {
            return ['action' => 'work.queue', 'params' => []];
        }

        return null;
    }
}
