<?php

namespace App\Services\Messaging;

use App\Jobs\SendInternalMessageNotificationEmail;
use App\Models\Message;
use App\Models\Shoot;
use App\Models\User;
use App\Services\SystemEmails\EmailContextBuilder;
use App\Services\SystemEmails\SystemEmailOrchestrator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class InternalMessageNotificationService
{
    public function __construct(
        private readonly EmailContextBuilder $contextBuilder,
        private readonly SystemEmailOrchestrator $orchestrator,
    ) {
    }

    /**
     * Queue one independently retryable notification per eligible recipient.
     */
    public function queueFor(Message $message): void
    {
        foreach ($this->recipientsFor($message) as $recipient) {
            $pending = SendInternalMessageNotificationEmail::dispatch(
                (int) $message->id,
                (int) $recipient->id,
            )->afterCommit();

            // The test suite intentionally uses the sync queue globally. Keep
            // controller tests isolated while still allowing Queue::fake() to
            // inspect the exact jobs that would be queued in production.
            if (app()->runningUnitTests() && config('queue.default') === 'sync') {
                $pending->onConnection('database');
            }
        }
    }

    /**
     * @return Collection<int, User>
     */
    public function recipientsFor(Message $message): Collection
    {
        $message->loadMissing(['shoot.client', 'thread.contact.user', 'creator']);

        if (!$this->isNotifiableInternalMessage($message)) {
            return collect();
        }

        $senderId = (int) ($message->sender_user_id ?? $message->created_by ?? 0);
        $senderRole = $this->normalizedRole((string) $message->sender_role);
        $recipients = collect();

        if ($message->direction === 'INBOUND' && $senderRole === 'client') {
            $recipients = $this->activeAdminsAndSuperAdmins();

            $salesRep = $this->resolveAssignedSalesRep($message);
            if ($salesRep) {
                $recipients->push($salesRep);
            }
        } elseif ($message->direction === 'OUTBOUND' && $this->isStaffSender($message, $senderRole)) {
            $client = $this->resolveClient($message);
            if ($client) {
                $recipients->push($client);
            }
        }

        return $recipients
            ->filter(fn ($recipient) => $recipient instanceof User)
            ->reject(fn (User $recipient) => (int) $recipient->id === $senderId)
            ->filter(fn (User $recipient) => $this->canReceiveNotificationEmail($recipient))
            ->unique(fn (User $recipient) => (int) $recipient->id)
            ->values();
    }

    /**
     * Deliver one notification through the canonical email pipeline.
     *
     * @return array{sent: bool, duplicate: bool, dispatch: mixed, message_id: ?int}|array{sent: false, duplicate: false, skipped: true, message_id: null}
     */
    public function deliver(Message $message, User $recipient): array
    {
        $message->loadMissing(['shoot.client', 'thread.contact.user', 'creator']);

        $eligibleRecipientIds = $this->recipientsFor($message)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        if (!$eligibleRecipientIds->contains((int) $recipient->id)) {
            return [
                'sent' => false,
                'duplicate' => false,
                'skipped' => true,
                'message_id' => null,
            ];
        }

        $client = $this->resolveClient($message);
        $shoot = $message->shoot;
        $preview = $this->safePreview($message);
        $senderName = trim((string) ($message->creator?->name ?: $message->sender_display_name ?: $message->from_address ?: 'A dashboard user'));
        $senderRole = $this->roleLabel((string) $message->sender_role);
        $messageUrl = rtrim((string) config('app.frontend_url', 'https://reprodashboard.com'), '/')
            . '/messaging/email/inbox?message=' . $message->id;

        $payload = $this->contextBuilder->build([
            'recipient' => [
                'id' => $recipient->id,
                'name' => $recipient->name,
                'email' => $recipient->email,
            ],
            'account' => [
                'id' => $client?->id,
                'name' => $client?->name,
                'company_name' => $client?->company_name,
            ],
            'shoot' => [
                'id' => $shoot?->id,
                'address' => $shoot?->address,
                'city' => $shoot?->city,
                'state' => $shoot?->state,
                'scheduled_date' => $shoot?->scheduled_date,
            ],
            'links' => [
                'message' => $messageUrl,
                'dashboard' => rtrim((string) config('app.frontend_url', 'https://reprodashboard.com'), '/'),
            ],
            'meta' => [
                'recipient_type' => $this->recipientType($recipient),
                'sender_name' => $senderName,
                'sender_role' => $senderRole,
                'message_preview' => $preview,
                'message_subject' => trim((string) ($message->subject ?? '')),
                'internal_message_id' => (int) $message->id,
                'thread_id' => $message->thread_id ? (int) $message->thread_id : null,
                'event_version' => 'internal-message-' . $message->id,
            ],
        ]);

        return $this->orchestrator->send(
            'INTERNAL_MESSAGE_NOTIFICATION',
            $payload,
            [
                'to' => $recipient->email,
                'related_account_id' => $recipient->id,
                'related_shoot_id' => $shoot?->id,
                'send_source' => 'INTERNAL_MESSAGE_NOTIFICATION',
                'contact_email' => $recipient->email,
                'contact_name' => $recipient->name,
                'contact_type' => $this->recipientType($recipient),
                'tags_json' => ['internal-message-notification'],
            ],
            [
                'idempotency_key' => $this->idempotencyKey($message, $recipient),
                'retry_failed' => true,
                'canonical_metadata' => [
                    'internal_message_id' => (int) $message->id,
                    'conversation_thread_id' => $message->thread_id ? (int) $message->thread_id : null,
                    'recipient_user_id' => (int) $recipient->id,
                ],
            ],
        );
    }

    public function idempotencyKey(Message $message, User $recipient): string
    {
        return sprintf('internal-message-notification:%d:recipient:%d', $message->id, $recipient->id);
    }

    public function canReceiveNotificationEmail(User $user): bool
    {
        if (!$user->isAccountEligibleForAuthentication()) {
            return false;
        }

        $email = trim((string) $user->email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $metadata = is_array($user->metadata) ? $user->metadata : [];
        $preferences = is_array($metadata['preferences'] ?? null) ? $metadata['preferences'] : [];

        if (!array_key_exists('notificationEmail', $preferences)) {
            return true;
        }

        return filter_var($preferences['notificationEmail'], FILTER_VALIDATE_BOOLEAN);
    }

    private function isNotifiableInternalMessage(Message $message): bool
    {
        return $message->channel === 'EMAIL'
            && $message->provider === 'INTERNAL'
            && $message->send_source === 'MANUAL'
            && !empty($message->related_shoot_id);
    }

    /**
     * @return Collection<int, User>
     */
    private function activeAdminsAndSuperAdmins(): Collection
    {
        return User::query()
            ->whereNotNull('email')
            ->where(function ($query) {
                $query->whereIn('role', ['admin', 'superadmin'])
                    ->orWhereNotNull('secondary_roles');
            })
            ->get()
            ->filter(fn (User $user) => $this->userHasAnyRole($user, ['admin', 'superadmin']))
            ->filter(fn (User $user) => $user->isAccountEligibleForAuthentication())
            ->values();
    }

    private function resolveAssignedSalesRep(Message $message): ?User
    {
        $shoot = $message->shoot;
        $client = $this->resolveClient($message);
        $metadata = is_array($client?->metadata) ? $client->metadata : [];
        $candidateId = $shoot?->rep_id
            ?? $client?->created_by_id
            ?? $metadata['accountRepId']
            ?? $metadata['account_rep_id']
            ?? $metadata['repId']
            ?? $metadata['rep_id']
            ?? null;

        if (!is_numeric($candidateId)) {
            return null;
        }

        $candidate = User::query()->find((int) $candidateId);

        if (!$candidate || !$this->userHasAnyRole($candidate, ['salesrep'])) {
            return null;
        }

        return $candidate;
    }

    private function resolveClient(Message $message): ?User
    {
        if ($message->shoot?->client instanceof User) {
            return $message->shoot->client;
        }

        if ($message->related_account_id) {
            $account = User::query()->find((int) $message->related_account_id);
            if ($account && $this->normalizedRole((string) $account->role) === 'client') {
                return $account;
            }
        }

        $contactUser = $message->thread?->contact?->user;

        return $contactUser instanceof User && $this->normalizedRole((string) $contactUser->role) === 'client'
            ? $contactUser
            : null;
    }

    private function safePreview(Message $message): string
    {
        $source = trim((string) ($message->body_text ?: strip_tags((string) $message->body_html)));
        $decoded = html_entity_decode($source, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', strip_tags($decoded)) ?? '';

        return Str::limit(trim($normalized), 180, '…');
    }

    private function isStaffRole(string $role): bool
    {
        return in_array($role, ['admin', 'superadmin', 'salesrep', 'editingmanager'], true);
    }

    private function isStaffSender(Message $message, string $senderRole): bool
    {
        if ($this->isStaffRole($senderRole)) {
            return true;
        }

        return $message->creator instanceof User
            && $this->userHasAnyRole($message->creator, ['admin', 'superadmin', 'salesrep', 'editingmanager']);
    }

    private function recipientType(User $recipient): string
    {
        $roles = $this->normalizedRolesForUser($recipient);

        if (in_array('client', $roles, true)) {
            return 'client';
        }
        if (in_array('salesrep', $roles, true)) {
            return 'rep';
        }

        return 'admin';
    }

    private function roleLabel(string $role): string
    {
        return match ($this->normalizedRole($role)) {
            'salesrep' => 'Sales Rep',
            'superadmin' => 'Super Admin',
            'editingmanager' => 'Editing Manager',
            'admin' => 'Admin',
            'client' => 'Client',
            default => Str::headline($role ?: 'Dashboard User'),
        };
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function userHasAnyRole(User $user, array $roles): bool
    {
        $normalized = $this->normalizedRolesForUser($user);

        return collect($roles)
            ->map(fn ($role) => $this->normalizedRole($role))
            ->contains(fn ($role) => in_array($role, $normalized, true));
    }

    /**
     * @return array<int, string>
     */
    private function normalizedRolesForUser(User $user): array
    {
        return collect(array_merge([$user->role], is_array($user->secondary_roles) ? $user->secondary_roles : []))
            ->filter(fn ($role) => is_string($role) && trim($role) !== '')
            ->map(fn ($role) => $this->normalizedRole($role))
            ->unique()
            ->values()
            ->all();
    }

    private function normalizedRole(string $role): string
    {
        return strtolower(str_replace(['-', '_', ' '], '', $role));
    }
}
