<?php

namespace App\Http\Controllers\API\Messaging;

use App\Events\SmsMessageReceived;
use App\Events\SmsThreadUpdated;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundSmsAiJob;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\SmsNumber;
use App\Services\Messaging\SmsDeliveryFailure;
use App\Support\LockedWrite;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TelnyxWebhookController extends Controller
{
    /**
     * Canonical Telnyx webhook endpoint configured on the Messaging Profile.
     * Routes by data.event_type to handle inbound + delivery events.
     */
    public function messaging(Request $request): JsonResponse
    {
        if (!$this->isValidTelnyxRequest($request)) {
            return response()->json(['message' => 'Invalid Telnyx signature.'], 403);
        }

        return $this->routeMessaging($request->json()->all(), null);
    }

    /**
     * Reserved for explicit per-message webhook_url overrides; not configured by default.
     */
    public function status(Request $request): JsonResponse
    {
        if (!$this->isValidTelnyxRequest($request)) {
            return response()->json(['message' => 'Invalid Telnyx signature.'], 403);
        }

        return $this->routeStatus($request->json()->all(), null);
    }

    /**
     * Handles a text event that the maintenance spool verified when Telnyx
     * delivered it. No route points here; only telnyx:replay-spool calls it.
     * $receivedAt is the original arrival time, not the replay time.
     *
     * @param  array<string, mixed>  $payload
     */
    public function replayVerified(string $route, ?string $sharedMessage, array $payload, CarbonInterface $receivedAt): JsonResponse
    {
        return match ($route) {
            'messaging' => $this->routeMessaging($payload, $receivedAt),
            'status' => $this->routeStatus($payload, $receivedAt),
            'shared-mms' => response()->json(app(\App\Services\Messaging\SharedMmsService::class)->webhook($payload, $sharedMessage) ?? ['status' => 'ignored']),
            default => throw new InvalidArgumentException('Only Telnyx text routes can be replayed.'),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  CarbonInterface|null  $receivedAt  The arrival time of a replayed event; null when live.
     */
    private function routeMessaging(array $payload, ?CarbonInterface $receivedAt): JsonResponse
    {
        $eventType = (string) data_get($payload, 'data.event_type', '');

        if ($group = app(\App\Services\Messaging\SharedMmsService::class)->webhook($payload)) {
            return response()->json($group);
        }

        return match ($eventType) {
            'message.received' => $this->handleInbound($payload, $receivedAt),
            'message.sent', 'message.finalized', 'message.failed' => $this->handleStatus($payload, $eventType, $receivedAt),
            default => $this->ignored($eventType),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function routeStatus(array $payload, ?CarbonInterface $receivedAt): JsonResponse
    {
        $eventType = (string) data_get($payload, 'data.event_type', '');

        if ($group = app(\App\Services\Messaging\SharedMmsService::class)->webhook($payload)) {
            return response()->json($group);
        }

        if ($eventType === '') {
            return $this->ignored($eventType);
        }

        return $this->handleStatus($payload, $eventType, $receivedAt);
    }

    public function sharedStatus(Request $request, string $message): JsonResponse
    {
        if (! $this->isValidTelnyxRequest($request)) {
            return response()->json(['message' => 'Invalid Telnyx signature.'], 403);
        }
        return response()->json(app(\App\Services\Messaging\SharedMmsService::class)->webhook($request->json()->all(), $message) ?? ['status' => 'ignored']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleInbound(array $payload, ?CarbonInterface $receivedAt = null): JsonResponse
    {
        $at = $receivedAt ?? now();
        $messageId = (string) data_get($payload, 'data.payload.id', '');
        $from = (string) data_get($payload, 'data.payload.from.phone_number', '');
        $to = (string) data_get($payload, 'data.payload.to.0.phone_number', '');
        $text = (string) data_get($payload, 'data.payload.text', '');

        if ($messageId === '' || $from === '' || $to === '') {
            Log::warning('Telnyx inbound webhook missing required fields', ['payload' => $payload]);

            return response()->json(['status' => 'ignored']);
        }

        if ($existing = Message::where('provider_message_id', $messageId)->first()) {
            app(\App\Services\Messaging\GroundwireSmsPushService::class)->queueIncoming($existing->id);
            return response()->json(['status' => 'duplicate']);
        }

        $knownThread = app(\App\Services\Messaging\SmsInboxPolicy::class)->threadForPair($from, $to);
        $knownContact = $knownThread?->contact ?? $this->findContactByPhone($this->normalizePhoneNumber($from));
        $createdMessageId = LockedWrite::run(fn () => DB::transaction(function () use ($payload, $messageId, $from, $to, $text, $knownThread, $knownContact, $at, $receivedAt): ?int {
            // Checked again inside the write: a live delivery and a replay of
            // the same text can both pass the check above. On SQLite a copy
            // committed meanwhile makes this write fail as locked, and the
            // LockedWrite retry then finds it here.
            if (Message::where('provider_message_id', $messageId)->exists()) {
                return null;
            }
            $contactPhone = $this->normalizePhoneNumber($from);
            $thread = $knownThread?->fresh();
            $contact = $knownContact?->fresh() ?? $this->findContactByPhone($contactPhone)
                ?? Contact::create([
                    'name' => $this->formatPhoneForDisplay($contactPhone),
                    'phone' => $contactPhone,
                    'type' => 'other',
                ]);

            if (!$contact->phone) {
                $contact->update(['phone' => $contactPhone]);
            }

            $thread ??= MessageThread::create(['contact_id' => $contact->id, 'channel' => 'SMS', 'last_message_at' => $at]);

            $message = new Message([
                'channel' => 'SMS',
                'direction' => 'INBOUND',
                'provider' => 'TELNYX',
                'provider_message_id' => $messageId,
                'from_address' => $from,
                'to_address' => $to,
                'body_text' => $text,
                'status' => 'DELIVERED',
                'sent_at' => $at,
                'delivered_at' => $at,
                'thread_id' => $thread->id,
                'metadata' => [
                    'telnyx_event_id' => data_get($payload, 'data.id'),
                    'telnyx_event_type' => data_get($payload, 'data.event_type'),
                ],
            ]);
            if ($receivedAt !== null) {
                // Clients show and order texts by created_at (sentAt, the
                // history and the thread preview), so a replayed text keeps
                // its arrival time there too, as a live one would have.
                $message->created_at = $at;
            }
            $message->save();
            if ($receivedAt !== null) {
                // Saving marked the thread as needing a reply; a newer staff
                // reply must keep it answered.
                app(\App\Services\Messaging\SmsInboxPolicy::class)->recordBackdated($message);
            }

            app(\App\Services\Messaging\MessageMediaService::class)->incoming('sms', (string) $message->id, (array) data_get($payload, 'data.payload.media', []));
            // A replayed text keeps its arrival time, so it must not move the
            // thread's latest-message summary back past a newer message.
            if ($receivedAt === null || $thread->last_message_at === null || $thread->last_message_at->lte($at)) {
                $thread->update([
                    'last_message_at' => $at,
                    'last_direction' => 'INBOUND',
                    'last_snippet' => Str::limit($text, 200),
                ]);
            }

            if (app(\App\Services\Messaging\SmsInboxPolicy::class)->kind($message) === 'incoming') {
                $thread->markUnreadForStaff();
            }
            return $message->id;
        }), 'sms.inbound.persist');

        if ($createdMessageId === null) {
            if ($existing = Message::where('provider_message_id', $messageId)->first()) {
                app(\App\Services\Messaging\GroundwireSmsPushService::class)->queueIncoming($existing->id);
            }

            return response()->json(['status' => 'duplicate']);
        }

        if ($createdMessageId !== null) {
            $message = Message::with('thread.contact', 'thread.assignedTo')->findOrFail($createdMessageId);
            SmsMessageReceived::dispatch($message);
            SmsThreadUpdated::dispatch($message->thread);
        }

        if ($createdMessageId !== null) {
            app(\App\Services\Messaging\GroundwireSmsPushService::class)->queueIncoming($createdMessageId);
        }
        $number = $this->findSmsNumber($to);
        Log::info('Telnyx inbound SMS stored', [
            'provider_message_id' => $messageId,
            'sms_number_id' => $number?->id,
        ]);

        $isComplianceKeyword = app(\App\Services\Messaging\AiSms\SmsComplianceService::class)->detectKeyword($text) !== null;
        $isReaction = app(\App\Services\Messaging\SmsReactionParser::class)->parse($text) !== null;
        if ($createdMessageId !== null && ! $isReaction && (config('services.telnyx.ai_sms_enabled', false) || $isComplianceKeyword)) {
            ProcessInboundSmsAiJob::dispatch($createdMessageId);
        }

        return response()->json(['status' => 'received']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleStatus(array $payload, string $eventType, ?CarbonInterface $receivedAt = null): JsonResponse
    {
        $messageId = (string) data_get($payload, 'data.payload.id', '');

        if ($messageId === '') {
            Log::warning('Telnyx status webhook missing message id', [
                'payload' => $payload,
                'event_type' => $eventType,
            ]);

            return response()->json(['status' => 'ignored']);
        }

        $message = Message::where('provider_message_id', $messageId)
            ->where('channel', 'SMS')->where('direction', 'OUTBOUND')->where('provider', 'TELNYX')->first();
        if (!$message) {
            Log::warning('Telnyx status webhook for unknown message', [
                'provider_message_id' => $messageId,
                'event_type' => $eventType,
            ]);

            return response()->json(['status' => 'unknown_message']);
        }

        $updates = $this->mapStatusUpdate($eventType, $payload, $receivedAt);
        \App\Support\LockedWrite::run(fn () => DB::transaction(function () use ($message, $updates, $eventType, $payload): void {
            $message->refresh();
            if ($updates === [] && in_array($message->status, ['DELIVERED', 'FAILED'], true)) {
                return;
            }
            if (data_get($payload, 'data.id') && data_get($message->metadata, 'telnyx_event_id') === data_get($payload, 'data.id')) {
                return;
            }
            // Webhooks may arrive out of order. A late carrier-accepted event
            // must never erase a terminal delivery/failure result.
            if (($updates['status'] ?? null) === 'SENT' && in_array($message->status, ['DELIVERED', 'FAILED'], true)) {
                return;
            }
            $occurredAt = data_get($payload, 'data.occurred_at');
            $previousAt = data_get($message->metadata, 'telnyx_status_occurred_at');
            if ($occurredAt && $previousAt) {
                try {
                    if (\Carbon\CarbonImmutable::parse($occurredAt)->lt(\Carbon\CarbonImmutable::parse($previousAt))) {
                        return;
                    }
                } catch (\Throwable) {
                    // Missing/malformed provider timestamps do not fabricate delivery.
                }
            }
            $metadata = (array) ($message->metadata ?? []);
            $metadata['telnyx_event_id'] = data_get($payload, 'data.id');
            $metadata['telnyx_event_type'] = $eventType;
            $metadata['telnyx_delivery_status'] = data_get($payload, 'data.payload.to.0.status') ?: null;
            if ($occurredAt) {
                $metadata['telnyx_status_occurred_at'] = $occurredAt;
            }
            $codes = ($updates['status'] ?? null) === 'FAILED' ? SmsDeliveryFailure::codes($payload) : [];
            // Deferred messages previously retained delivery.status=SENT after a failure.
            if (isset($updates['status'])) {
                $metadata['telnyx_error_codes'] = $codes;
                $metadata['delivery'] = array_merge((array) ($metadata['delivery'] ?? []), [
                    'status' => $updates['status'],
                    'error' => $updates['error_message'] ?? null,
                ]);
            }
            $changedFailure = ($updates['status'] ?? null) === 'FAILED'
                && ($message->status !== 'FAILED' || data_get($message->metadata, 'telnyx_error_codes') !== $codes);
            $message->update($updates + ['metadata' => $metadata]);
            if ($changedFailure) {
                // LOG_LEVEL=error in production. Never log message bodies or provider text.
                Log::error('SMS delivery failed after provider acceptance', [
                    'message_id' => $message->id, 'provider_message_id' => $message->provider_message_id,
                    'error_codes' => $codes, 'shoot_id' => $message->related_shoot_id,
                ]);
            }
        }), 'sms.delivery_status');

        return response()->json(['status' => 'updated']);
    }

    protected function ignored(string $eventType): JsonResponse
    {
        Log::debug('Telnyx webhook event ignored', ['event_type' => $eventType]);

        return response()->json(['status' => 'ignored']);
    }

    protected function isValidTelnyxRequest(Request $request): bool
    {
        $publicKey = (string) config('services.telnyx.public_key');
        $signature = (string) ($request->header('Telnyx-Signature-Ed25519')
            ?? $request->header('telnyx-signature-ed25519')
            ?? '');
        $timestamp = (string) ($request->header('Telnyx-Timestamp')
            ?? $request->header('telnyx-timestamp')
            ?? '');

        if ($publicKey === '') {
            // Production fails closed; non-prod allowed to skip when key unset (e.g. local replay testing).
            return app()->environment(['local', 'testing']);
        }

        if ($signature === '' || $timestamp === '') {
            return false;
        }

        $tolerance = (int) config('services.telnyx.webhook_tolerance_seconds', 300);
        if (abs(time() - (int) $timestamp) > max(1, $tolerance)) {
            Log::warning('Telnyx webhook rejected: stale timestamp', [
                'timestamp' => $timestamp,
                'tolerance' => $tolerance,
            ]);
            return false;
        }

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            Log::error('Telnyx webhook verification unavailable: libsodium missing');
            return false;
        }

        $body = $request->getContent();
        $signedPayload = $timestamp . '|' . $body;

        $signatureBytes = base64_decode($signature, true);
        $publicKeyBytes = base64_decode($publicKey, true);

        if ($signatureBytes === false || $publicKeyBytes === false) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signatureBytes, $signedPayload, $publicKeyBytes);
        } catch (\SodiumException $e) {
            Log::warning('Telnyx webhook signature verification threw', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function mapStatusUpdate(string $eventType, array $payload, ?CarbonInterface $receivedAt = null): array
    {
        // Telnyx event_type → internal status mapping. message.finalized carries final state in data.payload.to[0].status.
        $finalState = strtolower((string) data_get($payload, 'data.payload.to.0.status', ''));
        $at = $receivedAt ?? now();

        return match ($eventType) {
            'message.sent' => [
                'status' => 'SENT',
                'sent_at' => $at,
                'error_message' => null,
            ],
            'message.finalized' => match ($finalState) {
                'delivered' => [
                    'status' => 'DELIVERED',
                    'delivered_at' => $at,
                    'failed_at' => null,
                    'error_message' => null,
                ],
                'sending_failed', 'delivery_failed', 'failed' => [
                    'status' => 'FAILED',
                    'failed_at' => $at,
                    'delivered_at' => null,
                    'error_message' => SmsDeliveryFailure::message(SmsDeliveryFailure::codes($payload)),
                ],
                // Telnyx explicitly distinguishes delivery_unconfirmed from
                // delivered. Preserve the last known state for unknown results.
                default => [],
            },
            'message.failed' => [
                'status' => 'FAILED',
                'failed_at' => $at,
                'delivered_at' => null,
                'error_message' => SmsDeliveryFailure::message(SmsDeliveryFailure::codes($payload)),
            ],
            default => [],
        };
    }

    protected function findContactByPhone(string $phone): ?Contact
    {
        $digits = $this->phoneDigits($phone);

        return Contact::query()
            ->where('phone', $phone)
            ->orWhere('phone', 'like', '%' . $digits . '%')
            ->first();
    }

    protected function findSmsNumber(string $phone): ?SmsNumber
    {
        $digits = $this->phoneDigits($phone);

        return SmsNumber::query()
            ->where('phone_number', $phone)
            ->orWhere('phone_number', 'like', '%' . $digits . '%')
            ->first();
    }

    protected function normalizePhoneNumber(string $phone): string
    {
        $digits = $this->phoneDigits($phone);

        if (strlen($digits) === 10) {
            $digits = '1' . $digits;
        }

        return '+' . $digits;
    }

    protected function phoneDigits(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return substr($digits, 1);
        }

        return $digits;
    }

    protected function formatPhoneForDisplay(string $phone): string
    {
        $digits = $this->phoneDigits($phone);

        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6, 4)
            );
        }

        return $phone;
    }
}
