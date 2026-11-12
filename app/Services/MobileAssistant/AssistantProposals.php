<?php

namespace App\Services\MobileAssistant;

use App\Models\MessageThread;
use App\Models\Shoot;
use App\Models\SmsGroup;
use App\Models\SmsNumber;
use App\Models\User;
use App\Services\Messaging\SmsInboxPolicy;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootManagementAccess;
use App\Services\Shoots\ShootNotesAccessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Constructs a frozen, human-readable proposal; preparing a proposal never mutates business data. */
final class AssistantProposals
{
    public function __construct(private AssistantCapabilities $capabilities, private AssistantQueries $queries, private AssistantEndpoint $endpoint) {}

    public function make(User $actor, string $action, array $input): array
    {
        $capability = $this->capabilities->require($actor, $action);
        abort_unless(in_array($capability['mode'], ['confirm', 'review', 'handoff'], true), 422, 'This is not a change action.');

        return match ($action) {
            'note.create' => $this->note($actor, $input),
            'availability.create' => $this->availability($actor, $input),
            'shoot.hold', 'shoot.reschedule', 'shoot.resume' => $this->schedule($actor, $action, $input),
            'sms.send' => $this->sms($actor, $input),
            'email.send' => $this->email($actor, $input),
            'support.create' => $this->support($input),
            'call.start' => $this->call($actor, $input),
            default => $this->review($actor, $action, $input),
        };
    }

    private function proposal(array $params, string $summary, string $path, ?string $version = null,
        string $route = 'shoot-detail', ?string $recordID = null, string $mode = 'confirm'): array
    {
        return ['params' => $params, 'summary' => $summary, 'path' => $path, 'method' => 'POST',
            'target_version' => $version, 'route' => $route, 'record_id' => $recordID, 'mode' => $mode];
    }

    public function version(Shoot $shoot): string
    {
        return hash('sha256', json_encode([$shoot->getRawOriginal(), $shoot->serviceItems()->orderBy('id')->get()->map->getRawOriginal()->all()]));
    }

    public function authorizeReceipt(User $actor, string $action, array $params): void
    {
        // A successful change may itself remove write eligibility (for example a hold).
        // Replays require current read access, not permission to repeat the old mutation.
        if (isset($params['shoot_id'])) {
            $shoot = $this->queries->shoot($actor, (int) $params['shoot_id']);
            if ($action === 'note.create') {
                $notes = app(ShootNotesAccessService::class);
                $draft = new \App\Models\ShootNote(['type' => $params['type'], 'visibility' => $params['visibility']]);
                abort_unless($notes->canRead($shoot, $actor) && $notes->visibleNotes(collect([$draft]), $actor, $shoot)->isNotEmpty(), 404);
            }
        }
        if ($action === 'sms.send') {
            $thread = MessageThread::findOrFail($params['thread_id']);
            abort_unless(app(SmsInboxPolicy::class)->canView($actor, $thread), 404);
        }
        if ($action === 'availability.create') {
            $this->endpoint->invoke($actor, 'GET', 'photographer/availability/'.$params['photographer_id']);
        }
        if ($action === 'call.start') {
            $this->call($actor, $params);
        }
        if ($action === 'email.send') {
            abort_unless(\App\Models\MessageChannel::whereKey($params['channel_id'])->where('type', 'EMAIL')
                ->where(fn ($q) => $q->where('owner_scope', 'GLOBAL')->orWhere(fn ($q) => $q->where('owner_scope', 'USER')->where('owner_id', $actor->id)))->exists(), 404);
        }
    }

    private function note(User $actor, array $input): array
    {
        $role = $this->capabilities->role($actor);
        $input += ['type' => match ($role) {
            'photographer' => 'photographer', 'client' => 'shoot', default => 'company'
        },
            'visibility' => match ($role) {
                'photographer' => 'photographer_only', 'client' => 'client_visible', default => 'internal'
            }];
        $p = Validator::make($input, ['shoot_id' => 'required|integer|min:1', 'content' => 'required|string|min:1|max:5000',
            'type' => 'required|in:shoot,company,photographer,editing,approval',
            'visibility' => 'required|in:internal,photographer_only,client_visible'])->validate();
        $shoot = $this->queries->shoot($actor, $p['shoot_id']);
        abort_unless(app(ShootNotesAccessService::class)->canCreate($shoot, $actor, $p['type'], $p['visibility']), 403, 'You cannot add this type of note to this shoot.');
        $audience = match ($p['visibility']) {
            'client_visible' => 'visible to the client', 'photographer_only' => 'visible to the photographer and staff', default => 'internal to staff'
        };

        return $this->proposal($p, 'Add a note to '.$shoot->address.', '.$audience.': '.$p['content'], 'shoots/'.$shoot->id.'/notes', $this->version($shoot), recordID: (string) $shoot->id);
    }

