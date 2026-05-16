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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        $payload = $request->json()->all();
        $eventType = (string) data_get($payload, 'data.event_type', '');

        return match ($eventType) {
            'message.received' => $this->handleInbound($payload),
            'message.sent', 'message.finalized', 'message.failed' => $this->handleStatus($payload, $eventType),
            default => $this->ignored($eventType),
        };
    }

    /**
     * Reserved for explicit per-message webhook_url overrides; not configured by default.
     */
    public function status(Request $request): JsonResponse
    {
        if (!$this->isValidTelnyxRequest($request)) {
            return response()->json(['message' => 'Invalid Telnyx signature.'], 403);
        }

        $payload = $request->json()->all();
        $eventType = (string) data_get($payload, 'data.event_type', '');

        if ($eventType === '') {
            return $this->ignored($eventType);
        }

        return $this->handleStatus($payload, $eventType);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleInbound(array $payload): JsonResponse
    {
        $messageId = (string) data_get($payload, 'data.payload.id', '');
        $from = (string) data_get($payload, 'data.payload.from.phone_number', '');
        $to = (string) data_get($payload, 'data.payload.to.0.phone_number', '');
        $text = (string) data_get($payload, 'data.payload.text', '');

        if ($messageId === '' || $from === '' || $to === '') {
            Log::warning('Telnyx inbound webhook missing required fields', ['payload' => $payload]);

            return response()->json(['status' => 'ignored']);
        }

        if (Message::where('provider_message_id', $messageId)->exists()) {
            return response()->json(['status' => 'duplicate']);
        }

        $createdMessageId = null;

        DB::transaction(function () use ($payload, $messageId, $from, $to, $text, &$createdMessageId): void {
            $contactPhone = $this->normalizePhoneNumber($from);
            $contact = $this->findContactByPhone($contactPhone)
                ?? Contact::create([
                    'name' => $this->formatPhoneForDisplay($contactPhone),
                    'phone' => $contactPhone,
                    'type' => 'other',
                ]);

            if (!$contact->phone) {
                $contact->update(['phone' => $contactPhone]);
            }

            $thread = MessageThread::firstOrCreate(
                ['contact_id' => $contact->id, 'channel' => 'SMS'],
                ['last_message_at' => now()]
            );

            $message = Message::create([
                'channel' => 'SMS',
                'direction' => 'INBOUND',
                'provider' => 'TELNYX',
                'provider_message_id' => $messageId,
                'from_address' => $from,
                'to_address' => $to,
                'body_text' => $text,
                'status' => 'DELIVERED',
                'sent_at' => now(),
                'delivered_at' => now(),
                'thread_id' => $thread->id,
                'metadata' => [
                    'telnyx_event_id' => data_get($payload, 'data.id'),
                    'telnyx_event_type' => data_get($payload, 'data.event_type'),
                ],
            ]);

            $createdMessageId = $message->id;

            $thread->update([
                'last_message_at' => now(),
                'last_direction' => 'INBOUND',
                'last_snippet' => Str::limit($text, 200),
            ]);

            $thread->markUnreadForStaff();
            $message->loadMissing('thread.contact');

            SmsMessageReceived::dispatch($message);
            SmsThreadUpdated::dispatch($thread->fresh()->load(['contact', 'assignedTo']));
        });

        $number = $this->findSmsNumber($to);
        Log::info('Telnyx inbound SMS stored', [
            'provider_message_id' => $messageId,
            'sms_number_id' => $number?->id,
        ]);

        if ($createdMessageId !== null && config('services.telnyx.ai_sms_enabled', false)) {
            ProcessInboundSmsAiJob::dispatch($createdMessageId);
        }

        return response()->json(['status' => 'received']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleStatus(array $payload, string $eventType): JsonResponse
    {
        $messageId = (string) data_get($payload, 'data.payload.id', '');

        if ($messageId === '') {
            Log::warning('Telnyx status webhook missing message id', [
                'payload' => $payload,
                'event_type' => $eventType,
            ]);

            return response()->json(['status' => 'ignored']);
        }

        $message = Message::where('provider_message_id', $messageId)->first();
        if (!$message) {
            Log::warning('Telnyx status webhook for unknown message', [
                'provider_message_id' => $messageId,
                'event_type' => $eventType,
            ]);

            return response()->json(['status' => 'unknown_message']);
        }

        $message->update($this->mapStatusUpdate($eventType, $payload));

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
    protected function mapStatusUpdate(string $eventType, array $payload): array
    {
        // Telnyx event_type → internal status mapping. message.finalized carries final state in data.payload.to[0].status.
        $finalState = strtolower((string) data_get($payload, 'data.payload.to.0.status', ''));

        return match ($eventType) {
            'message.sent' => [
                'status' => 'SENT',
                'sent_at' => now(),
                'error_message' => null,
            ],
            'message.finalized' => match ($finalState) {
                'delivered' => [
                    'status' => 'DELIVERED',
                    'delivered_at' => now(),
                    'error_message' => null,
                ],
                'sending_failed', 'delivery_failed', 'failed' => [
                    'status' => 'FAILED',
                    'failed_at' => now(),
                    'error_message' => 'Telnyx final state: ' . $finalState,
                ],
                default => [
                    'status' => 'DELIVERED',
                    'delivered_at' => now(),
                    'error_message' => null,
                ],
            },
            'message.failed' => [
                'status' => 'FAILED',
                'failed_at' => now(),
                'error_message' => 'Telnyx reported message.failed',
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
