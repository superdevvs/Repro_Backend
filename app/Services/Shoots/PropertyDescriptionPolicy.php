<?php

namespace App\Services\Shoots;

use App\Models\Shoot;

class PropertyDescriptionPolicy
{
    public const TIER_STANDARD = 'standard';

    public const STANDARD_PROPERTY_DESCRIPTION_MAX_CHARACTERS = 650;

    private const CHARACTER_LIMITS = [
        self::TIER_STANDARD => self::STANDARD_PROPERTY_DESCRIPTION_MAX_CHARACTERS,
    ];

    public function tierFor(Shoot $shoot): string
    {
        // Resolve the owning client's paid tier here once subscriptions are introduced.
        return self::TIER_STANDARD;
    }

    public function maxCharactersFor(Shoot $shoot): int
    {
        return self::CHARACTER_LIMITS[$this->tierFor($shoot)]
            ?? self::STANDARD_PROPERTY_DESCRIPTION_MAX_CHARACTERS;
    }

    public function enforceCharacterLimit(string $description, int $characterLimit): string
    {
        $description = trim($description);
        if ($description === '' || mb_strlen($description) <= $characterLimit) {
            return $description;
        }

        $window = rtrim(mb_substr($description, 0, $characterLimit));
        $minimumSentenceLength = (int) floor($characterLimit * 0.7);

        if (
            preg_match('/^.*[.!?](?=\s|$)/us', $window, $sentenceMatch) === 1
            && mb_strlen($sentenceMatch[0]) >= $minimumSentenceLength
        ) {
            return rtrim($sentenceMatch[0]);
        }

        if (preg_match('/^.*\s/us', $window, $wordMatch) === 1) {
            $wordBoundary = rtrim($wordMatch[0]);
            if ($wordBoundary !== '') {
                return $wordBoundary.'…';
            }
        }

        return rtrim(mb_substr($description, 0, $characterLimit - 1)).'…';
    }
}
