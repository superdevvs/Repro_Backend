<?php

namespace Tests\Unit;

use App\Services\ReproAi\IntentRegistry;
use App\Services\ReproAi\IntentScorer;
use PHPUnit\Framework\TestCase;

class IntentScorerTest extends TestCase
{
    public function test_scores_book_shoot(): void
    {
        $scorer = new IntentScorer(new IntentRegistry());
        $result = $scorer->score('Book a new shoot next week');

        $this->assertSame('book_shoot', $result['name']);
        $this->assertGreaterThanOrEqual(1.0, $result['confidence']);
    }

    public function test_scores_manage_booking(): void
    {
        $scorer = new IntentScorer(new IntentRegistry());
        $result = $scorer->score('Please reschedule my booking');

        $this->assertSame('manage_booking', $result['name']);
        $this->assertGreaterThanOrEqual(1.0, $result['confidence']);
    }

    public function test_scores_availability(): void
    {
        $scorer = new IntentScorer(new IntentRegistry());
        $result = $scorer->score('Check availability for next Thursday');

        $this->assertSame('availability', $result['name']);
        $this->assertGreaterThanOrEqual(1.0, $result['confidence']);
    }

    public function test_defaults_to_general(): void
    {
        $scorer = new IntentScorer(new IntentRegistry());
        $result = $scorer->score('Tell me about pricing');

        $this->assertSame('general', $result['name']);
        $this->assertSame(0.0, $result['confidence']);
    }

    public function test_requires_keyword_groups_for_book_shoot(): void
    {
        $scorer = new IntentScorer(new IntentRegistry());
        $result = $scorer->score('I want to book next week');

        $this->assertSame('general', $result['name']);
    }

    public function test_disqualifying_keywords_prevent_book_shoot(): void
    {
        $scorer = new IntentScorer(new IntentRegistry());
        $result = $scorer->score('Book a new shoot and check availability');

        $this->assertSame('availability', $result['name']);
        $this->assertGreaterThanOrEqual(1.0, $result['confidence']);
    }
}
