<?php

namespace App\Services\Crm\Robbie;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEmailTemplate;
use App\Models\Crm\CrmEvent;
use App\Models\User;
use App\Services\Crm\Email\CrmHtmlSanitizer;
use App\Services\ReproAi\LlmClient;

/**
 * Robbie's writing help: an email drafted inside an approved template (R3),
 * a contact brief in plain words, and a drafted outcome note for a follow-up.
 * Every result is a draft the user edits and confirms.
 */
class CrmRobbieWriter
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly CrmContactBrief $brief,
    ) {}

    /** @return array{subject: string, body_html: string, drafted: bool} */
    public function draftEmail(CrmContact $contact, CrmEmailTemplate $template, ?string $instruction, User $user): array
    {
        $version = $template->approvedVersion;
        $facts = $this->brief->facts($contact);
        unset($facts['drip']);
        $prompt = "Rewrite this approved email for one contact. Keep the template's purpose, structure and any links. "
            ."Use merge fields like {{first_name}} as they are. Plain, accurate wording; no claims of being fastest, cheapest, best or guaranteed; no prices unless the template has them. "
            .'Allowed HTML: p, br, strong, em, ul, ol, li, a. Reply as JSON {"subject": "...", "body_html": "..."}.'
            ."\n\nTemplate subject: ".$version->subject
            ."\nTemplate body:\n".$version->body_html
            ."\n\nContact facts: ".json_encode($facts)
            .($instruction ? "\n\nThe sender asks: ".mb_substr($instruction, 0, 600) : '');

        $out = $this->json([
            ['role' => 'system', 'content' => 'You write short, warm, professional sales emails for R/E Pro Photos, a real estate photography company. You write as '.$user->name.'.'],
            ['role' => 'user', 'content' => $prompt],
        ]);
        $subject = trim((string) ($out['subject'] ?? ''));
        $body = trim((string) ($out['body_html'] ?? ''));
        if ($subject === '' || $body === '') {
            return ['subject' => $version->subject, 'body_html' => $version->body_html, 'drafted' => false];
        }

        return ['subject' => mb_substr($subject, 0, 200), 'body_html' => CrmHtmlSanitizer::clean($body), 'drafted' => true];
    }

    /** @return array{summary: string, facts: array, ai: bool} */
    public function brief(CrmContact $contact, bool $useAi): array
    {
        $facts = $this->brief->facts($contact);
        $plain = $this->brief->summary($facts);
        if (! $useAi) {
            return ['summary' => $plain, 'facts' => $facts, 'ai' => false];
        }
        unset($facts['drip']['sends']);
        $out = $this->json([
            ['role' => 'system', 'content' => 'You brief a sales rep on one contact before a call. Two or three short sentences, then one suggested next step. Only use the facts given. Reply as JSON {"summary": "...", "next_step": "..."}.'],
            ['role' => 'user', 'content' => json_encode($facts) ?: '{}'],
        ]);
        $summary = trim((string) ($out['summary'] ?? ''));
        if ($summary === '') {
            return ['summary' => $plain, 'facts' => $facts, 'ai' => false];
        }
        $next = trim((string) ($out['next_step'] ?? ''));

        return ['summary' => $summary.($next !== '' ? ' Next: '.$next : ''), 'facts' => $facts, 'ai' => true];
    }

    public function draftOutcome(CrmEvent $event, ?string $hint): string
    {
        $contact = $event->contact_id ? CrmContact::query()->find($event->contact_id) : null;
        $out = $this->json([
            ['role' => 'system', 'content' => 'Write a one or two sentence outcome note for a sales follow-up, in the first person, past tense, factual. Reply as JSON {"note": "..."}.'],
            ['role' => 'user', 'content' => json_encode([
                'contact' => $contact?->name, 'company' => $contact?->company, 'planned_note' => $event->note, 'rep_says' => $hint ? mb_substr($hint, 0, 800) : null,
            ]) ?: '{}'],
        ]);

        return mb_substr(trim((string) ($out['note'] ?? ($hint ?? ''))), 0, 2000);
    }

    private function json(array $messages): array
    {
        try {
            $response = $this->llm->chatCompletion($messages, [], false, [
                'model' => (string) config('crm.robbie.model', 'gpt-6.1-terra'),
                'reasoning_effort' => (string) config('crm.robbie.reasoning_effort', 'medium'),
                'usage_feature' => (string) config('crm.robbie.usage_feature', 'crm_robbie'),
                'response_format' => ['type' => 'json_object'],
                'max_tokens' => 1500,
                'timeout' => 45,
            ]);

            return json_decode((string) ($response['choices'][0]['message']['content'] ?? ''), true) ?: [];
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }
}