    private function availability(User $actor, array $input): array
    {
        if ($this->capabilities->role($actor) === 'photographer') {
            $input += ['photographer_id' => $actor->id];
        }
        $p = Validator::make($input, ['photographer_id' => 'required|integer|min:1', 'date' => 'required|date_format:Y-m-d',
            'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i|after:start_time',
            'status' => 'required|in:available,unavailable', 'timezone' => 'required|timezone', 'note' => 'nullable|string|max:1000',
            'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date'])->validate();
        $person = User::findOrFail($p['photographer_id']);
        abort_unless($person->isAccountEligibleForAuthentication(), 422, 'This photographer is not active.');
        $calendar = $this->endpoint->invoke($actor, 'POST', 'photographer/availability/bulk-index', [
            'photographer_ids' => [$person->id], 'from_date' => $p['date'], 'to_date' => $p['date'],
        ]);
        abort_unless(data_get($calendar, 'meta.can_manage.'.$person->id), 403, 'You cannot change this photographer calendar.');
        $timezone = data_get($calendar, 'meta.timezones.'.$person->id);
        abort_unless($p['timezone'] === $timezone, 409, 'The calendar timezone changed. Review the time again.');
        foreach (['start' => 'starts_at', 'end' => 'ends_at'] as $part => $instantKey) {
            $text = $p['date'].' '.$p[$part.'_time'];
            $time = Carbon::parse($text, $timezone);
            abort_unless($time->format('Y-m-d H:i') === $text, 422, 'That local time does not exist because the clocks change.');
            if (isset($p[$instantKey])) {
                abort_unless(Carbon::parse($p[$instantKey])->setTimezone($timezone)->format('Y-m-d H:i') === $text, 422, 'The timezone and selected time do not match.');
            } else {
                $transitions = (new \DateTimeZone($timezone))->getTransitions($time->timestamp - 86400, $time->timestamp + 86400);
                if ($transitions && count($transitions) > 1 && $transitions[1]['offset'] < $transitions[0]['offset']) {
                    $repeatStart = Carbon::createFromTimestamp($transitions[1]['ts'], $timezone);
                    $repeatEnd = $repeatStart->copy()->addSeconds($transitions[0]['offset'] - $transitions[1]['offset']);
                    abort_if($text >= $repeatStart->format('Y-m-d H:i') && $text < $repeatEnd->format('Y-m-d H:i'), 422, 'That time occurs twice. Choose the exact time in the app.');
                }
            }
        }
        $summary = ($p['status'] === 'unavailable' ? 'Block ' : 'Add availability for ').$person->name.' on '.Carbon::parse($p['date'])->format('l, F j, Y')
            .' from '.$p['start_time'].' to '.$p['end_time'].' '.$timezone.'.';

        return $this->proposal($p, $summary, 'photographer/availability', hash('sha256', json_encode($calendar)), 'availability', (string) $person->id);
    }

    private function schedule(User $actor, string $action, array $input): array
    {
        $p = Validator::make($input, ['shoot_id' => 'required|integer|min:1', 'reason' => ($action === 'shoot.hold' ? 'required' : 'nullable').'|string|max:500',
            'scheduled_at' => ($action === 'shoot.hold' ? 'nullable' : 'required').'|date',
            'notify_client' => 'sometimes|boolean', 'notify_photographer' => 'sometimes|boolean',
            'notification_channels' => 'sometimes|array|min:1|max:2', 'notification_channels.*' => 'in:email,sms'])->validate();
        $shoot = $this->queries->shoot($actor, $p['shoot_id']);
        abort_unless(app(ShootManagementAccess::class)->canEditAppointment($shoot, $actor), 403);
        if ($action === 'shoot.hold') {
            abort_unless(app(ShootManagementAccess::class)->can($actor, 'manage'), 403);
            $p += ['notify_client' => false, 'notify_photographer' => false, 'notification_channels' => ['email']];
            $recipients = array_filter([$p['notify_client'] ? 'client' : null, $p['notify_photographer'] ? 'photographers' : null]);
            $summary = 'Put '.$shoot->address.' on hold. Reason: '.$p['reason'].'. '.($recipients ? 'Notify '.implode(' and ', $recipients).' by '.implode(' and ', $p['notification_channels']).'.' : 'No client or photographer notifications.');

            return $this->proposal($p, $summary, 'shoots/'.$shoot->id.'/put-on-hold', $this->version($shoot), recordID: (string) $shoot->id);
        }
        abort_unless(app(ShootAuthorizationSupport::class)->canScheduleShoot($shoot, $actor), 403);
        // Multiple onsite visits and units require their dedicated editor; never move all visits implicitly.
        $windows = app(\App\Services\Shoots\ShootDurationResolver::class)->windowsForShoot($shoot);
        if (count($windows) > 1) {
            return $this->proposal($p, 'Review the individual appointments for '.$shoot->address.'.', '', $this->version($shoot), recordID: (string) $shoot->id, mode: 'review');
        }
        abort_unless(preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $p['scheduled_at']), 422, 'Include the timezone offset for the appointment.');
        $zone = app(\App\Services\Schedule\ScheduleInstantResolver::class)->timezoneForShoot($shoot);
        $date = Carbon::parse($p['scheduled_at'])->setTimezone($zone);
        $p['scheduled_date'] = $date->toDateString();
        $p['time'] = $date->format('H:i');
        $p['expected_units_revision'] = (int) ($shoot->units_revision ?? 0);
        app(\App\Services\Scheduling\ScheduleCommitGuard::class)->prepare($p, $shoot, $actor);

