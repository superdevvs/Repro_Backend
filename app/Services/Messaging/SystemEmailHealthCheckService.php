<?php

namespace App\Services\Messaging;

use App\Models\MessageChannel;
use App\Services\Messaging\Providers\CakemailProvider;
use Illuminate\Support\Str;

class SystemEmailHealthCheckService
{
    public function __construct(
        private readonly CakemailProvider $provider,
    ) {
    }

    /**
     * @return array{
     *   healthy: bool,
     *   provider: string,
     *   failure_type: string|null,
     *   checks: array{
     *     default_channel: array{
     *       success: bool,
     *       channel_id: int|null,
     *       provider: string|null,
     *       from_email: string|null
     *     },
     *     provider_connection: array{
     *       success: bool,
     *       error: string|null,
     *       account_name: string|null,
     *       sender_count: int,
     *       list_count: int
     *     },
     *     send_capability: array{
     *       success: bool,
     *       reason: string|null
     *     }
     *   }
     * }
     */
    public function inspect(): array
    {
        $channel = MessageChannel::query()
            ->where('type', 'EMAIL')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        $channelCheck = [
            'success' => $channel !== null,
            'channel_id' => $channel?->id,
            'provider' => $channel?->provider,
            'from_email' => $channel?->from_email,
        ];

        $connection = $this->provider->testConnection();
        $failureType = $this->resolveFailureType($connection['error'] ?? null, $channel);
        $sendCapability = [
            'success' => $channel !== null
                && filter_var((string) $channel->from_email, FILTER_VALIDATE_EMAIL) !== false
                && ($connection['success'] ?? false) === true,
            'reason' => $channel === null
                ? 'missing_default_channel'
                : (filter_var((string) $channel->from_email, FILTER_VALIDATE_EMAIL) === false
                    ? 'invalid_default_from_email'
                    : (($connection['success'] ?? false) === true ? null : 'provider_not_ready')),
        ];

        $healthy = $channelCheck['success'] && ($connection['success'] ?? false) === true && $sendCapability['success'];

        return [
            'healthy' => $healthy,
            'provider' => strtoupper((string) ($channel?->provider ?? 'CAKEMAIL')),
            'failure_type' => $healthy ? null : $failureType,
            'checks' => [
                'default_channel' => $channelCheck,
                'provider_connection' => [
                    'success' => (bool) ($connection['success'] ?? false),
                    'error' => $connection['error'] ?? null,
                    'account_name' => data_get($connection, 'account.name'),
                    'sender_count' => is_countable($connection['senders'] ?? null) ? count($connection['senders']) : 0,
                    'list_count' => is_countable($connection['lists'] ?? null) ? count($connection['lists']) : 0,
                ],
                'send_capability' => $sendCapability,
            ],
        ];
    }

    public function statusCode(array $summary): int
    {
        return ($summary['healthy'] ?? false) ? 200 : 503;
    }

    private function resolveFailureType(?string $error, ?MessageChannel $channel): string
    {
        if (!$channel) {
            return 'missing_config';
        }

        $normalized = Str::lower((string) $error);

        if ($normalized === '') {
            return 'provider_connectivity';
        }

        if (Str::contains($normalized, ['not configured', 'invalid absolute url', 'invalid default from', 'missing_default_channel'])) {
            return 'missing_config';
        }

        if (Str::contains($normalized, ['authenticate', 'credentials', 'unauthorized', 'forbidden'])) {
            return 'authentication';
        }

        return 'provider_connectivity';
    }
}
