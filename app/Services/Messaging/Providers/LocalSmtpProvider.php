<?php

namespace App\Services\Messaging\Providers;

use App\Models\MessageChannel;
use App\Services\Messaging\Contracts\EmailProviderInterface;
use Illuminate\Support\Facades\Log;

/**
 * Legacy SMTP provider — now delegates to CakemailProvider.
 * SMTP is permanently disabled; all email goes through the CakeMail REST API.
 */
class LocalSmtpProvider implements EmailProviderInterface
{
    public function send(MessageChannel $channel, array $payload): string
    {
        Log::info('LocalSmtpProvider: delegating to CakemailProvider (SMTP disabled)');
        return app(CakemailProvider::class)->send($channel, $payload);
    }

    public function schedule(MessageChannel $channel, array $payload): string
    {
        return $this->send($channel, $payload);
    }
}





