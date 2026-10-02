<?php

namespace App\Services\Messaging;

use App\Models\Message;
use App\Services\Messaging\Providers\CakemailProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class EmailActivityService
{
    public function forMessage(Message $message): array
    {
        $metadata = (array) $message->metadata;
        $events = (array) ($metadata['email_activity'] ?? []);
        $available = true;
        $providerLogs = false;
        if ($message->provider === 'CAKEMAIL' && $message->provider_message_id) {
            try {
                $logs = Cache::remember('email-activity:'.$message->id.':'.$message->provider_message_id, 30,
                    fn () => app(CakemailProvider::class)->getLogs(['email_id' => $message->provider_message_id, 'per_page' => 100]));
                $providerLogs = true;
                $events = [];
                foreach ($logs as $index => $log) {
                    // Ignore unrelated rows even if the upstream filter misbehaves.
                    if (($log['email_id'] ?? null) !== $message->provider_message_id) {
                        continue;
                    }
                    $events[] = [
                        'id' => (string) ($log['id'] ?? 'cake-'.$index),
                        'type' => strtolower((string) ($log['type'] ?? 'unknown')),
                        'at' => $this->timestamp($log['time'] ?? null),
                        'detail' => $this->detail(data_get($log, 'metadata.reason')),
                        'link' => $this->safeLink(data_get($log, 'metadata.link') ?? data_get($log, 'metadata.url')),
                    ];
                }
            } catch (\Exception) {
                $available = false;
            }
        }
        $events = array_values(array_filter($events, fn ($event) => is_array($event) && isset($event['type'], $event['at'])));
        foreach (['sent' => $message->sent_at, 'delivered' => $message->delivered_at, 'failed' => $message->failed_at,
            'opened' => $metadata['opened_at'] ?? null, 'clicked' => $metadata['clicked_at'] ?? null] as $type => $at) {
            if ($at && ! collect($events)->contains('type', $type)) {
                $events[] = ['id' => 'stored-'.$type, 'type' => $type, 'at' => $this->timestamp($at),
                    'detail' => $type === 'failed' ? $this->detail($message->error_message) : null, 'link' => null];
            }
        }
        usort($events, fn ($a, $b) => (strtotime($a['at'] ?? '') ?: 0) <=> (strtotime($b['at'] ?? '') ?: 0));
        $status = $message->status;
        foreach ($events as $event) {
            $status = match ($event['type']) {
                'bounced', 'bounce' => 'BOUNCED',
                'complained', 'spam' => 'COMPLAINED',
                'failed', 'rejected', 'suppressed' => 'FAILED',
                'delivered' => in_array($status, ['BOUNCED', 'COMPLAINED', 'FAILED'], true) ? $status : 'DELIVERED',
                default => $status,
            };
        }
        $count = fn ($type) => count(array_filter($events, fn ($event) => $event['type'] === $type));

        return [
            'provider' => $message->provider, 'provider_message_id' => $message->provider_message_id,
            'status' => $status, 'provider_logs_available' => $available,
            'open_count' => $providerLogs ? $count('opened') : max($count('opened'), (int) ($metadata['open_count'] ?? 0)),
            'click_count' => $providerLogs ? $count('clicked') : max($count('clicked'), (int) ($metadata['click_count'] ?? 0)),
            'events' => array_slice(array_map(fn ($event) => [
                'id' => (string) ($event['id'] ?? ''), 'type' => (string) $event['type'], 'at' => $event['at'],
                'detail' => $this->detail($event['detail'] ?? null), 'link' => $this->safeLink($event['link'] ?? null),
            ], $events), -100),
        ];
    }

    public function timestamp(mixed $value): ?string
    {
        try {
            return $value === null ? null : (is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : Carbon::parse($value))->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    public function detail(mixed $value): ?string
    {
        return is_string($value) ? mb_substr(strip_tags($value), 0, 500) : null;
    }

    public function safeLink(mixed $value): ?string
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($value);
        if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }

        // Tracking links can contain authentication tokens; show only host and path.
        return $parts['scheme'].'://'.$parts['host'].($parts['path'] ?? '/');
    }
}
