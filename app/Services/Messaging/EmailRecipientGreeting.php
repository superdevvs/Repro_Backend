<?php

namespace App\Services\Messaging;

/** Plain-text greetings shared by Robbie's compose and template drafts. */
class EmailRecipientGreeting
{
    public function hasOpening(string $body): bool
    {
        return $this->splitOpening($body)[0] !== null;
    }

    public function requestedWithoutGreeting(?string $instruction): bool
    {
        return preg_match('/\b(?:no|without|omit|skip|remove)\s+(?:(?:a|the|any)\s+){0,3}(?:greeting|salutation)\b|\b(?:do not|don[\x{2019}\x{0027}]t)\s+(?:add|include|use)\s+(?:(?:a|the|any)\s+){0,2}(?:greeting|salutation)\b/iu', (string) $instruction) === 1;
    }

    public function requestedGenericGreeting(?string $instruction): bool
    {
        return preg_match('/\b(?:generic|unpersonali[sz]ed|non[- ]personali[sz]ed)\s+(?:greeting|salutation)\b|\b(?:do not|don[\x{2019}\x{0027}]t)\s+personali[sz]e\b|\b(?:no|without|omit|skip)\s+personali[sz]ation\b/iu', (string) $instruction) === 1;
    }

    public function forNewDraft(string $body, ?string $instruction = null): string
    {
        [$opening, $content] = $this->splitOpening($body);
        if ($this->requestedWithoutGreeting($instruction)) {
            return $content;
        }
        if ($this->requestedGenericGreeting($instruction)) {
            if ($opening !== null && preg_match('/\{\{\s*(?:recipient_(?:first_name|last_name|name)|greeting)\s*\}\}|\[(?:recipient_(?:first_name|last_name|name)|greeting|First Name)\]/', $opening)) {
                return 'Hi there,'.($content !== '' ? "\n\n".ltrim($content) : '');
            }

            return $body;
        }

        return 'Hi {{recipient_first_name}},'.($content !== '' ? "\n\n".ltrim($content) : '');
    }

    public function preserveOpening(string $original, string $rewritten): string
    {
        [$greeting] = $this->splitOpening($original);
        if ($greeting === null) {
            return $rewritten;
        }
        [, $content] = $this->splitOpening($rewritten);

        return $greeting.($content !== '' ? "\n\n".ltrim($content) : '');
    }

    /** @return array{?string, string} */
    private function splitOpening(string $body): array
    {
        $parts = preg_split('/\r\n|\r|\n/', ltrim($body), 2);
        $line = trim($parts[0] ?? '');
        if (preg_match('/^(?:hi|hello|hey|dear|\{\{\s*greeting\s*\}\}|\[greeting\])[,!:]?$/iu', $line)) {
            return [$line, ltrim($parts[1] ?? '')];
        }
        if (preg_match('/^((?:hi|hello|hey|dear)[ \t]+([^,;:!?\r\n]{1,80})[,!:])[ \t]*(.*)$/iu', $line, $match) !== 1) {
            return [null, $body];
        }
        $addressee = preg_replace('/\b(?:Dr|Mr|Mrs|Ms|Prof)\./i', '', $match[2]);
        $addressee = trim(preg_replace('/\{\{\s*[a-z_]+\s*\}\}|\[[^\]]+\]/i', 'Name', $addressee));
        // A short name/team salutation may be replaced. Introductory sentences
        // such as "Hello and welcome to your new dashboard!" remain content.
        if (count(preg_split('/\s+/', $addressee)) > 4 || preg_match('/[.]|\b(?:and|welcome|thanks?|please|your|you|we)\b/iu', $addressee)) {
            return [null, $body];
        }
        // Preserve prose after an inline salutation instead of retaining an
        // invented first name in "Hi Jamie, thanks for your photos.".
        $content = $match[3].(isset($parts[1]) ? "\n".$parts[1] : '');

        return [$match[1], ltrim($content)];
    }
}
