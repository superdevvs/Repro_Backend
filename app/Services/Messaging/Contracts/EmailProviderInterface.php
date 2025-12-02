<?php

namespace App\Services\Messaging\Contracts;

use App\Models\Message;
use App\Models\MessageChannel;

interface EmailProviderInterface
{
    /**
     * Send an email via the provider and return the provider's response identifier.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(MessageChannel $channel, array $payload): string;

    /**
     * Optional hook to schedule deliveries.
     *
     * @param  array<string, mixed>  $payload
     */
    public function schedule(MessageChannel $channel, array $payload): string;
}





