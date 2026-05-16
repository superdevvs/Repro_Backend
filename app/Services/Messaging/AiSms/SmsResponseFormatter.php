<?php

namespace App\Services\Messaging\AiSms;

class SmsResponseFormatter
{
    private const SEGMENT_BUDGET = 1500;

    /**
     * Strip markdown/emoji-heavy formatting and split a long reply into ≤ N SMS segments
     * with `(n/m) ` prefixes when more than one segment is needed.
     *
     * @return list<string>
     */
    public function format(string $body, ?int $maxSegments = null): array
    {
        $clean = $this->stripMarkdown($body);

        if ($clean === '') {
            return [];
        }

        $maxSegments = max(1, $maxSegments ?? (int) config('services.telnyx.ai_max_segments', 3));

        // Single-segment short path.
        if (mb_strlen($clean) <= self::SEGMENT_BUDGET && $maxSegments === 1) {
            return [$this->truncate($clean, self::SEGMENT_BUDGET)];
        }

        $segments = $this->chunkOnBoundaries($clean, self::SEGMENT_BUDGET);
        $segments = array_slice($segments, 0, $maxSegments);

        if (count($segments) === 1) {
            return [$segments[0]];
        }

        $total = count($segments);

        return array_map(
            fn (int $idx, string $part) => sprintf('(%d/%d) %s', $idx + 1, $total, $part),
            array_keys($segments),
            $segments
        );
    }

    public function stripMarkdown(string $body): string
    {
        $text = (string) $body;

        // Code fences and inline code → drop the markers, keep content.
        $text = preg_replace_callback(
            '/```[\s\S]*?```/u',
            static fn (array $m) => trim($m[0], "` \n"),
            $text
        ) ?? $text;
        $text = preg_replace('/`([^`]+)`/u', '$1', $text) ?? $text;

        // Bold/italic markers.
        $text = preg_replace('/\*\*([^*]+)\*\*/u', '$1', $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/u', '$1', $text) ?? $text;
        $text = preg_replace('/__([^_]+)__/u', '$1', $text) ?? $text;
        $text = preg_replace('/(?<!_)_([^_]+)_(?!_)/u', '$1', $text) ?? $text;

        // Links: keep visible text, drop URL.
        $text = preg_replace('/\[([^\]]+)\]\(([^\)]+)\)/u', '$1 ($2)', $text) ?? $text;

        // Headings: drop leading hashes.
        $text = preg_replace('/^\s{0,3}#{1,6}\s+/mu', '', $text) ?? $text;

        // Bullet markers and blockquotes.
        $text = preg_replace('/^\s*[>\-\*\+]\s+/mu', '- ', $text) ?? $text;

        // Collapse 3+ newlines to 2.
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @return list<string>
     */
    private function chunkOnBoundaries(string $text, int $budget): array
    {
        $segments = [];
        $remaining = $text;

        while (mb_strlen($remaining) > $budget) {
            $window = mb_substr($remaining, 0, $budget);
            $cut = $this->preferredCut($window);
            $segments[] = trim(mb_substr($remaining, 0, $cut));
            $remaining = ltrim(mb_substr($remaining, $cut));
        }

        if ($remaining !== '') {
            $segments[] = trim($remaining);
        }

        return $segments;
    }

    private function preferredCut(string $window): int
    {
        $patterns = [
            '/.*[.!?](?=\s|$)/su',
            '/.*\n/su',
            '/.*[,;:]/su',
            '/.*\s/su',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $window, $match)) {
                $cut = mb_strlen($match[0]);
                if ($cut > 0) {
                    return $cut;
                }
            }
        }

        return mb_strlen($window);
    }

    private function truncate(string $text, int $budget): string
    {
        if (mb_strlen($text) <= $budget) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $budget - 1)) . '…';
    }
}
