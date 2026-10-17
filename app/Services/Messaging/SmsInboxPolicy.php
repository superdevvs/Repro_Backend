<?php

namespace App\Services\Messaging;

use App\Models\Message;
use App\Models\MessageThread;
use App\Models\User;
use App\Support\LockedWrite;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Persist conversation intent once, rather than inspecting every message on inbox reads. */
class SmsInboxPolicy
{
    public function isAdmin(?User $user): bool
    {
        return $user && in_array($this->role($user), ['admin', 'superadmin'], true);
    }

    public function canSms(?User $user): bool
    {
        return $user && $user->isAccountEligibleForAuthentication()
            && in_array($this->role($user), ['admin', 'superadmin', 'editingmanager', 'salesrep', 'photographer'], true)
            && app(\App\Services\RolePermissionService::class)->userCan($user, 'messaging-sms', 'view');
    }

    public function canView(?User $user, MessageThread $thread): bool
    {
        return $thread->channel === 'SMS' && $this->canSms($user)
            && ($thread->sms_inbox_active || $this->isAdmin($user))
            && ($this->role($user) !== 'photographer' || $this->forUser(MessageThread::query()->whereKey($thread->id), $user)->exists());
    }

    public function canSharedInbox(?User $user): bool
    {
        return $this->canSms($user) && $this->role($user) !== 'photographer';
    }

    public function forUser(Builder $query, User $user): Builder
    {
        if ($this->role($user) !== 'photographer') {
            return $query;
        }
        $phones = array_values(array_filter(array_unique([$this->phone((string) $user->phone), $this->phone((string) $user->phonenumber)])));

        return $query->whereNull('sms_group_id')->whereHas('contact', function ($contact) use ($user, $phones) {
            $contact->where('user_id', $user->id);
            foreach ($phones as $phone) {
                $contact->orWhereRaw($this->phoneSql('phone').' = ?', [$phone]);
            }
        });
    }

    public function photographerRecipients(MessageThread $thread): array
    {
        if (! $thread->sms_inbox_active || $thread->sms_group_id) {
            return [];
        }
        $contact = $thread->contact;
        $phone = $this->phone((string) $contact?->phone);

        return User::query()->where(fn ($roles) => $roles->where('role', 'photographer')->orWhereJsonContains('secondary_roles', 'photographer'))->where(function ($users) use ($contact, $phone) {
            $users->where('id', $contact?->user_id ?? 0);
            if ($phone) {
                $users->orWhereRaw($this->phoneSql('phone').' = ?', [$phone])
                    ->orWhereRaw($this->phoneSql('phonenumber').' = ?', [$phone]);
            }
        })->get()->filter(fn ($user) => $this->canSms($user))->pluck('id')->all();
    }

    public function visibleMessages(Builder $query): Builder
    {
        return $query->where('channel', 'SMS')->where(fn ($q) => $q->where('hidden_from_inbox', false)->orWhereNull('hidden_from_inbox'));
    }

    public function kind(Message $message): string
    {
        if ($message->channel !== 'SMS' || $message->hidden_from_inbox) {
            return 'hidden';
        }
        if ($message->direction === 'INBOUND') {
            return app(SmsReactionParser::class)->parse((string) $message->body_text) ? 'reaction' : 'incoming';
        }
        $intent = data_get($message->metadata, 'sms_inbox.kind');
        if ($intent === 'manual') {
            return 'manual';
        }
        if ($intent === 'system') {
            return 'system';
        }

        // Legacy MANUAL also includes one-click shoot notifications. Only a
        // free-form staff message starts a conversation; templates never do.
        return strtoupper((string) $message->send_source) === 'MANUAL'
            && $message->created_by && ! $message->template_id && ! $message->related_invoice_id
            && ! data_get($message->metadata, 'manual_delivery')
            && in_array(data_get($message->metadata, 'sms_notification.category'), [null, 'other'], true)
            ? 'manual' : 'system';
    }

