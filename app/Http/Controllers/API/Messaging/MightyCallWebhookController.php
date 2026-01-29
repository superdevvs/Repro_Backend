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

class MightyCallWebhookController extends Controller
{
    /**
     * Handle incoming webhook from MightyCall
     * 
     * MightyCall sends webhooks for:
     * - Incoming/Outgoing calls
     * - SMS messages (via message events)
     */
    public function handle(Request $request): JsonResponse
    {
        // Handle GET requests for webhook validation
        if ($request->isMethod('get')) {
            return response()->json([
                'status' => 'ok',
                'message' => 'MightyCall webhook endpoint ready',
            ]);
        }

        $payload = $request->all();
        
        Log::info('MightyCall webhook received', [
            'method' => $request->method(),
            'event_type' => $payload['EventType'] ?? $payload['eventType'] ?? 'unknown',
            'payload' => $payload,
        ]);

        // Respond quickly to satisfy MightyCall's 8-second timeout requirement
        // Process asynchronously if needed
        
        $eventType = $payload['EventType'] ?? $payload['eventType'] ?? '';
        
        // Handle different event types
        if ($this->isSmsEvent($eventType, $payload)) {
            $this->handleSmsEvent($payload);
        } elseif ($this->isCallEvent($eventType)) {
            $this->handleCallEvent($payload);
        }

        return response()->json(['status' => 'received']);
    }

    /**
     * Check if this is an SMS-related event
     */
    protected function isSmsEvent(string $eventType, array $payload): bool
    {
        // Check for SMS-specific event types
        $smsEventTypes = [
            'Message',
            'MessageReceived',
            'MessageSent',
            'InboundMessage',
            'OutboundMessage',
            'SmsMms',
        ];

        if (in_array($eventType, $smsEventTypes)) {
            return true;
        }

        // Check if payload contains message data
        if (isset($payload['Body']['requestType']) && $payload['Body']['requestType'] === 'Message') {
            return true;
        }

        if (isset($payload['requestType']) && $payload['requestType'] === 'Message') {
            return true;
        }

        return false;
    }

    /**
     * Check if this is a call-related event
     */
    protected function isCallEvent(string $eventType): bool
    {
        $callEventTypes = [
            'IncomingCall',
            'OutgoingCall',
            'IncomingCallAgentRinging',
            'IncomingCallAgentConnected',
            'IncomingCallAgentCompleted',
            'IncomingCallCompleted',
            'OutgoingCallAgentDialing',
            'OutgoingCallAgentConnected',
            'OutgoingCallAgentCompleted',
            'OutgoingCallCompleted',
        ];

        return in_array($eventType, $callEventTypes);
    }

