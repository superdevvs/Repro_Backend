<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\Users\EmailHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CakemailWebhookController extends Controller
{
    public function __construct(
        private readonly EmailHealthService $emailHealthService,
    ) {
    }

    /**
     * Handle incoming Cakemail webhook events
     * Events: email.delivered, email.opened, email.clicked, email.bounced, email.unsubscribed
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $event = $payload['event'] ?? $request->header('X-Cakemail-Event');

        Log::info('Cakemail webhook received', [
            'event' => $event,
            'payload' => $payload,
        ]);

        // Verify webhook signature if configured
        $secret = config('services.cakemail.webhook_secret');
        if ($secret) {
            $signature = $request->header('X-Cakemail-Signature');
            if (!$this->verifySignature($payload, $signature, $secret)) {
                Log::warning('Cakemail webhook signature verification failed');
                return response()->json(['error' => 'Invalid signature'], 401);
            }
        }

        // Process the event
        match ($event) {
            'email.delivered' => $this->handleDelivered($payload),
            'email.opened' => $this->handleOpened($payload),
            'email.clicked' => $this->handleClicked($payload),
            'email.bounced' => $this->handleBounced($payload),
            'email.unsubscribed' => $this->handleUnsubscribed($payload),
            'email.complained' => $this->handleComplained($payload),
            default => Log::info('Unhandled Cakemail event', ['event' => $event]),
        };

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle email delivered event
     */
    protected function handleDelivered(array $payload): void
    {
        $messageId = $payload['data']['email_id'] ?? $payload['email_id'] ?? null;
        $message = null;

        if ($messageId) {
            $message = Message::where('provider_message_id', $messageId)->first();
            $message?->update([
                'status' => 'DELIVERED',
                'delivered_at' => now(),
            ]);

            Log::info('Cakemail: Email delivered', ['message_id' => $messageId]);
        }

        $user = $this->resolveRelatedUser($message, $payload);
        if ($user) {
            $this->emailHealthService->markDelivered($user);
        }
    }

    /**
     * Handle email opened event
     */
    protected function handleOpened(array $payload): void
    {
        $messageId = $payload['data']['email_id'] ?? $payload['email_id'] ?? null;
        
        if ($messageId) {
            $message = Message::where('provider_message_id', $messageId)->first();
            
            if ($message) {
                $metadata = $message->metadata ?? [];
                $metadata['opened_at'] = $metadata['opened_at'] ?? now()->toIso8601String();
                $metadata['open_count'] = ($metadata['open_count'] ?? 0) + 1;
                
                $message->update(['metadata' => $metadata]);

                Log::info('Cakemail: Email opened', [
                    'message_id' => $messageId,
                    'open_count' => $metadata['open_count'],
                ]);
            }
        }
    }

    /**
     * Handle email clicked event
     */
    protected function handleClicked(array $payload): void
    {
        $messageId = $payload['data']['email_id'] ?? $payload['email_id'] ?? null;
        $link = $payload['data']['link'] ?? $payload['link'] ?? null;
        
        if ($messageId) {
            $message = Message::where('provider_message_id', $messageId)->first();
            
            if ($message) {
                $metadata = $message->metadata ?? [];
                $metadata['clicked_at'] = $metadata['clicked_at'] ?? now()->toIso8601String();
                $metadata['click_count'] = ($metadata['click_count'] ?? 0) + 1;
                $metadata['clicked_links'] = $metadata['clicked_links'] ?? [];
                
                if ($link && !in_array($link, $metadata['clicked_links'])) {
                    $metadata['clicked_links'][] = $link;
                }
                
                $message->update(['metadata' => $metadata]);

                Log::info('Cakemail: Email link clicked', [
                    'message_id' => $messageId,
                    'link' => $link,
                ]);
            }
        }
    }

    /**
     * Handle email bounced event
     */
    protected function handleBounced(array $payload): void
    {
        $messageId = $payload['data']['email_id'] ?? $payload['email_id'] ?? null;
        $bounceType = $payload['data']['bounce_type'] ?? $payload['bounce_type'] ?? 'unknown';
        $bounceReason = $payload['data']['reason'] ?? $payload['data']['diagnostic_code'] ?? $payload['reason'] ?? "Bounce type: {$bounceType}";
        $message = null;

        if ($messageId) {
            $message = Message::where('provider_message_id', $messageId)->first();
            $message?->update([
                'status' => 'BOUNCED',
                'error_message' => $bounceReason,
            ]);

            Log::warning('Cakemail: Email bounced', [
                'message_id' => $messageId,
                'bounce_type' => $bounceType,
            ]);
        }

        $user = $this->resolveRelatedUser($message, $payload);
        if ($user) {
            $this->emailHealthService->markBounced($user, $bounceReason);

            UserActivityLog::record(
                $user,
                'email_bounced',
                'Client email bounced',
                sprintf(
                    'Email to %s bounced. Entered address: %s. Please correct the client email.',
                    $user->name ?: 'the client',
                    strtolower((string) $user->email)
                ),
                null,
                [
                    'email' => strtolower((string) $user->email),
                    'bounce_reason' => $bounceReason,
                    'bounce_type' => $bounceType,
                    'sales_rep_id' => $this->emailHealthService->extractSalesRepId($user),
                ]
            );
        }
    }

    /**
     * Handle email unsubscribed event
     */
    protected function handleUnsubscribed(array $payload): void
    {
        $email = $payload['data']['email'] ?? $payload['email'] ?? null;
        
        if ($email) {
            Log::info('Cakemail: Contact unsubscribed', ['email' => $email]);
            
            // Optionally update user preferences in your database
            // User::where('email', $email)->update(['email_unsubscribed' => true]);
        }
    }

    /**
     * Handle email complained (spam report) event
     */
    protected function handleComplained(array $payload): void
    {
        $email = $payload['data']['email'] ?? $payload['email'] ?? null;
        $messageId = $payload['data']['email_id'] ?? $payload['email_id'] ?? null;
        
        Log::warning('Cakemail: Spam complaint received', [
            'email' => $email,
            'message_id' => $messageId,
        ]);

        if ($messageId) {
            Message::where('provider_message_id', $messageId)
                ->update([
                    'status' => 'COMPLAINED',
                    'error_message' => 'Marked as spam by recipient',
                ]);
        }

        $user = $this->resolveRelatedUser(
            $messageId ? Message::where('provider_message_id', $messageId)->first() : null,
            $payload
        );

        if ($user) {
            $reason = 'Marked as spam by the recipient.';
            $this->emailHealthService->markRisky($user, $reason);

            UserActivityLog::record(
                $user,
                'email_delivery_risky',
                'Client email marked risky',
                sprintf('Email deliverability for %s was marked risky. %s', $user->email, $reason),
                null,
                [
                    'email' => strtolower((string) $user->email),
                    'risk_reason' => $reason,
                    'sales_rep_id' => $this->emailHealthService->extractSalesRepId($user),
                ]
            );
        }
    }

    protected function resolveRelatedUser(?Message $message, array $payload): ?User
    {
        $candidateId = $message?->related_account_id;
        if ($candidateId) {
            $user = User::find($candidateId);
            if ($user) {
                return $user;
            }
        }

        $email = strtolower(trim((string) (
            $payload['data']['email']
            ?? $payload['email']
            ?? $payload['data']['recipient']
            ?? $payload['recipient']
            ?? $message?->to_address
            ?? ''
        )));

        if ($email === '') {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();
    }

    /**
     * Verify webhook signature
     */
    protected function verifySignature(array $payload, ?string $signature, string $secret): bool
    {
        if (!$signature) {
            return false;
        }

        $computed = hash_hmac('sha256', json_encode($payload), $secret);
        return hash_equals($computed, $signature);
    }
}