    public function record(Message $message): void
    {
        if (! $message->thread_id || ! in_array($kind = $this->kind($message), ['incoming', 'manual'], true)) {
            return;
        }
        LockedWrite::run(fn () => DB::table('message_threads')->where('id', $message->thread_id)->where('channel', 'SMS')->update([
            'sms_inbox_active' => true,
            'sms_needs_reply' => $kind === 'incoming',
        ]), 'sms.inbox.record');
    }

    /** Shared by HTTP, realtime and badges; editing managers retain broad access. */
    public function scope(Builder $query, string $scope = 'inbox'): Builder
    {
        return $query->where('sms_inbox_active', $scope !== 'system')
            ->whereHas('messages', fn ($q) => $this->visibleMessages($q));
    }

    public function channels(MessageThread $thread, ?Message $message = null): array
    {
        if ($message?->hidden_from_inbox) {
            return [];
        }
        $channels = [new PrivateChannel($thread->sms_inbox_active ? 'sms.thread-list' : 'sms.system-thread-list')];
        if ($message && $thread->sms_inbox_active) {
            $channels[] = new PrivateChannel('sms.thread.'.$thread->id);
        }
        foreach ($this->photographerRecipients($thread) as $userId) {
            $channels[] = new PrivateChannel('sms.user.'.$userId);
        }

        return $channels;
    }

    /** SMS identity is the two telephone endpoints, independent of an email address. */
    public function threadForPair(string $person, string $business): ?MessageThread
    {
        $person = $this->phone($person);
        $business = $this->phone($business);
        if (! $person || ! $business) {
            return null;
        }
        $query = Message::query()->where('channel', 'SMS')
            ->whereHas('thread', fn ($q) => $q->where('channel', 'SMS')->whereNull('sms_group_id'))
            ->where(fn ($q) => $this->pair($q, $person, $business))
            ->orderByDesc('created_at')->orderByDesc('id');
        $message = (clone $query)->whereHas('thread', fn ($q) => $q->where('sms_inbox_active', true))->first()
            ?? $query->first();

        return $message?->thread;
    }

    /** Include legacy split reminders only for exact person/business endpoints. */
    public function history(MessageThread $thread): Collection
    {
        $messages = $this->visibleMessages($thread->messages()->getQuery())->orderBy('created_at')->orderBy('id')->get();
        if ($thread->sms_group_id || $messages->isEmpty()) {
            return $messages;
        }
        $pairs = [];
        foreach ($messages as $message) {
            $from = $this->phone((string) $message->from_address);
            $to = $this->phone((string) $message->to_address);
            if ($from && $to) {
                $person = $message->direction === 'INBOUND' ? $from : $to;
                $business = $message->direction === 'INBOUND' ? $to : $from;
                $pairs[$person.'|'.$business] = [$person, $business];
            }
        }
        if ($pairs === []) {
            return $messages;
        }
        $context = $this->visibleMessages(Message::query())->where('direction', 'OUTBOUND')
            ->where('thread_id', '!=', $thread->id)
            ->whereHas('thread', fn ($q) => $q->where('channel', 'SMS')->whereNull('sms_group_id')->where('sms_inbox_active', false))
            ->where(function ($q) use ($pairs) {
                foreach (array_slice(array_values($pairs), 0, 20) as [$person, $business]) {
                    $q->orWhere(fn ($pair) => $pair->whereRaw($this->phoneSql('from_address').' = ?', [$business])
                        ->whereRaw($this->phoneSql('to_address').' = ?', [$person]));
                }
            })->orderByDesc('created_at')->orderByDesc('id')->limit(2000)->get();

        return $messages->concat($context)->unique('id')->sort(function (Message $left, Message $right) {
            return ($left->created_at <=> $right->created_at) ?: ($left->id <=> $right->id);
        })->values();
    }

