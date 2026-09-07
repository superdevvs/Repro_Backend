<?php

namespace Tests\Unit\Messaging;

use App\Exceptions\Messaging\EmailProviderRejectedException;
use App\Models\MessageChannel;
use App\Services\Messaging\Providers\CakemailProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CakemailSendRejectionTest extends TestCase
{
    #[DataProvider('providerResponses')]
    public function test_only_explicit_non_acceptance_is_safe_to_retry(?int $status, bool $retryable): void
    {
        config([
            'cache.default' => 'array',
            'services.cakemail.username' => 'rejection-fixture@example.test',
            'services.cakemail.password' => 'synthetic-password',
            'services.cakemail.sender_id' => 'fixture-sender',
            'services.cakemail.base_url' => 'https://cakemail.example.test',
        ]);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($status) {
            if ($request->url() === 'https://cakemail.example.test/token') {
                return Http::response(['access_token' => 'synthetic-token', 'expires_in' => 3600]);
            }
            if ($status === null) {
                throw new ConnectionException('Timed out after the request was transmitted');
            }

            return Http::response(['detail' => 'Provider fixture failure'], $status);
        });

        $provider = new CakemailProvider();
        $provider->clearCache();
        $failure = null;
        try {
            $provider->send(new MessageChannel(['provider' => 'CAKEMAIL', 'config_json' => []]), [
                'to' => 'photographer@example.test',
                'subject' => 'Scheduled shoot',
                'html' => '<p>Shoot scheduled.</p>',
            ]);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        $this->assertNotNull($failure, 'The provider failure must be surfaced.');
        $this->assertSame($retryable, $failure instanceof EmailProviderRejectedException);
    }

    public static function providerResponses(): array
    {
        return [
            'rate limit rejection' => [429, true],
            'invalid request rejection' => [422, true],
            'server error with unknown outcome' => [500, false],
            'request timeout with unknown outcome' => [408, false],
            'connection timeout with unknown outcome' => [null, false],
        ];
    }
}
