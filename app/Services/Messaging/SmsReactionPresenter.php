<?php

namespace App\Services\Messaging;

use App\Models\Message;
use App\Models\MessageThread;
use Illuminate\Database\Eloquent\Collection;

class SmsReactionPresenter
{
    private const MAX_LOOKUP_KEYS = 100;

    private const MAX_CANDIDATES = 2000;

    public function __construct(private readonly SmsReactionParser $parser) {}

    /**
     * Read-only presentation of reaction events and their actual sent messages.
     * Duplicate contact threads are matched only by both telephone endpoints and
     * an exact quoted body, never by contact name or contact identity.
     *
     * @param  Collection<int, Message>  $messages
     * @return Collection<int, Message>
     */
    public function present(MessageThread $thread, Collection $messages): Collection
    {
        $events = [];
        $lookupKeys = [];
        foreach ($messages as $message) {
            if ($message->channel !== 'SMS' || $message->direction !== 'INBOUND') {
                continue;
            }
            $reaction = $this->parser->parse((string) $message->body_text);
            if ($reaction === null) {
                continue;
            }

            $target = $this->target($messages, $message, $reaction['quotedMessage']);
            if ($target !== null) {
                $reaction['targetMessageId'] = (string) $target->id;
                $message->setAttribute('_sms_reaction', $reaction);

                continue;
            }
            $message->setAttribute('_sms_reaction', $reaction);
            $events[] = [$message, $reaction];
            $from = $this->phone((string) $message->from_address);
            $to = $this->phone((string) $message->to_address);
            if ($from !== null && $to !== null) {
                $lookupKeys[json_encode([$from, $to, $reaction['quotedMessage']])] = [$from, $to, $reaction['quotedMessage']];
            }
        }

        if ($events === []) {
            return $messages;
        }

        // One bounded query for the entire history; excess/ambiguous events
        // retain the explicit quoted-message fallback instead of guessing.
        $external = new Collection;
        if (! $thread->sms_group_id && $lookupKeys !== []) {
            $external = Message::query()
                ->where('channel', 'SMS')
                ->where('direction', 'OUTBOUND')
                ->where('thread_id', '!=', $thread->id)
                ->whereHas('thread', fn ($query) => $query->where('channel', 'SMS')->whereNull('sms_group_id'))
                ->where('created_at', '<=', $messages->max('created_at'))
                ->where(function ($query) use ($lookupKeys) {
                    foreach (array_slice(array_values($lookupKeys), 0, self::MAX_LOOKUP_KEYS) as [$from, $to, $body]) {
                        $query->orWhere(function ($pair) use ($from, $to, $body) {
                            $pair->where('body_text', $body)
                                ->whereRaw($this->phoneSql('from_address').' = ?', [$to])
                                ->whereRaw($this->phoneSql('to_address').' = ?', [$from]);
                        });
                    }
                })
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(self::MAX_CANDIDATES)
                ->get();
        }

        $included = [];
        foreach ($events as [$message, $reaction]) {
            $target = $this->target($external, $message, $reaction['quotedMessage']);
            if ($target === null) {
                continue;
            }
            $reaction['targetMessageId'] = (string) $target->id;
            $message->setAttribute('_sms_reaction', $reaction);
            if ((string) $target->thread_id !== (string) $thread->id) {
                $included[(string) $target->id] = $target;
            }
        }

        return $messages->concat(array_values($included))
            ->sort(function (Message $left, Message $right) {
                $time = $left->created_at <=> $right->created_at;

                return $time !== 0 ? $time : $left->id <=> $right->id;
            })->values();
    }

    /** @param Collection<int, Message> $candidates */
    private function target(Collection $candidates, Message $event, string $quoted): ?Message
    {
        $from = $this->phone((string) $event->from_address);
        $to = $this->phone((string) $event->to_address);
        if ($from === null || $to === null) {
            return null;
        }

        return $candidates->filter(function (Message $candidate) use ($event, $quoted, $from, $to) {
            return $candidate->channel === 'SMS'
                && $candidate->direction === 'OUTBOUND'
                && $candidate->body_text === $quoted
                && $this->phone((string) $candidate->from_address) === $to
                && $this->phone((string) $candidate->to_address) === $from
                && ($candidate->created_at < $event->created_at
                    || ($candidate->created_at == $event->created_at && $candidate->id < $event->id));
        })->sort(function (Message $left, Message $right) {
            $time = $right->created_at <=> $left->created_at;

            return $time !== 0 ? $time : $right->id <=> $left->id;
        })->first();
    }

    private function phone(string $phone): ?string
    {
        // Restrict accepted punctuation to the same set as the portable SQL
        // normalization below. Non-phone addresses never qualify for matching.
        if (! preg_match('/^[+0-9 ().-]+$/D', $phone)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 10) {
            $digits = '1'.$digits;
        }

        return strlen($digits) >= 11 && strlen($digits) <= 15 ? $digits : null;
    }

    private function phoneSql(string $column): string
    {
        $digits = $column;
        foreach (['+', ' ', '(', ')', '.', '-'] as $character) {
            $digits = "replace({$digits}, '{$character}', '')";
        }

        return "(case when length({$digits}) = 10 then '1' || {$digits} else {$digits} end)";
    }
}
