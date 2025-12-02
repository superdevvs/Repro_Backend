<?php

namespace App\Services\Messaging\Contracts;

use App\Models\MessageChannel;
use App\Models\SmsNumber;

interface SmsProviderInterface
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(SmsNumber $number, array $payload): string;
}





