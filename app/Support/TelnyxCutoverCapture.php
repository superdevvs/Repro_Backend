<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Standalone HTTP adapter. No Laravel route, middleware or service activates it.
 * Retry behavior must be verified before activation: store failures, including
 * cryptographic rejection of well-shaped headers, receive a generic 503.
 */
final class TelnyxCutoverCapture
{
    public const MAX_BODY_BYTES = 2_097_152;

    private const DESTINATIONS = [
        '/api/webhooks/telnyx/messaging' => 'messaging',
        '/api/webhooks/telnyx/status' => 'status',
        '/api/webhooks/telnyx/voice' => 'voice',
    ];

    /** @param Closure(string): bool $confirmProtectedDurability Confirms the exact receipt against protected independent storage. */
    public function __construct(
        private readonly TelnyxCutoverInbox $inbox,
        private readonly Closure $confirmProtectedDurability,
    ) {}

    public function capture(Request $request): Response
    {
        // Use the original server values, without method or URI rewrite headers.
        $destination = $this->destination((string) $request->server->get('REQUEST_URI'));
        if ($destination === null) {
            return $this->refuse(Response::HTTP_NOT_FOUND);
        }
        if ($request->server->get('REQUEST_METHOD') !== 'POST') {
            return $this->refuse(Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'POST']);
        }

        $signatures = $request->headers->all('Telnyx-Signature-Ed25519');
        $timestamps = $request->headers->all('Telnyx-Timestamp');
        $lengths = $request->headers->all('Content-Length');
        if (count($signatures) !== 1 || count($timestamps) !== 1
            || ! is_string($signatures[0]) || ! is_string($timestamps[0])
            || ! preg_match('/\A[A-Za-z0-9+\/]{86}(?:==)?\z/', $signatures[0])
            || ! preg_match('/\A[0-9]{1,12}\z/', $timestamps[0])
            || count($lengths) > 1
            || ($lengths !== [] && (! is_string($lengths[0]) || ! preg_match('/\A[0-9]{1,10}\z/', $lengths[0])))) {
            return $this->refuse(Response::HTTP_BAD_REQUEST);
        }
        $declaredLength = $lengths === [] ? null : (int) $lengths[0];
        if ($declaredLength !== null && $declaredLength > self::MAX_BODY_BYTES) {
            return $this->refuse(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $stream = $request->getContent(true);
            $body = is_resource($stream) ? stream_get_contents($stream, self::MAX_BODY_BYTES + 1) : false;
            if ($body === false) {
                return $this->refuse(Response::HTTP_SERVICE_UNAVAILABLE);
            }
            $bodyBytes = strlen($body);
            if ($bodyBytes > self::MAX_BODY_BYTES) {
                return $this->refuse(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
            }
            if (! feof($stream)) {
                return $this->refuse(Response::HTTP_SERVICE_UNAVAILABLE);
            }
            if ($bodyBytes === 0 || ($declaredLength !== null && $declaredLength !== $bodyBytes)) {
                return $this->refuse(Response::HTTP_BAD_REQUEST);
            }

            // store() verifies the actual signature and durably writes locally.
            // It is insufficient to acknowledge, including for a local duplicate.
            $receipt = $this->inbox->store($destination, $body, $signatures[0], $timestamps[0]);
            if (($this->confirmProtectedDurability)($receipt['receipt_id']) === true) {
                return new Response('', Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
            }
        } catch (Throwable) {
            // Do not expose request data, signature diagnostics or storage errors.
        }

        return $this->refuse(Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function destination(string $uri): ?string
    {
        if (isset(self::DESTINATIONS[$uri])) {
            return self::DESTINATIONS[$uri];
        }

        if (preg_match('#\A/api/webhooks/telnyx/shared-mms/([a-fA-F0-9]{8}(?:-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12})\z#', $uri, $match)) {
            return 'shared-mms:'.strtolower($match[1]);
        }

        return null;
    }

    private function refuse(int $status, array $headers = []): Response
    {
        return new Response('Capture refused.', $status, $headers + [
            'Cache-Control' => 'no-store',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
