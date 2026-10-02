<?php

namespace App\Services\Messaging\Providers;

use App\Exceptions\Messaging\EmailProviderRejectedException;
use App\Models\MessageChannel;
use App\Services\Messaging\Contracts\EmailProviderInterface;
use Illuminate\Support\Facades\Log;

class ResendWithCakemailFallbackProvider implements EmailProviderInterface
{
    public string $lastProvider = 'RESEND';

    public ?string $fallbackReason = null;

    public function __construct(private readonly ResendProvider $primary, private readonly CakemailProvider $fallback) {}

    public function send(MessageChannel $channel, array $payload): string
    {
        $this->lastProvider = 'RESEND';
        $this->fallbackReason = null;
        try {
            return $this->primary->send($channel, $payload);
        } catch (EmailProviderRejectedException $exception) {
            $this->lastProvider = 'CAKEMAIL';
            $this->fallbackReason = $exception->getMessage();
            Log::error('Resend rejected email; using CakeMail fallback.', [
                'channel_id' => $channel->id,
                'message_key' => $payload['idempotency_key'] ?? null,
                'reason' => $this->fallbackReason,
            ]);

            return $this->fallback->send($channel, $payload);
        }
    }

    public function schedule(MessageChannel $channel, array $payload): string
    {
        return $this->send($channel, $payload);
    }
}