        return $this->proposal($p, 'Move '.$shoot->address.' to '.$date->format('l, F j, Y, g:i A T').'. Existing booking notifications apply.', 'shoots/'.$shoot->id.'/schedule', $this->version($shoot), recordID: (string) $shoot->id);
    }

    private function sms(User $actor, array $input): array
    {
        $p = Validator::make($input, ['thread_id' => 'required|integer|min:1', 'body' => 'required|string|min:1|max:1200', 'sms_number_id' => 'nullable|integer|min:1'])->validate();
        $thread = MessageThread::with(['contact', 'latestSmsMessage'])->findOrFail($p['thread_id']);
        $policy = app(SmsInboxPolicy::class);
        abort_unless($policy->canView($actor, $thread), 404);
        $business = $policy->businessNumber($thread);
        $number = isset($p['sms_number_id']) ? SmsNumber::findOrFail($p['sms_number_id'])
            : SmsNumber::where('provider', 'TELNYX')->get()->first(fn ($n) => preg_replace('/\D/', '', $n->phone_number) === preg_replace('/\D/', '', (string) $business));
        abort_unless($number && $number->provider === 'TELNYX', 422, 'Choose a business sending number.');
        $p['sms_number_id'] = $number->id;
        $members = $thread->sms_group_id ? SmsGroup::findOrFail($thread->sms_group_id)->members()->with('user')->orderBy('id')->get()
            ->map(function ($member) {
                // Group delivery prefers the linked user's current number over its saved member copy.
                $live = $this->smsPhone((string) ($member->user?->phonenumber ?: $member->user?->phone));

                return ['id' => $member->id, 'user_id' => $member->user_id, 'name' => $member->name ?: $member->user?->name,
                    'phone' => $live ?: $this->smsPhone((string) $member->phone)];
            })->all() : [];
        // Match the existing sender's actual destination, not a stale thread participant label.
        $target = $members ? implode(', ', array_map(fn ($m) => ($m['name'] ?: $m['phone']).' ('.$m['phone'].')', $members))
            : ($thread->contact?->name ?: 'Contact').' ('.($thread->contact?->phone ?: data_get($thread->contact?->phones_json, '0.number')).')';
        $recipients = $members ? array_values(array_unique(array_column($members, 'phone')))
            : [$this->smsPhone((string) ($thread->contact?->phone ?: data_get($thread->contact?->phones_json, '0.number')))];
        abort_if($recipients === [] || in_array('', $recipients, true), 422, 'Every recipient needs a valid phone number. Review this conversation in the app.');
        $version = hash('sha256', json_encode([$thread->getRawOriginal(), $thread->contact?->getRawOriginal(), $number->getRawOriginal(), $members]));

        return $this->proposal($p, 'Send from '.$number->phone_number.' to '.$target.': '.$p['body'], 'messaging/sms/threads/'.$thread->id.'/messages', $version, 'conversation', (string) $thread->id)
            + ['recipients' => $recipients];
    }

    private function smsPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) < 10 || strlen($digits) > 15) {
            return '';
        }

        return '+'.(strlen($digits) === 10 ? '1' : '').$digits;
    }

    private function email(User $actor, array $input): array
    {
        $p = Validator::make($input, ['to' => 'required|email:rfc|max:254', 'subject' => 'required|string|max:180',
            'body_text' => 'required|string|max:5000', 'channel_id' => 'required|integer|min:1'])->validate();
        // Sending-channel authorization is also enforced by the existing compose route at execution.
        $channel = \App\Models\MessageChannel::where('type', 'EMAIL')->whereKey($p['channel_id'])
            ->where(fn ($q) => $q->where('owner_scope', 'GLOBAL')->orWhere(fn ($own) => $own->where('owner_scope', 'USER')->where('owner_id', $actor->id)))->first();
        abort_unless($channel, 403, 'This email sending account is not available.');

        return $this->proposal($p, 'Email '.$p['to'].' from '.$channel->from_email.'. Subject: '.$p['subject'].'. Message: '.$p['body_text'],
            'messaging/email/compose', hash('sha256', json_encode($channel->getRawOriginal())), 'messages');
    }

    private function support(array $input): array
    {
        $p = Validator::make($input, ['subject' => 'required|string|min:3|max:180', 'body' => 'required|string|min:10|max:8000',
            'category' => ['required', Rule::in(\App\Http\Controllers\API\SupportTicketController::CATEGORIES)]])->validate();

        return $this->proposal($p, 'Create a '.$p['category'].' support request. '.$p['subject'].'. '.$p['body'], 'support/tickets', route: 'support');
    }

    private function call(User $actor, array $input): array
    {
        $p = Validator::make($input, ['shoot_id' => 'required_without:recipient|integer|min:1', 'person' => 'required_with:shoot_id|in:client,photographer',
            'recipient' => ['required_without:shoot_id', 'string', 'regex:/^(user|contact):[1-9][0-9]*$/'], 'caller_id' => 'required|string|max:80'])->validate();
        $shoot = isset($p['shoot_id']) ? $this->queries->shoot($actor, $p['shoot_id']) : null;
        if ($shoot) {
            $person = $p['person'] === 'client' ? $shoot->client : $shoot->photographer;
            abort_unless($person, 404);
            $phone = app(\App\Services\TelnyxAi\VoiceNumberSettingsResolver::class)->normalize($person->phonenumber ?: $person->phone);
            $name = $person->name;
            $version = [$this->version($shoot), $person->getRawOriginal()];
        } else {
            $choices = $this->queries->query($actor, ['action' => 'call.contacts', 'identifier' => $p['recipient']]);
            $person = $choices['cards'][0] ?? null;
            abort_unless($person, 404);
            $phone = $person['subtitle'];
            $name = $person['title'];
            $version = $person;
        }
        abort_unless(preg_match('/^\+[1-9]\d{6,14}$/', (string) $phone), 422, 'This contact needs a valid international phone number.');
        $lines = $this->endpoint->invoke($actor, 'GET', 'voice/numbers');
        $line = collect($lines['numbers'] ?? [])->first(fn ($l) => ($l['can_call_from'] ?? false) && in_array($p['caller_id'], [(string) ($l['id'] ?? ''), (string) ($l['phone_number'] ?? '')], true));
        abort_unless($line, 403, 'This calling line is not available.');
        $p += ['phone' => $phone, 'name' => $name];
        $p['caller_id'] = $line['phone_number'];
        // Presence/registration can change while the app connects. Freeze routing policy,
        // not live presence, so bringing the approved line online does not invalidate it.
        $lineVersion = [SmsNumber::find($line['id'])?->getRawOriginal(),
            \App\Models\VoiceLinePolicy::where('phone_number', $line['phone_number'])->first()?->getRawOriginal()];

        return $this->proposal($p, 'Call '.$name.' at '.$phone.' using '.$line['phone_number'].'.', '',
            hash('sha256', json_encode([$version, $lineVersion])), 'dialer', $shoot ? (string) $shoot->id : null, 'handoff');
    }

    private function review(User $actor, string $action, array $input): array
    {
        $p = Validator::make($input, ['shoot_id' => 'nullable|integer|min:1', 'prompt' => 'nullable|string|max:2000',
            'scheduled_at' => 'nullable|date', 'address' => 'nullable|string|max:500'])->validate();
        $shoot = isset($p['shoot_id']) ? $this->queries->shoot($actor, $p['shoot_id']) : null;
        if ($action === 'shoot.change_request') {
            abort_unless($shoot && (int) $shoot->client_id === (int) $actor->id, 403);
        }
        if (in_array($action, ['shoot.submit', 'shoot.delivery', 'shoot.media'], true)) {
            abort_unless($shoot, 422, 'Choose a shoot.');
        }

        return $this->proposal($p, 'Review '.($shoot?->address ?? 'your booking').' in R/E Pro before making changes.', '',
            $shoot ? $this->version($shoot) : null, $action === 'booking.review' ? 'book-shoot' : 'shoot-detail', $shoot ? (string) $shoot->id : null, 'review');
    }
}
