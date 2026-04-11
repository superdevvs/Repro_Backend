<?php

namespace App\Services\Messaging;

use App\Events\EmailMessageReceived;
use App\Events\EmailMessageSent;
use App\Events\SmsMessageReceived;
use App\Events\SmsMessageSent;
use App\Events\SmsThreadUpdated;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\MessageTemplate;
use App\Models\MessageThread;
use App\Models\SmsNumber;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\Contracts\EmailProviderInterface;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Illuminate\Support\Str;

class MessagingService
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly Providers\TwilioSmsProvider $twilioProvider,
        private readonly Providers\CakemailProvider $cakemailProvider,
        private readonly Providers\LocalSmtpProvider $localSmtpProvider,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendEmail(array $payload): Message
    {
        $channel = $this->resolveEmailChannel($payload);
        $cc = $this->normalizeEmailAddresses($payload['cc'] ?? []);
        $bcc = $this->normalizeEmailAddresses($payload['bcc'] ?? []);
        $payload['cc'] = $cc;
        $payload['bcc'] = $bcc;

        $message = $this->storeMessageRecord($payload, $channel, 'EMAIL');

        $provider = $this->getEmailProvider($channel);
        $providerMessageId = $provider->send($channel, [
            'to' => $payload['to'],
            'cc' => $cc,
            'bcc' => $bcc,
            'subject' => $payload['subject'] ?? $message->subject,
            'html' => $payload['body_html'] ?? $message->body_html,
            'text' => $payload['body_text'] ?? $message->body_text,
            'reply_to' => $payload['reply_to'] ?? null,
            'attachments' => $payload['attachments'] ?? [],
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
     * Store an internal-only email message (no provider send).
     *
     * @param  array<string, mixed>  $payload
     */
    public function storeInternalEmail(array $payload, string $direction = 'INBOUND'): Message
    {
        $message = $this->storeMessageRecord(
            $payload,
            null,
            'EMAIL',
            direction: $direction,
            status: 'SENT',
            providerOverride: 'INTERNAL'
        );

        return $message->fresh();
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
            providerOverride: 'TWILIO'
        );

        try {
            $providerMessageId = $this->twilioProvider->send($number, [
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
                'failed_at' => now(),
                'error_message' => $e->getMessage(),
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
        $thread = $this->findOrCreateThread(
            $contact,
            $channelType,
            $this->resolveThreadShootId($payload, $channelType, $providerOverride)
        );

        $message = Message::create([
            'channel' => $channelType,
            'direction' => $direction,
            'provider' => $providerOverride ?? $channel?->provider,
            'from_address' => $payload['from'] ?? $channel?->from_email,
            'to_address' => $payload['to'],
            'cc_addresses_json' => $this->normalizeEmailAddresses($payload['cc'] ?? []),
            'bcc_addresses_json' => $this->normalizeEmailAddresses($payload['bcc'] ?? []),
            'reply_to_email' => $payload['reply_to'] ?? null,
            'subject' => $payload['subject'] ?? null,
            'body_text' => $payload['body_text'] ?? null,
            'body_html' => $payload['body_html'] ?? null,
            'attachments_json' => $payload['attachments_json'] ?? null,
            'status' => $status,
            'send_source' => $payload['send_source'] ?? 'MANUAL',
            'tags_json' => $payload['tags_json'] ?? null,
            'scheduled_at' => $payload['scheduled_at'] ?? null,
            'created_by' => $payload['user_id'] ?? null,
            'sender_user_id' => $payload['sender_user_id'] ?? null,
            'sender_account_id' => $payload['sender_account_id'] ?? null,
            'sender_role' => $payload['sender_role'] ?? null,
            'sender_display_name' => $payload['sender_display_name'] ?? null,
            'template_id' => $payload['template_id'] ?? null,
            'related_shoot_id' => $payload['related_shoot_id'] ?? null,
            'related_shoot_context_type' => $payload['related_shoot_context_type'] ?? null,
            'related_account_id' => $payload['related_account_id'] ?? null,
            'related_invoice_id' => $payload['related_invoice_id'] ?? null,
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

        // Dispatch email events for real-time notifications
        if ($channelType === 'EMAIL') {
            $message->loadMissing(['channelConfig', 'shoot', 'creator']);

            if ($direction === 'OUTBOUND') {
                EmailMessageSent::dispatch($message);
            } else {
                EmailMessageReceived::dispatch($message);
            }
        }

        return $message;
    }

    protected function findOrCreateThread(Contact $contact, string $channel, ?int $relatedShootId = null): MessageThread
    {
        return DB::transaction(function () use ($contact, $channel, $relatedShootId) {
            return MessageThread::firstOrCreate(
                [
                    'contact_id' => $contact->id,
                    'channel' => $channel,
                    'related_shoot_id' => $relatedShootId,
                ],
                [
                    'last_message_at' => now(),
                    'related_shoot_id' => $relatedShootId,
                ]
            );
        });
    }

    protected function updateThreadForMessage(MessageThread $thread, Message $message): MessageThread
    {
        $snippet = $this->extractThreadSnippet($message);

        $thread->fill([
            'related_shoot_id' => $this->resolvePersistentThreadShootId($thread, $message),
            'last_message_at' => $message->created_at ?? now(),
            'last_direction' => $message->direction,
            'last_snippet' => $snippet,
            'unread_for_user_ids_json' => $this->resolveUnreadRecipients($thread, $message),
        ])->save();

        return $thread->refresh()->load(['contact', 'assignedTo']);
    }

    public function rebuildThreadState(MessageThread $thread): MessageThread
    {
        $messages = $thread->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            $thread->fill([
                'last_message_at' => null,
                'last_direction' => null,
                'last_snippet' => null,
                'unread_for_user_ids_json' => [],
            ])->save();

            return $thread->refresh()->load(['contact', 'assignedTo']);
        }

        $latestMessage = $messages->last();
        $unreadRecipients = [];

        foreach ($messages as $message) {
            $unreadRecipients = $this->resolveUnreadRecipientsFromState($unreadRecipients, $message);
        }

        $thread->fill([
            'related_shoot_id' => $thread->related_shoot_id,
            'last_message_at' => $latestMessage?->created_at,
            'last_direction' => $latestMessage?->direction,
            'last_snippet' => $latestMessage ? $this->extractThreadSnippet($latestMessage) : null,
            'unread_for_user_ids_json' => $unreadRecipients,
        ])->save();

        return $thread->refresh()->load(['contact', 'assignedTo']);
    }

    public function backfillLinkedInternalContactThreadsByShoot(): void
    {
        $messages = Message::query()
            ->with(['thread', 'thread.contact'])
            ->where('channel', 'EMAIL')
            ->where('provider', 'INTERNAL')
            ->whereNotNull('related_shoot_id')
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            return;
        }

        $touchedThreadIds = [];

        DB::transaction(function () use ($messages, &$touchedThreadIds) {
            foreach ($messages as $message) {
                $currentThread = $message->thread;
                $contact = $currentThread?->contact;

                if (!$contact instanceof Contact) {
                    $contact = Contact::query()->find($message->thread?->contact_id);
                }

                if (!$contact instanceof Contact) {
                    continue;
                }

                $targetThread = MessageThread::firstOrCreate(
                    [
                        'contact_id' => $contact->id,
                        'channel' => 'EMAIL',
                        'related_shoot_id' => (int) $message->related_shoot_id,
                    ],
                    [
                        'assigned_to_user_id' => $currentThread?->assigned_to_user_id,
                        'status' => $currentThread?->status,
                        'tags_json' => $currentThread?->tags_json,
                        'last_message_at' => $currentThread?->last_message_at ?? $message->created_at ?? now(),
                        'created_at' => $currentThread?->created_at ?? $message->created_at ?? now(),
                        'updated_at' => $currentThread?->updated_at ?? $message->updated_at ?? now(),
                    ]
                );

                if ((int) $message->thread_id !== (int) $targetThread->id) {
                    $touchedThreadIds[] = (int) $message->thread_id;
                    $touchedThreadIds[] = (int) $targetThread->id;

                    $message->thread_id = $targetThread->id;
                    $message->save();
                }
            }
        });

        $threadIds = collect($touchedThreadIds)
            ->filter(fn ($id) => (int) $id > 0)
            ->unique()
            ->values();

        foreach ($threadIds as $threadId) {
            $thread = MessageThread::query()->find($threadId);

            if (!$thread) {
                continue;
            }

            if (!$thread->messages()->exists()) {
                $thread->delete();
                continue;
            }

            $this->rebuildThreadState($thread);
        }
    }

    protected function resolveUnreadRecipients(MessageThread $thread, Message $message): array
    {
        return $this->resolveUnreadRecipientsFromState($thread->unread_for_user_ids_json ?? [], $message);
    }

    /**
     * @param  array<int, int|string>  $currentUnread
     * @return array<int, int>
     */
    protected function resolveUnreadRecipientsFromState(array $currentUnread, Message $message): array
    {
        $current = collect($currentUnread)->map(fn ($id) => (int) $id);

        if ($message->direction === 'OUTBOUND') {
            return $current
                ->reject(fn ($id) => (int) $id === (int) ($message->created_by ?? 0))
                ->values()
                ->all();
        }

        if ($this->isLinkedInternalContactMessage($message)) {
            return $this->resolveLinkedInternalUnreadRecipients($message);
        }

        $roles = ['admin', 'superadmin', 'salesRep'];

        $userIds = User::query()
            ->whereIn('role', $roles)
            ->pluck('id')
            ->all();

        return array_values(array_unique($userIds));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolveThreadShootId(array $payload, string $channelType, ?string $providerOverride): ?int
    {
        if (!$this->isLinkedInternalContactPayload($payload, $channelType, $providerOverride)) {
            return null;
        }

        $relatedShootId = $payload['related_shoot_id'] ?? null;

        return is_numeric($relatedShootId) ? (int) $relatedShootId : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function isLinkedInternalContactPayload(array $payload, string $channelType, ?string $providerOverride): bool
    {
        return $channelType === 'EMAIL'
            && $providerOverride === 'INTERNAL'
            && !empty($payload['related_shoot_id']);
    }

    protected function resolveLinkedInternalUnreadRecipients(Message $message): array
    {
        $recipients = User::query()
            ->select(['id', 'role', 'secondary_roles'])
            ->get()
            ->filter(fn (User $user) => $this->userHasAnyNormalizedRole($user, ['admin', 'superadmin', 'editingmanager']))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $salesRepId = $this->resolveAssociatedSalesRepId(
            $message->related_shoot_id ? (int) $message->related_shoot_id : null,
            $message->related_account_id ? (int) $message->related_account_id : null,
        );

        if ($salesRepId !== null) {
            $recipients[] = $salesRepId;
        }

        return array_values(array_unique(array_map('intval', $recipients)));
    }

    protected function isLinkedInternalContactMessage(Message $message): bool
    {
        return $message->provider === 'INTERNAL' && !empty($message->related_shoot_id);
    }

    protected function extractThreadSnippet(Message $message): string
    {
        return Str::limit(trim($message->body_text ?? $message->body_html ?? ''), 200);
    }

    protected function resolvePersistentThreadShootId(MessageThread $thread, Message $message): ?int
    {
        if ($thread->related_shoot_id !== null) {
            return (int) $thread->related_shoot_id;
        }

        if (!$this->isLinkedInternalContactMessage($message)) {
            return null;
        }

        return $message->related_shoot_id ? (int) $message->related_shoot_id : null;
    }

    protected function resolveAssociatedSalesRepId(?int $shootId, ?int $accountId = null): ?int
    {
        $shoot = null;

        if ($shootId !== null) {
            $shoot = Shoot::query()
                ->with('client')
                ->find($shootId);
        }

        if ($shoot?->rep_id) {
            return (int) $shoot->rep_id;
        }

        $client = $shoot?->client;

        if (!$client && $accountId !== null) {
            $candidate = User::query()->find($accountId);
            if ($candidate && $this->normalizedRole($candidate->role) === 'client') {
                $client = $candidate;
            }
        }

        if (!$client instanceof User) {
            return null;
        }

        return $this->resolveClientRepId($client);
    }

    protected function resolveClientRepId(User $client): ?int
    {
        $metadata = is_array($client->metadata) ? $client->metadata : [];
        $repCandidate = $client->created_by_id
            ?? $metadata['accountRepId']
            ?? $metadata['account_rep_id']
            ?? $metadata['repId']
            ?? $metadata['rep_id']
            ?? null;

        return is_numeric($repCandidate) ? (int) $repCandidate : null;
    }

    /**
     * @param  array<int, string>  $roles
     */
    protected function userHasAnyNormalizedRole(User $user, array $roles): bool
    {
        $normalizedRoles = $this->normalizedRolesForUser($user);

        foreach ($roles as $role) {
            if (in_array($this->normalizedRole($role), $normalizedRoles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    protected function normalizedRolesForUser(User $user): array
    {
        $roles = [$user->role];

        if (is_array($user->secondary_roles)) {
            $roles = array_merge($roles, $user->secondary_roles);
        }

        return collect($roles)
            ->filter(fn ($role) => is_string($role) && trim($role) !== '')
            ->map(fn ($role) => $this->normalizedRole($role))
            ->unique()
            ->values()
            ->all();
    }

    protected function normalizedRole(?string $role): string
    {
        return strtolower(str_replace(['-', '_', ' '], '', (string) $role));
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
                'user_id' => $payload['contact_user_id'] ?? null,
                'account_id' => $payload['contact_account_id'] ?? null,
            ]
        );

        $updates = [];
        if (!empty($payload['contact_name']) && $contact->name !== $payload['contact_name']) {
            $updates['name'] = $payload['contact_name'];
        }
        if (!empty($payload['contact_type']) && $contact->type !== $payload['contact_type']) {
            $updates['type'] = $payload['contact_type'];
        }
        if (!empty($payload['contact_user_id']) && $contact->user_id !== $payload['contact_user_id']) {
            $updates['user_id'] = $payload['contact_user_id'];
        }
        if (!empty($payload['contact_account_id']) && $contact->account_id !== $payload['contact_account_id']) {
            $updates['account_id'] = $payload['contact_account_id'];
        }
        if ($updates) {
            $contact->fill($updates)->save();
        }

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
            $defaultName = config('mail.from.name', 'Cakemail');
            $defaultEmail = config('mail.from.address', 'noreply@reprophotos.com');

            $channel = MessageChannel::create([
                'type' => 'EMAIL',
                'provider' => 'CAKEMAIL',
                'display_name' => $defaultName,
                'from_email' => $defaultEmail,
                'is_default' => true,
                'owner_scope' => 'GLOBAL',
            ]);
        }

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
        return match (strtoupper((string) $channel->provider)) {
            'LOCAL_SMTP' => $this->localSmtpProvider,
            'CAKEMAIL' => $this->cakemailProvider,
            default => $this->logAndReturnCakeMailProvider($channel),
        };
    }

    protected function logAndReturnCakeMailProvider(MessageChannel $channel): EmailProviderInterface
    {
        Log::warning('Unknown email provider requested; defaulting to CakeMail.', [
            'channel_id' => $channel->id,
            'provider' => $channel->provider,
        ]);

        return $this->cakemailProvider;
    }

    /**
     * Get the Cakemail provider instance for direct access
     */
    public function getCakemailProvider(): Providers\CakemailProvider
    {
        return $this->cakemailProvider;
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
            'cc' => $this->normalizeEmailAddresses($message->cc_addresses_json ?? []),
            'bcc' => $this->normalizeEmailAddresses($message->bcc_addresses_json ?? []),
            'subject' => $message->subject ?? '',
            'html' => $message->body_html ?? '',
            'text' => $message->body_text ?? '',
            'reply_to' => $message->reply_to_email,
            'attachments' => $this->resolveScheduledAttachments($message),
        ]);

        $message->update([
            'status' => 'SENT',
            'sent_at' => now(),
            'provider_message_id' => $providerMessageId,
            'message_channel_id' => $channel->id,
        ]);

        return $message->refresh();
    }

    /**
     * @param  mixed  $emails
     * @return array<int, string>
     */
    protected function normalizeEmailAddresses(mixed $emails): array
    {
        return collect(is_array($emails) ? $emails : [])
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{filename: string, content: string, content_type: string}>
     */
    protected function resolveScheduledAttachments(Message $message): array
    {
        return collect($message->attachments_json ?? [])
            ->map(function ($attachment) {
                if (!is_array($attachment)) {
                    return null;
                }

                $disk = $attachment['disk'] ?? config('filesystems.default', 'local');
                $storagePath = $attachment['storage_path'] ?? null;
                if (!is_string($storagePath) || $storagePath === '' || !Storage::disk($disk)->exists($storagePath)) {
                    return null;
                }

                return [
                    'filename' => (string) ($attachment['name'] ?? 'attachment'),
                    'content' => Storage::disk($disk)->get($storagePath),
                    'content_type' => (string) ($attachment['type'] ?? 'application/octet-stream'),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
