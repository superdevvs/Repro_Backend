<?php

namespace App\Services\ReproAi;

class IntentScorer
{
    public function __construct(private IntentRegistry $registry)
    {
    }

    /**
     * @return array{name:string,score:float,confidence:float,matched:array<int,string>}
     */
    public function score(string $message): array
    {
        $normalized = $this->normalize($message);
        $intents = $this->registry->all();

        $best = [
            'name' => 'general',
            'score' => 0.0,
            'confidence' => 0.0,
            'matched' => [],
        ];

        foreach ($intents as $intent) {
            $score = 0.0;
            $matched = [];
            $keywords = $intent['keywords'] ?? [];
            $negatives = $intent['negative_keywords'] ?? [];
            $requiredGroups = $intent['required_groups'] ?? [];
            $disqualifying = $intent['disqualifying_keywords'] ?? [];
            $minKeywordMatches = (int) ($intent['min_keyword_matches'] ?? 0);

            if (!empty($requiredGroups)) {
                $allGroupsMatched = true;
                foreach ($requiredGroups as $group) {
                    $groupMatched = false;
                    foreach ((array) $group as $term) {
                        if ($this->contains($normalized, (string) $term)) {
                            $groupMatched = true;
                            break;
                        }
                    }
                    if (!$groupMatched) {
                        $allGroupsMatched = false;
                        break;
                    }
                }

                if (!$allGroupsMatched) {
                    continue;
                }
            }

            if (!empty($disqualifying)) {
                $hasDisqualifier = false;
                foreach ($disqualifying as $term) {
                    if ($this->contains($normalized, (string) $term)) {
                        $hasDisqualifier = true;
                        break;
                    }
                }
                if ($hasDisqualifier) {
                    continue;
                }
            }

            foreach ($keywords as $keyword => $weight) {
                if ($this->contains($normalized, $keyword)) {
                    $score += (float) $weight;
                    $matched[] = $keyword;
                }
            }

            foreach ($negatives as $keyword) {
                if ($this->contains($normalized, $keyword)) {
                    $score -= 0.4;
                }
            }

            if ($minKeywordMatches > 0 && count($matched) < $minKeywordMatches) {
                continue;
            }

            $minConfidence = (float) ($intent['min_confidence'] ?? 1.0);
            $confidence = $minConfidence > 0 ? min(1.0, $score / $minConfidence) : 0.0;

            if ($score > $best['score']) {
                $best = [
                    'name' => $intent['name'],
                    'score' => $score,
                    'confidence' => $confidence,
                    'matched' => $matched,
                ];
            }
        }

        return $best;
    }

    private function normalize(string $message): string
    {
        $normalized = strtolower(trim($message));
        $normalized = preg_replace('/[^a-z0-9\s]/', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return $normalized ?? '';
    }

    private function contains(string $haystack, string $needle): bool
    {
        return $needle !== '' && str_contains($haystack, $needle);
    }
}
