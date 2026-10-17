<?php

namespace App\Services\Messaging;

use Illuminate\Database\Eloquent\Builder;
use PDO;

class EmailMessageSearch
{
    public function apply(Builder $query, string $search): void
    {
        $search = trim($search);
        if ($search === '') {
            return;
        }

        $sqlite = $query->getConnection()->getDriverName() === 'sqlite';
        if ($sqlite) {
            // Historical HTML-only messages have no plain-text body to search.
            // This connection-local function leaves persisted messages untouched.
            $query->getConnection()->getPdo()->sqliteCreateFunction(
                'messaging_search_text',
                self::searchableText(...),
                2,
                PDO::SQLITE_DETERMINISTIC,
            );
        }

        $pattern = '%'.addcslashes(self::searchableText($search, 0), '%_\\').'%';
        $query->where(function (Builder $match) use ($pattern, $sqlite) {
            foreach (['subject', 'body_text', 'from_address', 'to_address', 'sender_display_name', 'cc_addresses_json', 'bcc_addresses_json'] as $column) {
                $expression = $sqlite ? "messaging_search_text(messages.{$column}, 0)" : "LOWER(messages.{$column})";
                $match->orWhereRaw("{$expression} LIKE ? ESCAPE '\\'", [$pattern]);
            }

            $html = $sqlite ? 'messaging_search_text(messages.body_html, 1)' : 'LOWER(messages.body_html)';
            $match->orWhereRaw("{$html} LIKE ? ESCAPE '\\'", [$pattern])
                ->orWhereHas('thread.contact', function (Builder $contact) use ($pattern, $sqlite) {
                    $contact->where(function (Builder $identity) use ($pattern, $sqlite) {
                        foreach (['name', 'email'] as $column) {
                            $expression = $sqlite ? "messaging_search_text(contacts.{$column}, 0)" : "LOWER(contacts.{$column})";
                            $identity->orWhereRaw("{$expression} LIKE ? ESCAPE '\\'", [$pattern]);
                        }
                    });
                })
                ->orWhereHas('creator', function (Builder $sender) use ($pattern, $sqlite) {
                    $sender->where(function (Builder $identity) use ($pattern, $sqlite) {
                        foreach (['name', 'email'] as $column) {
                            $expression = $sqlite ? "messaging_search_text(users.{$column}, 0)" : "LOWER(users.{$column})";
                            $identity->orWhereRaw("{$expression} LIKE ? ESCAPE '\\'", [$pattern]);
                        }
                    });
                });
        });
    }

    private static function searchableText(?string $value, int $html): string
    {
        $text = $value ?? '';
        if ($html) {
            $text = preg_replace('~<(head|style|script)\b[^>]*>.*?</\1\s*>~is', ' ', $text) ?? $text;
            $text = preg_replace('~<(?:br\b[^>]*|/(?:p|div|li|tr|h[1-6]|section))\s*>~i', ' ', $text) ?? $text;
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 'UTF-8');
    }
}