    /**
     * Handle SMS message event
     */
    protected function handleSmsEvent(array $payload): void
    {
        try {
            $body = $payload['Body'] ?? $payload;
            
            // Extract message details
            $from = $body['From'] ?? $body['from'] ?? $body['clientAddress'] ?? null;
            $to = $body['To'] ?? $body['to'] ?? $body['businessNumber'] ?? null;
            $messageText = $body['text'] ?? $body['message'] ?? $body['Text'] ?? '';
            $messageId = $payload['CallId'] ?? $payload['Guid'] ?? $body['Id'] ?? $body['id'] ?? Str::uuid()->toString();
            $timestamp = $payload['Timestamp'] ?? $body['timestamp'] ?? now()->toIso8601String();
            $direction = $this->determineDirection($payload);

            if (!$from || !$to) {
                Log::warning('MightyCall SMS webhook missing from/to', ['payload' => $payload]);
                return;
            }

            // Determine which is our number and which is the contact
            $ourNumber = $direction === 'INBOUND' ? $to : $from;
            $contactPhone = $direction === 'INBOUND' ? $from : $to;

            // Find the SmsNumber record
            $smsNumber = $this->findSmsNumber($ourNumber);
            if (!$smsNumber) {
                Log::warning('MightyCall SMS webhook: Unknown business number', [
                    'our_number' => $ourNumber,
                    'contact_phone' => $contactPhone,
                ]);
                // Still process, might be a new number
            }

            // Store the message
            $this->storeIncomingMessage([
                'from' => $from,
                'to' => $to,
                'contact_phone' => $contactPhone,
                'message' => $messageText,
                'message_id' => $messageId,
                'timestamp' => $timestamp,
                'direction' => $direction,
                'sms_number' => $smsNumber,
                'raw_payload' => $payload,
            ]);

        } catch (\Exception $e) {
            Log::error('MightyCall SMS webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $payload,
            ]);
        }
    }

    /**
     * Determine message direction
     */
    protected function determineDirection(array $payload): string
    {
        $eventType = $payload['EventType'] ?? $payload['eventType'] ?? '';
        $body = $payload['Body'] ?? $payload;
        
        // Check event type
        if (str_contains(strtolower($eventType), 'inbound') || str_contains(strtolower($eventType), 'received')) {
            return 'INBOUND';
        }
        if (str_contains(strtolower($eventType), 'outbound') || str_contains(strtolower($eventType), 'sent')) {
            return 'OUTBOUND';
        }

        // Check messageOrigin field
        $origin = $body['messageOrigin'] ?? $body['MessageOrigin'] ?? null;
        if ($origin === 'Inbound') {
            return 'INBOUND';
        }
        if ($origin === 'Outbound') {
            return 'OUTBOUND';
        }

        // Check direction field
        $direction = $body['direction'] ?? $body['Direction'] ?? null;
        if ($direction === 'Incoming') {
            return 'INBOUND';
        }
        if ($direction === 'Outgoing') {
            return 'OUTBOUND';
        }

        // Default to inbound for webhook (most common case)
        return 'INBOUND';
    }

    /**
     * Find SmsNumber by phone number
     */
    protected function findSmsNumber(string $phone): ?SmsNumber
    {
        // Normalize phone number
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        return SmsNumber::where('phone_number', 'LIKE', "%{$digits}%")
            ->orWhere('phone_number', 'LIKE', "%{$phone}%")
            ->first();
    }

    /**
     * Store incoming message in database
     */
    protected function storeIncomingMessage(array $data): void
    {
        DB::transaction(function () use ($data) {
            // Find or create contact
            $contact = Contact::firstOrCreate(
                ['phone' => $data['contact_phone']],
                [
                    'name' => $this->formatPhoneForDisplay($data['contact_phone']),
                    'type' => 'other',
                ]
            );

            // Find or create thread
            $thread = MessageThread::firstOrCreate(
                ['contact_id' => $contact->id, 'channel' => 'SMS'],
                ['last_message_at' => now()]
            );

            // Check for duplicate message
            $existingMessage = Message::where('provider_message_id', $data['message_id'])->first();
            if ($existingMessage) {
                Log::info('MightyCall: Duplicate message ignored', ['message_id' => $data['message_id']]);
                return;
            }

            // Create the message
            $message = Message::create([
                'channel' => 'SMS',
                'direction' => $data['direction'],
                'provider' => 'MIGHTYCALL',
                'from_address' => $data['from'],
                'to_address' => $data['to'],
                'body_text' => $data['message'],
                'status' => 'DELIVERED',
                'sent_at' => $data['timestamp'],
                'provider_message_id' => $data['message_id'],
                'thread_id' => $thread->id,
                'metadata_json' => $data['raw_payload'],
            ]);

            // Update thread
            $thread->update([
                'last_message_at' => now(),
                'last_direction' => $data['direction'],
                'last_snippet' => Str::limit($data['message'], 200),
            ]);

            // Mark as unread for staff if inbound
            if ($data['direction'] === 'INBOUND') {
                $thread->markUnreadForStaff();
            }

            $message->loadMissing('thread.contact');

            // Dispatch events for real-time updates
            if ($data['direction'] === 'INBOUND') {
                SmsMessageReceived::dispatch($message);
            }
            SmsThreadUpdated::dispatch($thread->fresh()->load(['contact', 'assignedTo']));

            Log::info('MightyCall: Incoming SMS stored', [
                'message_id' => $message->id,
                'thread_id' => $thread->id,
                'direction' => $data['direction'],
            ]);
        });
    }

    /**
     * Handle call event (for logging/notifications)
     */
    protected function handleCallEvent(array $payload): void
    {
        // Log call events for now, can be expanded for call tracking
        Log::info('MightyCall call event', [
            'event_type' => $payload['EventType'] ?? 'unknown',
            'call_id' => $payload['CallId'] ?? null,
            'from' => $payload['Body']['From'] ?? null,
            'to' => $payload['Body']['To'] ?? null,
        ]);
    }

    /**
     * Format phone number for display
     */
    protected function formatPhoneForDisplay(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }
        
        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6, 4)
            );
        }
        
        return $phone;
    }

    /**
     * Verify webhook is from MightyCall (optional security)
     */
    public function verify(Request $request): JsonResponse
    {
        // MightyCall sends a POST request to verify webhook URLs
        // Must respond with 2XX within 8 seconds
        return response()->json(['status' => 'verified']);
    }
}
