<?php

namespace App\Services\Messaging;

class SmsReactionParser
{
    /**
     * Parse only a complete, known SMS Tapback envelope. Keep the quoted text
     * intact so it cannot attach to a different message through fuzzy matching.
     *
     * @return array{emoji: string, label: string, action: string, targetMessageId: null, quotedMessage: string}|null
     */
    public function parse(string $body): ?array
    {
        $prefixes = [
            'Liked' => ['👍', 'Like', 'add'],
            'Loved' => ['❤️', 'Love', 'add'],
            'Laughed at' => ['😂', 'Laugh', 'add'],
            'Disliked' => ['👎', 'Dislike', 'add'],
            'Emphasized' => ['‼️', 'Emphasis', 'add'],
            'Questioned' => ['❓', 'Question', 'add'],
            'A aprobat' => ['👍', 'Like', 'add'],
            'Removed a like from' => ['👍', 'Like', 'remove'],
            'Removed a heart from' => ['❤️', 'Love', 'remove'],
            'Removed a laugh from' => ['😂', 'Laugh', 'remove'],
            'Removed a dislike from' => ['👎', 'Dislike', 'remove'],
            'Removed an exclamation from' => ['‼️', 'Emphasis', 'remove'],
            'Removed a question mark from' => ['❓', 'Question', 'remove'],
        ];
        $quotes = ['"' => '"', '“' => '”', '„' => '”', '«' => '»'];
        $body = trim($body);

        foreach ($prefixes as $prefix => [$emoji, $label, $action]) {
            foreach ($quotes as $open => $close) {
                $start = $prefix.' '.$open;
                if (! str_starts_with($body, $start) || ! str_ends_with($body, $close)) {
                    continue;
                }
                $quoted = substr($body, strlen($start), -strlen($close));
                if ($quoted === '' || trim($quoted) === '') {
                    return null;
                }

                return [
                    'emoji' => $emoji,
                    'label' => $label,
                    'action' => $action,
                    'targetMessageId' => null,
                    'quotedMessage' => $quoted,
                ];
            }
        }

        return null;
    }
}
