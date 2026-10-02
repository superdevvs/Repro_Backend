<?php

namespace App\Http\Controllers\API;

use App\Models\Message;
use App\Support\InboundWebhookGuard;
use App\Support\LockedWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResendWebhookController extends CakemailWebhookController
{
    public function handle(Request $request): JsonResponse
    {
        $secret = trim((string) config('services.resend.webhook_secret'));
        if ($unconfigured = InboundWebhookGuard::requireConfiguredSecret($secret)) {
            return $unconfigured;
        }
        if (! $this->validSignature($request, $secret)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->json()->all();
        $event = $payload['type'] ?? '';
        if (! in_array($event, ['email.delivered', 'email.opened', 'email.clicked', 'email.bounced', 'email.complained', 'email.failed', 'email.suppressed', 'email.delivery_delayed'], true)) {
            return response()->json(['status' => 'ignored']);
        }
        $emailId = data_get($payload, 'data.email_id');
        if (! is_string($emailId) || $emailId === '') {
            return response()->json(['error' => 'Missing email identifier'], 422);
        }

        $processed = LockedWrite::run(fn () => DB::transaction(function () use ($emailId, $payload, $event, $request) {
            $message = Message::where('provider', 'RESEND')->where('provider_message_id', $emailId)->first();
            // The webhook can race the post-send persistence. Request a retry.
            if (! $message) {
                return false;
            }
            $eventId = (string) $request->header('svix-id');
            $ids = (array) data_get($message->metadata, 'resend_webhook_event_ids', []);
            if (in_array($eventId, $ids, true)) {
                return true;
            }

            $normalized = ['data' => [
                'email_id' => $emailId,
                'email' => $message->to_address,
                'reason' => (string) (data_get($payload, 'data.bounce.message') ?? data_get($payload, 'data.failed.reason') ?? data_get($payload, 'data.suppressed.reason') ?? 'Email delivery failed.'),
                'bounce_type' => data_get($payload, 'data.bounce.type', 'unknown'),
                'link' => data_get($payload, 'data.click.link'),
            ]];
            $terminal = in_array($message->status, ['BOUNCED', 'COMPLAINED', 'FAILED'], true);
            match ($event) {
                'email.delivered' => $terminal ? null : $this->handleDelivered($normalized),
                'email.opened' => $this->handleOpened($normalized),
                'email.clicked' => $this->handleClicked($normalized),
                'email.bounced' => $this->handleBounced($normalized),
                'email.complained' => $this->handleComplained($normalized),
                'email.failed', 'email.suppressed' => $terminal ? null : $message->update(['status' => 'FAILED', 'failed_at' => now(), 'error_message' => $normalized['data']['reason']]),
                default => null,
            };
            $metadata = (array) $message->refresh()->metadata;
            $metadata['resend_webhook_event_ids'] = [...$ids, $eventId];
            $metadata['resend_last_event'] = $event;
            $activity = app(\App\Services\Messaging\EmailActivityService::class);
            $eventAt = $activity->timestamp($payload['created_at'] ?? null) ?? now()->toIso8601String();
            $metadata['email_activity'] = array_slice([...(array) ($metadata['email_activity'] ?? []), [
                'id' => $eventId, 'type' => substr($event, 6), 'at' => $eventAt,
                'detail' => in_array($event, ['email.bounced', 'email.failed', 'email.suppressed'], true) ? $activity->detail($normalized['data']['reason']) : null,
                'link' => $activity->safeLink($normalized['data']['link']),
            ]], -100);
            $message->update(['metadata' => $metadata]);

            return true;
        }), 'resend_webhook');

        return response()->json(['status' => $processed ? 'ok' : 'awaiting_message'], $processed ? 200 : 503);
    }

    private function validSignature(Request $request, string $secret): bool
    {
        $id = (string) $request->header('svix-id');
        $timestamp = (string) $request->header('svix-timestamp');
        if ($id === '' || ! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > 300 || ! str_starts_with($secret, 'whsec_')) {
            return false;
        }
        $key = base64_decode(substr($secret, 6), true);
        if ($key === false || $key === '') {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$request->getContent(), $key, true));
        foreach (explode(' ', (string) $request->header('svix-signature')) as $signature) {
            if (str_starts_with($signature, 'v1,') && hash_equals($expected, substr($signature, 3))) {
                return true;
            }
        }

        return false;
    }
}
