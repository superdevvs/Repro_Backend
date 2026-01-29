<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CakemailWebhookController extends Controller
{
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
        
        if ($messageId) {
            Message::where('provider_message_id', $messageId)
                ->update([
                    'status' => 'DELIVERED',
                    'delivered_at' => now(),
                ]);

            Log::info('Cakemail: Email delivered', ['message_id' => $messageId]);
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
        
        if ($messageId) {
            Message::where('provider_message_id', $messageId)
                ->update([
                    'status' => 'BOUNCED',
                    'error_message' => "Bounce type: {$bounceType}",
                ]);

            Log::warning('Cakemail: Email bounced', [
                'message_id' => $messageId,
                'bounce_type' => $bounceType,
            ]);
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
