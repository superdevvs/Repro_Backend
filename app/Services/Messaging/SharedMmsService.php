<?php

namespace App\Services\Messaging;

use App\Models\Contact;
use App\Models\SmsNumber;
use App\Models\SmsSharedGroup;
use App\Models\SmsSharedMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SharedMmsService
{
    public function __construct(private TelnyxSharedMmsGateway $gateway, private SmsInboxPolicy $policy) {}

    public function eligible(?User $user): bool { return $this->policy->canSharedInbox($user); }
    public function enabled(): bool { return (bool) config('shared_mms.enabled', false); }
    private function lock(string $key, callable $run): mixed
    {
        // Use a distributed store in deployment; array locks are test-only.
        return Cache::store((string) config('shared_mms.lock_store', 'database'))->lock('shared-mms:'.hash('sha256', $key), 180)->block(5, $run);
    }
    public function authorize(User $user, ?SmsSharedGroup $group = null): void
    {
        abort_unless($this->eligible($user), 403, 'Shared group messaging is unavailable for this account.');
        if ($group) {
            abort_unless($group->members()->whereKey($user->id)->exists(), 404, 'Conversation unavailable.');
        }
    }
    private function numberAllowed(SmsNumber $number): bool
    {
        return $number->provider === 'TELNYX' && in_array($number->id, array_map('intval', (array) config('shared_mms.sender_ids', [])), true);
    }
    public function capabilities(User $user): array
    {
        $this->authorize($user);
        $senders = [];
        if ($this->enabled()) {
            $numbers = SmsNumber::where('provider', 'TELNYX')->whereIn('id', array_slice((array) config('shared_mms.sender_ids', []), 0, 10))
                ->when(! $this->policy->isSuperadmin($user), fn ($q) => $q->where('is_default', true))->orderByDesc('is_default')->get();
            foreach ($numbers as $number) {
                try {
                    $this->gateway->sender($number);
                    $senders[] = ['id' => $number->id, 'phone_number' => $number->phone_number, 'label' => $number->label ?: $number->phone_number, 'is_default' => (bool) $number->is_default];
                } catch (\Throwable) { /* Capability failure must not become a usable sender. */ }
            }
        }
        return ['available' => $senders !== [], 'reason' => $senders ? null : 'Shared group MMS is awaiting a verified business line.',
            'min_recipients' => 2, 'max_recipients' => 8, 'text_limit' => 1200, 'media_supported' => false,
            'participants_editable' => false, 'regions' => ['US', 'CA'], 'senders' => $senders];
    }
    public function participants(array $phones, string $business): array
    {
        $phones = array_map(fn ($p) => trim((string) $p), $phones);
        if (count($phones) < 2 || count($phones) > 8 || count(array_unique($phones)) !== count($phones)
            || in_array($business, $phones, true) || collect($phones)->contains(fn ($p) => ! preg_match('/^\+1[2-9][0-9]{9}$/D', $p))) {
            throw ValidationException::withMessages(['participants' => 'Choose 2 to 8 distinct US or Canadian mobile numbers, excluding the business line.']);
        }
        sort($phones, SORT_STRING);
        return $phones;
    }
    public function participantKey(string $business, array $participants): string
    {
        sort($participants, SORT_STRING);
        return hash('sha256', $business.'|'.implode('|', $participants));
    }
    private function checkSending(User $user, SmsNumber $number, array $participants, bool $creation): void
    {
        abort_unless($this->enabled() && $this->numberAllowed($number), 503, 'Shared group MMS is not enabled for this line.');
        if ($creation) { abort_unless($this->policy->isSuperadmin($user) || $number->is_default, 403, 'This sending number is unavailable.'); }
        abort_if(filter_var(config('services.telnyx.sms_paused', false), FILTER_VALIDATE_BOOLEAN) || Cache::get('ops.telnyx_sms_paused') === true, 503, 'Sending messages is paused.');
        foreach ($participants as $phone) {
            abort_unless(app(OutboundDeliveryGuard::class)->allows('SMS', $phone), 403, 'External delivery is disabled for this recipient.');
            if ($this->optedOut($phone)) { throw ValidationException::withMessages(['participants' => 'A participant has opted out. This group cannot receive new messages.']); }
        }
        $sender = $this->gateway->sender($number);
        $this->gateway->recipients($participants, $sender);
    }
    private function optedOut(string $phone): bool
    {
        if (DB::table('sms_shared_opt_outs')->where('phone_number', $phone)->exists()) { return true; }
        $digits = [substr($phone, 1), substr($phone, -10)];
        $expression = static function (string $column): string {
            foreach (['+', ' ', '(', ')', '.', '-'] as $separator) { $column = "REPLACE({$column}, '{$separator}', '')"; }
            return $column;
        };
        return Contact::where('sms_opt_out', true)->whereRaw($expression('phone').' IN (?, ?)', $digits)->exists()
            || User::where('sms_opt_out', true)->where(fn ($q) => $q->whereRaw($expression('phone').' IN (?, ?)', $digits)
                ->orWhereRaw($expression('phonenumber').' IN (?, ?)', $digits))->exists();
    }
    public function create(User $user, array $data): SmsSharedMessage
    {
        $this->authorize($user);
        $number = SmsNumber::findOrFail($data['sms_number_id']);
        $participants = $this->participants($data['participants'], (string) $number->phone_number);
        $members = array_values(array_unique([$user->id, ...array_map('intval', $data['member_user_ids'] ?? [])]));
        sort($members, SORT_NUMERIC);
        $title = trim($data['title'] ?? '') ?: 'Group conversation';
        $body = trim($data['body_text']);
        $hash = hash('sha256', json_encode([$number->id, $participants, $members, $title, $body]));
        $key = strtolower($data['request_key']);
        return $this->lock('request:'.$user->id.':'.$key, function () use ($user, $number, $participants, $members, $title, $body, $hash, $key) {
            if ($existing = SmsSharedMessage::where('created_by', $user->id)->where('request_key', $key)->first()) {
                return $this->replay($user, $existing, $hash);
            }
            foreach ($members as $id) {
                abort_unless(($member = User::find($id)) && $this->eligible($member), 422, 'Every staff member must have shared messaging access.');
            }
            $participantKey = $this->participantKey($number->phone_number, $participants);
            // Never disclose or join an existing private staff membership merely
            // because the caller knows the external participant phone numbers.
            if ($existing = SmsSharedGroup::where('participant_key', $participantKey)->first()) {
                $this->authorize($user, $existing);
                abort_if(array_diff($members, $existing->members()->pluck('users.id')->all()) !== [], 409, 'This conversation already exists. Its staff membership cannot be changed here.');
            }
            $this->checkSending($user, $number, $participants, true);
            $message = $this->lock('group:'.$participantKey, fn () => DB::transaction(function () use ($user, $number, $participants, $members, $title, $body, $hash, $key, $participantKey) {
                $group = SmsSharedGroup::where('participant_key', $participantKey)->first();
                if ($group) {
                    $this->authorize($user, $group);
                    abort_if(array_diff($members, $group->members()->pluck('users.id')->all()) !== [], 409, 'This conversation already exists. Its staff membership cannot be changed here.');
                }
                else {
                    $group = SmsSharedGroup::create(['sms_number_id' => $number->id, 'business_number' => $number->phone_number,
                        'participant_key' => $participantKey, 'participants' => $participants, 'title' => $title, 'created_by' => $user->id]);
                    $group->members()->attach($members);
                }
                return $this->reserve($user, $group, $key, $hash, $body);
            }));
            return $this->deliver($message);
        });
    }
    public function send(User $user, SmsSharedGroup $group, array $data): SmsSharedMessage
    {
        $this->authorize($user, $group);
        $key = strtolower($data['request_key']); $body = trim($data['body_text']);
        $hash = hash('sha256', json_encode([$group->id, $body]));
        return $this->lock('request:'.$user->id.':'.$key, function () use ($user, $group, $key, $body, $hash) {
            if ($existing = SmsSharedMessage::where('created_by', $user->id)->where('request_key', $key)->first()) {
                return $this->replay($user, $existing, $hash);
            }
            $number = $group->number;
            abort_unless($number && $number->phone_number === $group->business_number, 422, 'The conversation business line has changed.');
            $this->checkSending($user, $number, $group->participants, false);
            return $this->deliver(DB::transaction(fn () => $this->reserve($user, $group, $key, $hash, $body)));
        });
    }
    private function replay(User $user, SmsSharedMessage $message, string $hash): SmsSharedMessage
    {
        $this->authorize($user, $message->group);
        abort_unless(hash_equals($message->request_hash, $hash), 409, 'This request key was already used for different message details.');
        return $message;
    }
    private function reserve(User $user, SmsSharedGroup $group, string $key, string $hash, string $body): SmsSharedMessage
    {
        // Eligibility reads can take time; recheck account/membership and opt-outs
        // immediately before reserving a send rather than trusting the old model.
        $freshUser = $user->fresh();
        abort_unless($freshUser, 403);
        $this->authorize($freshUser, $group);
        abort_unless($this->enabled(), 503, 'Shared group MMS is disabled.');
        abort_if(filter_var(config('services.telnyx.sms_paused', false), FILTER_VALIDATE_BOOLEAN) || Cache::get('ops.telnyx_sms_paused') === true, 503, 'Sending messages is paused.');
        foreach ($group->participants as $phone) {
            abort_unless(app(OutboundDeliveryGuard::class)->allows('SMS', $phone), 403, 'External delivery is disabled for this recipient.');
            if ($this->optedOut($phone)) { throw ValidationException::withMessages(['participants' => 'A participant has opted out. This group cannot receive new messages.']); }
        }
        $message = SmsSharedMessage::create(['group_id' => $group->id, 'created_by' => $user->id, 'request_key' => $key, 'request_hash' => $hash,
            'direction' => 'OUTBOUND', 'from_number' => $group->business_number, 'body_text' => $body, 'status' => 'sending',
            'recipient_statuses' => array_map(fn ($p) => ['phone_number' => $p, 'status' => 'unconfirmed'], $group->participants), 'provider_attempted_at' => now()]);
        $group->update(['last_message_at' => now(), 'last_snippet' => Str::limit($body, 190)]);
        return $message;
    }
    private function deliver(SmsSharedMessage $message): SmsSharedMessage
    {
        $group = $message->group;
        $result = $this->gateway->send($group->business_number, $group->participants, $message->body_text, $message->id);
        return $this->lock('message:'.$message->id, function () use ($message, $result) {
            $message->refresh();
            // A webhook may have already established the provider result.
            if ($message->status !== 'sending') { return $message; }
            $data = $result['data'] ?? [];
            $updates = ['status' => $result['status']];
            if ($result['status'] === 'accepted') {
                $updates += ['provider_message_id' => $data['id'], 'provider_group_message_id' => $data['group_message_id'] ?? $data['id']];
                $updates['recipient_statuses'] = $this->statuses($message->recipient_statuses, $data['to'] ?? [], null, 'response');
            }
            $message->update($updates);
            return $message->fresh();
        });
    }
    public function presentGroup(SmsSharedGroup $g, User $user): array
    {
        return ['id' => $g->id, 'kind' => 'shared_mms', 'title' => $g->title, 'business_number' => $g->business_number,
            'sms_number_id' => $g->sms_number_id, 'participants' => $g->participants,
            'member_user_ids' => $g->members()->pluck('users.id')->all(), 'created_by' => $g->created_by,
            'last_message_at' => $g->last_message_at?->toIso8601String(), 'last_snippet' => $g->last_snippet, 'unread_count' => $this->unreadCount($user, $g->id),
            'can_send' => $this->enabled() && $this->eligible($user) && $g->number && $this->numberAllowed($g->number)];
    }
    public function presentMessage(SmsSharedMessage $m): array
    {
        return ['id' => $m->id, 'group_id' => $m->group_id, 'request_key' => $m->request_key, 'direction' => $m->direction,
            'from' => $m->from_number, 'body_text' => $m->body_text, 'created_at' => $m->created_at->toIso8601String(),
            'status' => $m->status === 'sending' && $m->created_at->lt(now()->subMinute()) ? 'uncertain' : $m->status,
            'recipients' => array_map(fn ($v) => ['phone_number' => $v['phone_number'], 'status' => $v['status']], $m->recipient_statuses),
            'media_count' => $m->media_count];
    }
    public function envelope(SmsSharedMessage $m, User $user): array
    {
        return ['data' => $this->presentGroup($m->group, $user), 'message' => $this->presentMessage($m)];
    }
    public function unreadCount(User $user, string $groupID): int
    {
        if (! $this->eligible($user)) { return 0; }
        return DB::table('sms_shared_messages as message')->join('sms_shared_group_members as member', 'member.group_id', '=', 'message.group_id')
            ->where('member.user_id', $user->id)->where('message.group_id', $groupID)->where('message.direction', 'INBOUND')
            ->whereColumn('message.sequence', '>', 'member.last_read_sequence')->count();
    }
    public function unreadGroupCount(User $user): int
    {
        if (! $this->eligible($user)) { return 0; }
        return DB::table('sms_shared_group_members as member')->where('member.user_id', $user->id)->whereExists(function ($q) {
            $q->selectRaw('1')->from('sms_shared_messages as message')->whereColumn('message.group_id', 'member.group_id')
                ->where('message.direction', 'INBOUND')->whereColumn('message.sequence', '>', 'member.last_read_sequence');
        })->count();
    }
    public function messageUnreadFor(User $user, SmsSharedMessage $message): bool
    {
        return $this->eligible($user) && $message->direction === 'INBOUND'
            && DB::table('sms_shared_group_members')->where('group_id', $message->group_id)->where('user_id', $user->id)
                ->where('last_read_sequence', '<', $message->sequence)->exists();
    }
    public function markRead(User $user, SmsSharedGroup $group, string $messageID): int
    {
        $this->authorize($user, $group);
        $marker = $group->messages()->whereKey($messageID)->firstOrFail();
        DB::transaction(function () use ($user, $group, $marker) {
            $freshUser = $user->fresh(); abort_unless($freshUser, 403);
            $this->authorize($freshUser, $group);
            // Only the caller's supplied, existing message is covered. A later
            // arriving message stays unread, and old callbacks never rewind it.
            DB::table('sms_shared_group_members')->where('group_id', $group->id)->where('user_id', $user->id)
                ->where('last_read_sequence', '<', $marker->sequence)
                ->update(['last_read_sequence' => $marker->sequence, 'last_read_message_id' => $marker->id]);
        });
        return $this->unreadCount($user, $group->id);
    }
    /** Return null only for non-group traffic, preserving the existing SMS path. */
    public function webhook(array $event, ?string $hint = null): ?array
    {
        $p = data_get($event, 'data.payload', []); $type = (string) data_get($event, 'data.event_type', '');
        if (! is_array($p)) { return $hint ? ['status' => 'ignored'] : null; }
        if ($type === 'message.received' && ! empty($p['cc'])) { return $this->inbound($event); }
        $provider = (string) ($p['group_message_id'] ?? $p['id'] ?? '');
        $message = $hint ? SmsSharedMessage::find($hint) : (strlen($provider) <= 128 && $provider !== '' ? SmsSharedMessage::where('direction', 'OUTBOUND')
            ->where(fn ($q) => $q->where('provider_group_message_id', $provider)->orWhere('provider_message_id', $provider))->first() : null);
        if (! $message) { return $hint || ! empty($p['group_message_id']) ? ['status' => 'unmatched_group'] : null; }
        if (! in_array($type, ['message.sent', 'message.finalized', 'message.failed'], true)) { return ['status' => 'ignored']; }
        $eventID = (string) data_get($event, 'data.id', '');
        $from = is_array($p['from'] ?? null) ? (string) data_get($p, 'from.phone_number', '') : (string) ($p['from'] ?? '');
        $at = $this->timestamp(data_get($event, 'data.occurred_at'));
        if (! $at || ! $this->identifier($eventID) || ! $this->identifier($provider) || $message->direction !== 'OUTBOUND'
            || $from !== $message->group->business_number || ! is_array($p['to'] ?? null)) { return ['status' => 'ignored']; }
        return $this->lock('message:'.$message->id, fn () => DB::transaction(function () use ($message, $eventID, $provider, $p, $at, $type) {
            $message->refresh();
            if (DB::table('sms_shared_webhook_events')->where('event_id', $eventID)->exists()) { return ['status' => 'duplicate']; }
            if ($message->provider_group_message_id && $message->provider_group_message_id !== $provider
                && $message->provider_message_id !== $provider) { return ['status' => 'ignored']; }
            $known = array_column($message->recipient_statuses, 'phone_number');
            $recipientNumbers = array_column($p['to'], 'phone_number');
            if (! $recipientNumbers || array_diff($recipientNumbers, $known)) { return ['status' => 'ignored']; }
            $statuses = $this->statuses($message->recipient_statuses, $p['to'], $at, $type);
            $message->update(['provider_group_message_id' => $message->provider_group_message_id ?: $provider,
                'status' => 'accepted', 'recipient_statuses' => $statuses]);
            DB::table('sms_shared_webhook_events')->insert(['event_id' => $eventID, 'message_id' => $message->id, 'created_at' => now()]);
            return ['status' => 'updated'];
        }));
    }
    private function statuses(array $current, array $updates, ?CarbonImmutable $at, string $type): array
    {
        $rank = ['unconfirmed' => 0, 'queued' => 1, 'sent' => 2, 'unknown' => 3, 'failed' => 4, 'delivered' => 4];
        foreach ($current as &$recipient) {
            foreach ($updates as $update) {
                if (! is_array($update) || ($update['phone_number'] ?? '') !== $recipient['phone_number']) { continue; }
                $raw = strtolower((string) ($update['status'] ?? ''));
                $status = match ($raw) {
                    'queued', 'sent', 'delivered', 'unknown' => $raw,
                    'sending_failed', 'delivery_failed', 'failed' => 'failed',
                    'delivery_unconfirmed' => 'unknown',
                    default => $type === 'message.sent' ? 'sent' : 'unconfirmed',
                };
                $previous = $this->timestamp($recipient['occurred_at'] ?? null);
                if ($rank[$status] < $rank[$recipient['status']] || ($at && $previous && $at->lt($previous))) { continue; }
                $recipient['status'] = $status;
                if ($at) { $recipient['occurred_at'] = $at->toIso8601String(); }
            }
        }
        unset($recipient);
        return $current;
    }
    private function inbound(array $event): array
    {
        $p = data_get($event, 'data.payload');
        $id = (string) ($p['id'] ?? ''); $eventID = (string) data_get($event, 'data.id', '');
        $from = (string) data_get($p, 'from.phone_number', '');
        if (! $this->identifier($id) || ! $this->identifier($eventID) || ! is_array($p['cc']) || count($p['cc']) > 9 || ! is_array($p['to'] ?? null)
            || count($p['to']) > 9 || mb_strlen((string) ($p['text'] ?? '')) > 10000 || ($p['type'] ?? '') !== 'MMS') { return ['status' => 'ignored']; }
        $all = [$from, ...array_map(fn ($v) => is_string($v) ? $v : '', $p['cc']), ...array_column($p['to'], 'phone_number')];
        $all = array_values(array_unique($all));
        if (collect($all)->contains(fn ($v) => ! preg_match('/^\+1[2-9][0-9]{9}$/D', $v))) { return ['status' => 'ignored']; }
        $group = null;
        foreach (array_column($p['to'], 'phone_number') as $business) {
            if ($business === $from) { continue; }
            $participants = array_values(array_diff($all, [$business]));
            if (count($participants) < 2 || count($participants) > 8) { continue; }
            $candidate = SmsSharedGroup::where('participant_key', $this->participantKey($business, $participants))->first();
            if ($candidate) { if ($group) { return ['status' => 'ambiguous_group']; } $group = $candidate; }
        }
        if (! $group || ! in_array($from, $group->participants, true)) { return ['status' => 'unmatched_group']; }
        return $this->lock('inbound:'.$id, fn () => DB::transaction(function () use ($group, $id, $eventID, $from, $p, $event) {
            if (SmsSharedMessage::where('provider_message_id', $id)->exists() || DB::table('sms_shared_webhook_events')->where('event_id', $eventID)->exists()) { return ['status' => 'duplicate']; }
            $text = (string) ($p['text'] ?? '');
            $at = $this->timestamp(data_get($event, 'data.occurred_at')) ?? now();
            $message = SmsSharedMessage::create(['group_id' => $group->id, 'direction' => 'INBOUND', 'from_number' => $from,
                'body_text' => $text, 'status' => 'received', 'provider_message_id' => $id, 'recipient_statuses' => [],
                'media_count' => min(100, is_array($p['media'] ?? null) ? count($p['media']) : 0), 'created_at' => $at]);
            DB::table('sms_shared_webhook_events')->insert(['event_id' => $eventID, 'message_id' => $message->id, 'created_at' => now()]);
            SmsSharedGroup::whereKey($group->id)->where(fn ($q) => $q->whereNull('last_message_at')->orWhere('last_message_at', '<=', $at))
                ->update(['last_message_at' => $at, 'last_snippet' => Str::limit($text, 190)]);
            // A group STOP never causes a bot reply to all participants. Retain a
            // suppression record even when the sender has no saved Contact/User.
            $keyword = app(AiSms\SmsComplianceService::class)->detectKeyword($text);
            if ($keyword === 'stop') { DB::table('sms_shared_opt_outs')->updateOrInsert(['phone_number' => $from], ['created_at' => now()]); }
            if ($keyword === 'start') { DB::table('sms_shared_opt_outs')->where('phone_number', $from)->delete(); }
            app(\App\Services\MobileNotifications\MobileNotificationService::class)->queueSharedGroup($group->id, $message->id);
            return ['status' => 'received'];
        }));
    }
    private function identifier(string $id): bool { return (bool) preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $id); }
    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '' || strlen($value) > 64) { return null; }
        try { return CarbonImmutable::parse($value); } catch (\Throwable) { return null; }
    }
}