    /** Search the same legacy reminder context that history() is allowed to display. */
    public function searchContext(Builder $query, string $pattern): Builder
    {
        return $query->whereNull('sms_group_id')->whereHas('messages', function ($messages) use ($pattern) {
            $this->visibleMessages($messages)->whereExists(function ($context) use ($pattern) {
                $business = "(CASE WHEN messages.direction = 'INBOUND' THEN messages.to_address ELSE messages.from_address END)";
                $person = "(CASE WHEN messages.direction = 'INBOUND' THEN messages.from_address ELSE messages.to_address END)";
                $context->selectRaw('1')->from('messages as sms_context')
                    ->join('message_threads as context_threads', 'context_threads.id', '=', 'sms_context.thread_id')
                    ->whereColumn('sms_context.thread_id', '!=', 'messages.thread_id')
                    ->where('context_threads.channel', 'SMS')->where('context_threads.sms_inbox_active', false)->whereNull('context_threads.sms_group_id')
                    ->where('sms_context.channel', 'SMS')->where('sms_context.direction', 'OUTBOUND')
                    ->where(fn ($visible) => $visible->where('sms_context.hidden_from_inbox', false)->orWhereNull('sms_context.hidden_from_inbox'))
                    ->whereRaw("LOWER(COALESCE(sms_context.body_text, '')) LIKE LOWER(?) ESCAPE '\\'", [$pattern])
                    ->whereRaw($this->phoneSql('sms_context.from_address').' = '.$this->phoneSql($business))
                    ->whereRaw($this->phoneSql('sms_context.to_address').' = '.$this->phoneSql($person));
            });
        });
    }

    private function pair(Builder $query, string $person, string $business): void
    {
        $query->where(fn ($q) => $q->whereRaw($this->phoneSql('from_address').' = ?', [$person])
            ->whereRaw($this->phoneSql('to_address').' = ?', [$business]))
            ->orWhere(fn ($q) => $q->whereRaw($this->phoneSql('from_address').' = ?', [$business])
                ->whereRaw($this->phoneSql('to_address').' = ?', [$person]));
    }

    private function phone(string $value): ?string
    {
        if (! preg_match('/^[+0-9 ().-]+$/D', $value)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $value);
        $digits = strlen($digits) === 10 ? '1'.$digits : $digits;

        return strlen($digits) >= 11 && strlen($digits) <= 15 ? $digits : null;
    }

    private function phoneSql(string $column): string
    {
        $digits = $column;
        foreach (['+', ' ', '(', ')', '.', '-'] as $separator) {
            $digits = "REPLACE({$digits}, '{$separator}', '')";
        }

        return "(CASE WHEN LENGTH({$digits}) = 10 THEN '1' || {$digits} ELSE {$digits} END)";
    }

    /** One-time migration/recovery helper; no message bodies or unread receipts are rewritten. */
    public function backfill(): void
    {
        $states = [];
        Message::query()->where('channel', 'SMS')->whereNotNull('thread_id')->chunkById(500, function ($messages) use (&$states) {
            foreach ($messages as $message) {
                $kind = $this->kind($message);
                if (! in_array($kind, ['incoming', 'manual'], true)) {
                    continue;
                }
                $key = ($message->created_at?->format('Y-m-d H:i:s.u') ?? '').str_pad((string) $message->id, 20, '0', STR_PAD_LEFT);
                if (! isset($states[$message->thread_id]) || strcmp($key, $states[$message->thread_id]['key']) > 0) {
                    $states[$message->thread_id] = ['key' => $key, 'needs_reply' => $kind === 'incoming'];
                }
            }
        });
        foreach ($states as $threadId => $state) {
            LockedWrite::run(fn () => DB::table('message_threads')->where('id', $threadId)->where('channel', 'SMS')->update([
                'sms_inbox_active' => true,
                'sms_needs_reply' => $state['needs_reply'],
            ]), 'sms.inbox.backfill');
        }
    }

    private function role(User $user): string
    {
        $roles = array_map(function ($role) {
            $role = strtolower(str_replace(['_', '-', ' '], '', trim((string) $role)));

            return in_array($role, ['rep', 'representative'], true) ? 'salesrep' : $role;
        }, [$user->role, ...((array) $user->secondary_roles)]);
        foreach (['superadmin', 'admin', 'editingmanager', 'salesrep', 'photographer'] as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return $roles[0] ?? '';
    }
}
