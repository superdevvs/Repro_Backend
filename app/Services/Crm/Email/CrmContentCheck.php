<?php

namespace App\Services\Crm\Email;

use App\Models\User;
use App\Services\ReproAi\LlmClient;
use Throwable;

/**
 * Checks rep-written copy for misstated facts and over-the-edge promotion.
 *
 * A fixed rule list always runs (guarantees, unverifiable superlatives,
 * invented statistics). When CRM_CONTENT_CHECK_AI is on, Robbie also reviews
 * the text using the cheap model, passed explicitly. If the AI call fails the
 * rule result still stands.
 *
 * Result: {passed, issues: [{severity, kind, quote, message}], checks: [...], source}
 */
class CrmContentCheck
{
    /** [pattern, kind, message] */
    private const RULES = [
        ['/\bguarantee(d|s)?\b/i', 'over_promotion', 'Promises a result we can’t guarantee.'],
        ['/\b(fastest|cheapest|best|number one|#1|no\.?\s*1|leading|top-rated|unbeatable|lowest price)\b/i', 'unverifiable_claim', 'Claim can’t be verified. Say what we actually offer, for example "next-day delivery".'],
        ['/\b\d+(\.\d+)?\s*(x|times)\s+(faster|more|higher|better)\b/i', 'unverifiable_claim', 'Statistic with no source.'],
        ['/\b\d{1,3}\s*%\s+(more|faster|higher|increase)\b/i', 'unverifiable_claim', 'Statistic with no source.'],
        ['/\b(sell (your|the) (home|house|listing) (in|within) \d+)/i', 'over_promotion', 'Promises how fast a property will sell.'],
        ['/\b(act now|last chance|limited time only|don.?t miss out)\b/i', 'pressure', 'Pressure wording. Keep it informative.'],
        ['/\b(free)\b/i', 'pricing', 'Mentions something free. Check it matches a real offer.'],
    ];

    public function check(string $text, ?User $author = null, bool $useAi = true): array
    {
        $issues = [];
        foreach (self::RULES as [$pattern, $kind, $message]) {
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as [$quote, $offset]) {
                    $issues[] = [
                        'severity' => $kind === 'pricing' ? 'warning' : 'issue',
                        'kind' => $kind,
                        'quote' => $this->sentenceAround($text, (int) $offset, $quote),
                        'message' => $message,
                    ];
                }
            }
        }

        $source = 'rules';
        if ($useAi && config('crm.content_check_ai') && config('services.openai.api_key')) {
            try {
                $issues = array_merge($issues, $this->aiIssues($text));
                $source = 'rules+robbie';
            } catch (Throwable $e) {
                report($e);
            }
        }

        $issues = array_values(array_unique($issues, SORT_REGULAR));
        $blocking = array_filter($issues, fn ($i) => $i['severity'] === 'issue');

        return [
            'passed' => $blocking === [],
            'issues' => $issues,
            'checks' => [
                ['label' => 'No claims that can’t be verified', 'ok' => ! collect($issues)->contains('kind', 'unverifiable_claim')],
                ['label' => 'No over-the-edge promotion', 'ok' => ! collect($issues)->contains(fn ($i) => in_array($i['kind'], ['over_promotion', 'pressure'], true))],
            ],
            'source' => $source,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function aiIssues(string $text): array
    {
        $prompt = 'You review sales emails for R/E Pro Photos, a real-estate photography company. '
            .'Flag only (a) claims that misstate facts or cannot be verified and (b) over-the-edge promotion or pressure. '
            .'Reply as JSON: {"issues":[{"kind":"unverifiable_claim|over_promotion|pressure","quote":"exact words","message":"short fix"}]}. '
            .'Return {"issues":[]} if the email is fine.';
        $response = app(LlmClient::class)->chatCompletion([
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => mb_substr($text, 0, 6000)],
        ], [], false, [
            'model' => config('crm.robbie.model'),
            'reasoning_effort' => config('crm.robbie.reasoning_effort'),
            'max_tokens' => 800,
            'response_format' => ['type' => 'json_object'],
            'usage_feature' => config('crm.robbie.review_usage_feature'),
            'timeout' => 30,
        ]);
        $content = is_array($response) ? (string) data_get($response, 'choices.0.message.content', '') : '';
        $decoded = json_decode($content, true);

        return collect($decoded['issues'] ?? [])->filter(fn ($i) => is_array($i) && ! empty($i['quote']))->map(fn ($i) => [
            'severity' => 'issue',
            'kind' => in_array($i['kind'] ?? '', ['unverifiable_claim', 'over_promotion', 'pressure'], true) ? $i['kind'] : 'unverifiable_claim',
            'quote' => mb_substr((string) $i['quote'], 0, 200),
            'message' => mb_substr((string) ($i['message'] ?? 'Review this wording.'), 0, 200),
        ])->values()->all();
    }

    private function sentenceAround(string $text, int $offset, string $quote): string
    {
        $start = max(0, strrpos(substr($text, 0, $offset), '.') !== false ? strrpos(substr($text, 0, $offset), '.') + 1 : 0);
        $end = strpos($text, '.', $offset + strlen($quote));
        $sentence = trim(substr($text, $start, ($end === false ? strlen($text) : $end + 1) - $start));

        return mb_substr($sentence, 0, 200);
    }
}
