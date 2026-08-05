<?php

namespace App\Services\ReproAi;

class IntentPolicy
{
    /**
     * @var array<string, array<string, float>>
     */
    private array $ruleBasedIntents = [
        'book_shoot' => [
            'min_confidence' => 1.0,
        ],
        'manage_booking' => [
            'min_confidence' => 1.0,
        ],
        'availability' => [
            'min_confidence' => 1.0,
        ],
        'edit_photos' => [
            'min_confidence' => 1.0,
        ],
        'support_faq' => [
            'min_confidence' => 1.0,
        ],
    ];

    public function isRuleBased(string $intent, ?float $confidence = null): bool
    {
        if (!isset($this->ruleBasedIntents[$intent])) {
            return false;
        }

        $minConfidence = $this->ruleBasedIntents[$intent]['min_confidence'] ?? 0.0;
        if ($confidence !== null && $confidence < $minConfidence) {
            return false;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function ruleBasedIntents(): array
    {
        return array_keys($this->ruleBasedIntents);
    }
}
