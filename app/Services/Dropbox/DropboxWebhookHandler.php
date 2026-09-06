<?php

namespace App\Services\Dropbox;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dropbox's verification challenge and signed account-change notifications.
 * https://www.dropbox.com/developers/reference/webhooks
 */
class DropboxWebhookHandler
{
    private const MAX_CHALLENGE_BYTES = 1024;
    private const MAX_BODY_BYTES = 1_048_576;
    private const MAX_ACCOUNTS = 10_000;

    public function handle(Request $request): Response
    {
        if ($request->isMethod('get')) {
            return $this->verifyEndpoint($request);
        }

        if (!$request->isMethod('post')) {
            return response()->json(['error' => 'Method not allowed'], 405)
                ->header('Allow', 'GET, POST');
        }

        $secret = config('services.dropbox.client_secret');
        if (!is_string($secret) || trim($secret) === '') {
            Log::error('Dropbox webhook authentication is not configured.');

            return response()->json(['error' => 'Webhook temporarily unavailable'], 503)
                ->header('Retry-After', '300');
        }

        $declaredLength = $request->header('Content-Length');
        if (is_string($declaredLength) && ctype_digit($declaredLength)
            && (float) $declaredLength > self::MAX_BODY_BYTES) {
            return response()->json(['error' => 'Webhook payload too large'], 413);
        }

        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return response()->json(['error' => 'Webhook payload too large'], 413);
        }

        $signature = $request->header('X-Dropbox-Signature');
        if (!is_string($signature)
            || !preg_match('/\A[a-f0-9]{64}\z/i', $signature)
            || !hash_equals(hash_hmac('sha256', $body, $secret), strtolower($signature))) {
            return response()->json(['error' => 'Invalid webhook signature'], 403);
        }

        // Authenticate the exact bytes before decoding or processing any field.
        try {
            $payload = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return response()->json(['error' => 'Invalid webhook payload'], 400);
        }

        $accounts = $this->notificationAccounts($payload);
        if ($accounts === null) {
            return response()->json(['error' => 'Invalid webhook payload'], 400);
        }

        // Notifications identify changed accounts, not unique events. Identical
        // account lists can represent different file changes; do not suppress
        // them using a payload-hash cache. This endpoint currently acknowledges
        // notifications only. Future processing needs per-account cursor/leases.
        Log::info('Dropbox webhook notification accepted.', [
            'account_count' => count(array_unique($accounts)),
        ]);

        return response()->json(['status' => 'ok']);
    }

    private function verifyEndpoint(Request $request): Response
    {
        // Use the query bag only. POST/body fields are not verification requests.
        $challenge = $request->query('challenge');
        if (!is_string($challenge) || $challenge === ''
            || strlen($challenge) > self::MAX_CHALLENGE_BYTES
            || preg_match('/[\x00-\x1f\x7f]/', $challenge)) {
            return response()->json(['error' => 'Invalid webhook challenge'], 400);
        }

        return response($challenge, 200)
            ->header('Content-Type', 'text/plain')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Cache-Control', 'no-store');
    }

    /** @return list<string>|null */
    private function notificationAccounts(mixed $payload): ?array
    {
        if (!$payload instanceof \stdClass
            || !isset($payload->list_folder)
            || !$payload->list_folder instanceof \stdClass
            || !isset($payload->list_folder->accounts)
            || !is_array($payload->list_folder->accounts)
            || count($payload->list_folder->accounts) > self::MAX_ACCOUNTS) {
            return null;
        }

        foreach ($payload->list_folder->accounts as $account) {
            if (!is_string($account) || !preg_match('/\Adbid:[A-Za-z0-9_-]{1,128}\z/', $account)) {
                return null;
            }
        }

        // Dropbox may include API v1 IDs alongside the API v2 account list.
        if (property_exists($payload, 'delta')) {
            if (!$payload->delta instanceof \stdClass
                || !isset($payload->delta->users)
                || !is_array($payload->delta->users)
                || count($payload->delta->users) > self::MAX_ACCOUNTS) {
                return null;
            }
            foreach ($payload->delta->users as $user) {
                if (!is_int($user) || $user <= 0) {
                    return null;
                }
            }
        }

        return $payload->list_folder->accounts;
    }
}
