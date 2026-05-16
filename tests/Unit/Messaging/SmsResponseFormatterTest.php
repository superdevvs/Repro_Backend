<?php

namespace Tests\Unit\Messaging;

use App\Services\Messaging\AiSms\SmsResponseFormatter;
use Tests\TestCase;

class SmsResponseFormatterTest extends TestCase
{
    public function test_strips_markdown_formatting(): void
    {
        $formatter = new SmsResponseFormatter();

        $input = "**Hello** _world_!\n\n# Heading\n- item 1\n- item 2\n\nVisit [our site](https://example.com).";
        $clean = $formatter->stripMarkdown($input);

        $this->assertStringNotContainsString('**', $clean);
        $this->assertStringNotContainsString('# ', $clean);
        $this->assertStringContainsString('Hello world', $clean);
        $this->assertStringContainsString('our site', $clean);
        $this->assertStringContainsString('https://example.com', $clean);
    }

    public function test_short_message_returns_single_segment(): void
    {
        $formatter = new SmsResponseFormatter();
        $segments = $formatter->format('Hi! Reply YES to confirm.');

        $this->assertCount(1, $segments);
        $this->assertSame('Hi! Reply YES to confirm.', $segments[0]);
    }

    public function test_long_message_splits_into_capped_segments_with_prefixes(): void
    {
        $formatter = new SmsResponseFormatter();

        config()->set('services.telnyx.ai_max_segments', 3);

        // Force a 5000+ char body; expect 3 segments with `(n/3) ` prefixes.
        $body = str_repeat('Sentence one. Sentence two. Sentence three. ', 200);
        $segments = $formatter->format($body);

        $this->assertGreaterThan(1, count($segments));
        $this->assertLessThanOrEqual(3, count($segments));

        $total = count($segments);
        foreach ($segments as $idx => $seg) {
            $expectedPrefix = sprintf('(%d/%d) ', $idx + 1, $total);
            $this->assertStringStartsWith($expectedPrefix, $seg);
        }
    }

    public function test_empty_input_yields_no_segments(): void
    {
        $formatter = new SmsResponseFormatter();
        $this->assertSame([], $formatter->format(''));
        $this->assertSame([], $formatter->format("\n\n  \n"));
    }
}
