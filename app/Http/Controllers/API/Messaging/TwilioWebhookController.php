<?php

namespace App\Http\Controllers\API\Messaging;

use App\Events\SmsMessageReceived;
use App\Events\SmsThreadUpdated;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\SmsNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Twilio\Security\RequestValidator;

class TwilioWebhookController extends Controller
{
    public function messaging(Request $request): JsonResponse
    {
        if (!$this->isValidTwilioRequest($request)) {
            return response()->json(['message' => 'Invalid Twilio signature.'], 403);
        }

        $payload = $request->all();
        $messageSid = $payload['MessageSid'] ?? $payload['SmsSid'] ?? null;
        $from = $payload['From'] ?? null;
        $to = $payload['To'] ?? null;

        if (!$messageSid || !$from || !$to) {
            Log::warning('Twilio messaging webhook missing required fields', [
                'payload' => $payload,
            ]);

            return response()->json(['status' => 'ignored']);
        }

        if (Message::where('provider_message_id', $messageSid)->exists()) {
            return response()->json(['status' => 'duplicate']);
        }

        DB::transaction(function () use ($payload, $messageSid, $from, $to): void {
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
                'provider' => 'TWILIO',
                'provider_message_id' => $messageSid,
                'from_address' => $from,
                'to_address' => $to,
                'body_text' => (string) ($payload['Body'] ?? ''),
                'status' => 'DELIVERED',
                'sent_at' => now(),
                'delivered_at' => now(),
                'thread_id' => $thread->id,
            ]);

            $thread->update([
                'last_message_at' => now(),
                'last_direction' => 'INBOUND',
                'last_snippet' => Str::limit((string) ($payload['Body'] ?? ''), 200),
            ]);

            $thread->markUnreadForStaff();
            $message->loadMissing('thread.contact');

            SmsMessageReceived::dispatch($message);
            SmsThreadUpdated::dispatch($thread->fresh()->load(['contact', 'assignedTo']));
        });

        $number = $this->findSmsNumber($to);
        Log::info('Twilio inbound SMS stored', [
            'message_sid' => $messageSid,
            'sms_number_id' => $number?->id,
        ]);

        return response()->json(['status' => 'received']);
    }

    public function status(Request $request): JsonResponse
    {
        if (!$this->isValidTwilioRequest($request)) {
            return response()->json(['message' => 'Invalid Twilio signature.'], 403);
        }

        $payload = $request->all();
        $messageSid = $payload['MessageSid'] ?? $payload['SmsSid'] ?? null;
        $status = strtolower((string) ($payload['MessageStatus'] ?? ''));

        if (!$messageSid || $status === '') {
            Log::warning('Twilio status webhook missing required fields', [
                'payload' => $payload,
            ]);

            return response()->json(['status' => 'ignored']);
        }

        $message = Message::where('provider_message_id', $messageSid)->first();
        if (!$message) {
            Log::warning('Twilio status webhook received for unknown message', [
                'message_sid' => $messageSid,
                'payload' => $payload,
            ]);

            return response()->json(['status' => 'unknown_message']);
        }

        $message->update($this->mapStatusUpdate($status, $payload));

        return response()->json(['status' => 'updated']);
    }

    protected function isValidTwilioRequest(Request $request): bool
    {
        $authToken = (string) config('services.twilio.auth_token');
        $signature = (string) $request->header('X-Twilio-Signature', '');

        if ($authToken === '') {
            return app()->environment(['local', 'testing']);
        }

        if ($signature === '') {
            return false;
        }

        $validator = new RequestValidator($authToken);

        return $validator->validate($signature, $request->fullUrl(), $request->all());
    }

    protected function mapStatusUpdate(string $status, array $payload): array
    {
        return match ($status) {
            'queued', 'accepted', 'sending', 'scheduled' => [
                'status' => 'QUEUED',
                'error_message' => null,
            ],
            'sent' => [
                'status' => 'SENT',
                'sent_at' => now(),
                'error_message' => null,
            ],
            'delivered', 'read' => [
                'status' => 'DELIVERED',
                'delivered_at' => now(),
                'error_message' => null,
            ],
            'canceled', 'cancelled' => [
                'status' => 'CANCELLED',
                'failed_at' => now(),
                'error_message' => null,
            ],
            default => [
                'status' => 'FAILED',
                'failed_at' => now(),
                'error_message' => !empty($payload['ErrorCode'])
                    ? 'Twilio error code: ' . $payload['ErrorCode']
                    : 'Twilio reported message status: ' . $status,
            ],
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
