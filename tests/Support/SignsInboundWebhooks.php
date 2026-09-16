<?php

namespace Tests\Support;

trait SignsInboundWebhooks
{
    protected function postJsonWithHmac(
        string $uri,
        array $payload,
        string $secret,
        string $headerName
    ) {
        $signature = hash_hmac('sha256', json_encode($payload), $secret);

        return $this->withHeader($headerName, $signature)->postJson($uri, $payload);
    }
}
