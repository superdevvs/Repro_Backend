<?php

namespace App\Services\ReproAi\Flows;

use App\Models\AiChatSession;
use App\Models\User;
use App\Services\ReproAi\SupportKnowledgeBase;
use App\Support\SupportContact;
use Illuminate\Support\Facades\Schema;

class SupportFaqFlow
{
    public function __construct(private SupportKnowledgeBase $knowledge) {}

    public function handle(AiChatSession $session, string $message, array $context = []): array
    {
        // Never trust the role or article body sent by the browser or the model.
        $user = User::find($session->user_id);
        $role = $this->knowledge->role($user);
        $articleId = is_string($context['knowledge_article_id'] ?? null) ? $context['knowledge_article_id'] : null;
        $handoff = (bool) preg_match('/\b(speak to|talk to|human|representative|support ticket|create (?:a )?ticket|escalate)\b/i', $message);
        $handoff = $handoff || in_array($session->step, ['escalate', 'create_ticket'], true);
        $articles = $handoff
            ? [$this->knowledge->find('support-contact', $user)]
            : ($articleId !== null
                ? array_filter([$this->knowledge->find($articleId, $user)])
                : $this->knowledge->search($message, $user));
        $articles = array_values($articles);
        $related = [];
        if (! $handoff && $articleId === null && $articles !== [] && ! $this->knowledge->canAnswer($message, $articles[0])) {
            $related = array_slice($articles, 0, 3);
            $articles = [];
        }

        // Clear stale placeholder ticket state and allow a later transactional flow.
        if ($session->intent === 'support_faq' || $this->knowledge->isHelpOnly($user)
            || in_array($session->step, ['escalate', 'create_ticket', 'ask_question'], true)) {
            foreach (['step', 'intent', 'state_data'] as $column) {
                if (Schema::hasColumn('ai_chat_sessions', $column)) {
                    $session->{$column} = $column === 'state_data' ? [] : null;
                }
            }
            $session->save();
        }

        if ($articles !== []) {
            $article = $articles[0];

            return [
                'assistant_messages' => [[
                    'content' => $this->knowledge->formatAnswer($article),
                    'metadata' => [
                        'step' => 'faq_answer', 'topic' => $article['id'], 'role' => $role,
                        'knowledge_version' => $this->knowledge->version(),
                        'knowledge_articles' => array_map(fn (array $item) => [
                            'id' => $item['id'], 'title' => $item['title'], 'url' => $item['url'],
                        ], array_slice($articles, 0, 3)),
                    ],
                ]],
                'suggestions' => array_slice(array_values(array_unique([
                    ...array_column(array_slice($articles, 1, 2), 'title'),
                    'View help guides', 'Speak to a human',
                ])), 0, 4),
            ];
        }

        $content = $articleId !== null
            ? 'That guide is unavailable for your account. Open Help & guides to see guidance for your role.'
            : 'I do not have a reviewed answer for that exact question yet. Tell me the page, what you tried and the error you see, or browse Help & guides for your role.';
        if ($related !== []) {
            $content .= "\n\nThese guides may be related, but do not confirm that your requested action is available:";
            foreach ($related as $item) {
                $content .= "\n- [".$item['title'].']('.$item['url'].')';
            }
        }
        $content .= "\n\nYou can [create a support request](/support?new=1) and follow its replies in the dashboard. For a team member, call or text **".SupportContact::PHONE_DISPLAY.'** or email **'.SupportContact::EMAIL.'**. This chat has not created a ticket or notified support. Do not include passwords, payment card details or access codes.';

        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => ['step' => 'ask_question', 'unmatched' => true, 'role' => $role, 'knowledge_articles' => []],
            ]],
            'suggestions' => array_slice(array_column($this->knowledge->search('', $user), 'title'), 0, 4),
        ];
    }
}
