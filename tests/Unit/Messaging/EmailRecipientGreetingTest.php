<?php

namespace Tests\Unit\Messaging;

use App\Services\Messaging\EmailRecipientGreeting;
use PHPUnit\Framework\TestCase;

class EmailRecipientGreetingTest extends TestCase
{
    public function test_new_greetings_replace_named_salutations_without_discarding_the_letter(): void
    {
        $greeting = new EmailRecipientGreeting;
        foreach (['Hi Jamie,', 'Dear Dr. Rivera,', 'Hello [First Name],', '{{greeting}},'] as $opening) {
            $this->assertSame("Hi {{recipient_first_name}},\n\nThank you for your work.", $greeting->forNewDraft($opening."\n\nThank you for your work."));
        }
    }

    public function test_introductory_sentences_are_not_mistaken_for_standalone_salutations(): void
    {
        $greeting = new EmailRecipientGreeting;
        foreach (['Hello and welcome to your new dashboard', 'Hello and welcome to your new dashboard!', 'Hello and welcome to your new dashboard:', 'Hi Alex thanks for your photos', 'Hi Alex thanks for your photos!'] as $intro) {
            $this->assertSame("Hi {{recipient_first_name}},\n\n".$intro, $greeting->forNewDraft($intro));
        }
    }

    public function test_inline_salutations_replace_only_the_name_prefix_and_preserve_the_sentence(): void
    {
        $greeting = new EmailRecipientGreeting;
        $this->assertSame("Hi {{recipient_first_name}},\n\nthanks for your photos.\n\nMore details.", $greeting->forNewDraft("Hi Jamie, thanks for your photos.\n\nMore details."));
        $this->assertSame("Dear {{recipient_first_name}},\n\nthanks for your photos.", $greeting->preserveOpening("Dear {{recipient_first_name}},\n\nOld copy.", 'Hi Jamie, thanks for your photos.'));
    }

    public function test_generic_greetings_and_no_greeting_are_distinct_explicit_preferences(): void
    {
        $greeting = new EmailRecipientGreeting;
        $body = "Hello team,\n\nThank you for your work.";
        $this->assertSame($body, $greeting->forNewDraft($body, 'Use a generic greeting.'));
        $this->assertSame($body, $greeting->forNewDraft($body, 'Do not personalize this message.'));
        $this->assertSame("Hi there,\n\nThank you.", $greeting->forNewDraft("Hi {{recipient_first_name}},\n\nThank you.", 'Use a generic greeting.'));
        $this->assertSame('Thank you for your work.', $greeting->forNewDraft($body, 'Do not include a greeting.'));
        $this->assertFalse($greeting->requestedWithoutGreeting('Use a generic greeting.'));
    }
}
