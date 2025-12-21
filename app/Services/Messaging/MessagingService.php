<?php

namespace App\Services\Messaging;

use App\Events\SmsMessageReceived;
use App\Events\SmsMessageSent;
use App\Events\SmsThreadUpdated;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\MessageTemplate;
use App\Models\MessageThread;
use App\Models\SmsNumber;
use App\Models\User;
use App\Services\Messaging\Contracts\EmailProviderInterface;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Illuminate\Support\Str;

class MessagingService
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly Providers\LocalSmtpProvider $localSmtpProvider,
        private readonly Providers\GoogleOAuthProvider $googleOAuthProvider,
        private readonly Providers\MailchimpProvider $mailchimpProvider,
        private readonly Providers\MightyCallSmsProvider $mightyCallProvider,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendEmail(array $payload): Message
    {
        $channel = $this->resolveEmailChannel($payload);

        $message = $this->storeMessageRecord($payload, $channel, 'EMAIL');

        $provider = $this->getEmailProvider($channel);
        $providerMessageId = $provider->send($channel, [
            'to' => $payload['to'],
            'subject' => $payload['subject'] ?? $message->subject,
            'html' => $payload['body_html'] ?? $message->body_html,
            'text' => $payload['body_text'] ?? $message->body_text,
            'reply_to' => $payload['reply_to'] ?? null,
        ]);

        $message->update([
            'status' => 'SENT',
            'sent_at' => now(),
            'provider_message_id' => $providerMessageId,
        ]);

        return $message->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function scheduleEmail(array $payload, CarbonInterface $scheduledAt): Message
    {
        $channel = $this->resolveEmailChannel($payload);

        return $this->storeMessageRecord(
            array_merge($payload, ['scheduled_at' => $scheduledAt]),
            $channel,
            'EMAIL',
            status: 'SCHEDULED'
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendSms(array $payload): Message
    {
        $number = $this->resolveSmsNumber($payload);

        $message = $this->storeMessageRecord(
            array_merge($payload, ['from' => $number->phone_number]),
            null,
            'SMS',
            direction: 'OUTBOUND',
            status: 'QUEUED',
            providerOverride: 'MIGHTYCALL'
        );

        try {
            $providerMessageId = $this->mightyCallProvider->send($number, [
                'to' => $payload['to'],
                'text' => $payload['body_text'] ?? '',
            ]);

            $message->update([
                'status' => 'SENT',
                'sent_at' => now(),
                'provider_message_id' => $providerMessageId,
            ]);
        } catch (\Exception $e) {
            $message->update([
                'status' => 'FAILED',
            ]);
            
            Log::error('SMS send failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }

        return $message->fresh();
    }

    public function listThreads(array $filters = []): Builder
    {
        return MessageThread::query()
            ->with('contact')
            ->when(isset($filters['channel']), fn ($query) => $query->where('channel', $filters['channel']))
            ->when(
                isset($filters['contact_id']),
                fn ($query) => $query->where('contact_id', $filters['contact_id'])
            )
            ->orderByDesc('last_message_at');
    }

    public function getMessageLogs(array $filters = []): Builder
    {
        return Message::query()
            ->with(['thread.contact'])
            ->when(isset($filters['channel']), fn ($query) => $query->where('channel', $filters['channel']))
            ->when(isset($filters['status']), fn ($query) => $query->whereIn('status', (array) $filters['status']))
            ->orderByDesc('created_at');
    }

    /**
     * Helper for rendering templates.
     *
     * @param  array<string, mixed>  $variables
     */
    public function renderTemplate(MessageTemplate $template, array $variables): array
    {
        return $this->renderer->render($template, $variables);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function storeMessageRecord(
        array $payload,
        ?MessageChannel $channel,
        string $channelType,
        string $direction = 'OUTBOUND',
        string $status = 'QUEUED',
        ?string $providerOverride = null
    ): Message {
        $contact = $this->resolveContact($payload);
        $thread = $this->findOrCreateThread($contact, $channelType);

        $message = Message::create([
            'channel' => $channelType,
            'direction' => $direction,
            'provider' => $providerOverride ?? $channel?->provider,
            'from_address' => $payload['from'] ?? $channel?->from_email,
            'to_address' => $payload['to'],
            'subject' => $payload['subject'] ?? null,
            'body_text' => $payload['body_text'] ?? null,
            'body_html' => $payload['body_html'] ?? null,
            'status' => $status,
            'scheduled_at' => $payload['scheduled_at'] ?? null,
            'created_by' => $payload['user_id'] ?? null,
            'related_shoot_id' => $payload['related_shoot_id'] ?? null,
            'related_account_id' => $payload['related_account_id'] ?? null,
            'thread_id' => $thread->id,
            'message_channel_id' => $channel?->id,
        ]);

        $thread = $this->updateThreadForMessage($thread, $message);

        if ($channelType === 'SMS') {
            $message->loadMissing('thread.contact');

            if ($direction === 'OUTBOUND') {
                SmsMessageSent::dispatch($message);
            } else {
                SmsMessageReceived::dispatch($message);
            }

            SmsThreadUpdated::dispatch($thread);
        }

        return $message;
    }

    protected function findOrCreateThread(Contact $contact, string $channel): MessageThread
    {
        return DB::transaction(function () use ($contact, $channel) {
            return MessageThread::firstOrCreate(
                ['contact_id' => $contact->id, 'channel' => $channel],
                ['last_message_at' => now()]
            );
        });
    }

    protected function updateThreadForMessage(MessageThread $thread, Message $message): MessageThread
    {
        $snippet = Str::limit(trim($message->body_text ?? $message->body_html ?? ''), 200);

        $thread->fill([
            'last_message_at' => now(),
            'last_direction' => $message->direction,
            'last_snippet' => $snippet,
            'unread_for_user_ids_json' => $this->resolveUnreadRecipients($thread, $message),
        ])->save();

        return $thread->refresh()->load(['contact', 'assignedTo']);
    }

    protected function resolveUnreadRecipients(MessageThread $thread, Message $message): array
    {
        $current = collect($thread->unread_for_user_ids_json ?? []);

        if ($message->direction === 'OUTBOUND') {
            return $current
                ->reject(fn ($id) => (int) $id === (int) ($message->created_by ?? 0))
                ->values()
                ->all();
        }

        $roles = ['admin', 'superadmin', 'salesRep', 'sales_rep'];

        $userIds = User::query()
            ->whereIn('role', $roles)
            ->pluck('id')
            ->all();

        return array_values(array_unique($userIds));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolveContact(array $payload): Contact
    {
        $contact = Contact::firstOrCreate(
            [
                'email' => $payload['contact_email'] ?? $payload['to'],
                'phone' => $payload['contact_phone'] ?? $payload['to'],
            ],
            [
                'name' => $payload['contact_name'] ?? 'Unknown',
                'type' => $payload['contact_type'] ?? 'other',
            ]
        );

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolveEmailChannel(array $payload): MessageChannel
    {
        if (!empty($payload['channel_id'])) {
            return MessageChannel::findOrFail($payload['channel_id']);
        }

        $query = MessageChannel::ofType('EMAIL')
            ->orderByDesc('is_default');

        if (!empty($payload['user_id'])) {
            $query->where(function ($sub) use ($payload) {
                $sub->where(function ($inner) use ($payload) {
                    $inner->where('owner_scope', 'USER')->where('owner_id', $payload['user_id']);
                })->orWhere('owner_scope', 'GLOBAL');
            });
        }

        $channel = $query->first();

        if (!$channel) {
            throw new RuntimeException('No email channels configured.');
        }

        return $channel;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolveSmsNumber(array $payload): SmsNumber
    {
        if (!empty($payload['sms_number_id'])) {
            return SmsNumber::findOrFail($payload['sms_number_id']);
        }

        $number = SmsNumber::where('is_default', true)->first();

        if (!$number) {
            throw new RuntimeException('No SMS numbers configured.');
        }

        return $number;
    }

    protected function getEmailProvider(MessageChannel $channel): EmailProviderInterface
    {
        return match ($channel->provider) {
            'GOOGLE_OAUTH' => $this->googleOAuthProvider,
            'MAILCHIMP' => $this->mailchimpProvider,
            default => $this->localSmtpProvider,
        };
    }

    public function dispatchScheduledMessage(Message $message): Message
    {
        if ($message->channel !== 'EMAIL') {
            return $message;
        }

        $channel = $message->channelConfig ?? $this->resolveEmailChannel([
            'channel_id' => $message->message_channel_id,
            'user_id' => $message->created_by,
        ]);

        $provider = $this->getEmailProvider($channel);

        $providerMessageId = $provider->send($channel, [
            'to' => $message->to_address,
            'subject' => $message->subject ?? '',
            'html' => $message->body_html ?? '',
            'text' => $message->body_text ?? '',
        ]);

        $message->update([
            'status' => 'SENT',
            'sent_at' => now(),
            'provider_message_id' => $providerMessageId,
            'message_channel_id' => $channel->id,
        ]);

        return $message->refresh();
    }
}

