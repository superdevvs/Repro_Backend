<?php

namespace Tests\Unit\Messaging;

use App\Services\Messaging\SmsReactionParser;
use PHPUnit\Framework\TestCase;

class SmsReactionParserTest extends TestCase
{
    public function test_known_reactions_and_quote_styles_preserve_the_full_quoted_body(): void
    {
        $parser = new SmsReactionParser;
        foreach ([
            ['Liked "Hello"', '👍', 'Like', 'add', 'Hello'],
            ['Loved “Hello”', '❤️', 'Love', 'add', 'Hello'],
            ['Laughed at «Hello»', '😂', 'Laugh', 'add', 'Hello'],
            ['Disliked "Hello"', '👎', 'Dislike', 'add', 'Hello'],
            ['Emphasized "Hello"', '‼️', 'Emphasis', 'add', 'Hello'],
            ['Questioned "Hello"', '❓', 'Question', 'add', 'Hello'],
            ["A aprobat „Shoot reminder\nDetails: https://reprodashboard.com/shoots/2474”", '👍', 'Like', 'add', "Shoot reminder\nDetails: https://reprodashboard.com/shoots/2474"],
            ['Removed a like from "Hello"', '👍', 'Like', 'remove', 'Hello'],
            ['Removed a heart from "Hello"', '❤️', 'Love', 'remove', 'Hello'],
            ['Removed a laugh from "Hello"', '😂', 'Laugh', 'remove', 'Hello'],
            ['Removed a dislike from "Hello"', '👎', 'Dislike', 'remove', 'Hello'],
            ['Removed an exclamation from "Hello"', '‼️', 'Emphasis', 'remove', 'Hello'],
            ['Removed a question mark from "Hello"', '❓', 'Question', 'remove', 'Hello'],
        ] as [$body, $emoji, $label, $action, $quoted]) {
            $this->assertSame([
                'emoji' => $emoji,
                'label' => $label,
                'action' => $action,
                'targetMessageId' => null,
                'quotedMessage' => $quoted,
            ], $parser->parse($body), $body);
        }
    }

    public function test_ordinary_unknown_incomplete_or_mismatched_envelopes_stay_text(): void
    {
        $parser = new SmsReactionParser;
        foreach ([
            'I liked "Hello"',
            'Liked your message',
            'Liked "Hello" and will be there',
            'Approved "Hello"',
            'A aprobat planul pentru maine',
            'Liked “Hello"',
            'Liked ""',
            'Liked "   "',
            'Loved “Hello',
        ] as $body) {
            $this->assertNull($parser->parse($body), $body);
        }
    }
}
