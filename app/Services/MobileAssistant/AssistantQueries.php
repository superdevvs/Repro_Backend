<?php

namespace App\Services\MobileAssistant;

use App\Models\Shoot;
use App\Models\User;
use App\Services\PhotographerAvailabilityService;
use App\Services\Schedule\ScheduleInstantResolver;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootDurationResolver;
use App\Services\Shoots\ShootNotesAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

final class AssistantQueries
{
    public function __construct(private AssistantCapabilities $capabilities, private AssistantEndpoint $endpoint,
        private ShootAuthorizationSupport $access, private ScheduleInstantResolver $instants) {}

    public function shoots(User $actor): Builder
    {
        $this->capabilities->require($actor, 'shoot.search');

        return $this->access->scopeAccessibleShootMedia(Shoot::query(), $actor)
            ->where('status', '!=', Shoot::STATUS_IMPORT_DRAFT);
    }

    public function shoot(User $actor, int $id): Shoot
    {
        $shoot = $this->shoots($actor)->with(['client', 'photographer', 'services', 'serviceItems.service', 'serviceItems.photographer'])->findOrFail($id);
        abort_unless($this->access->canAccessShootMedia($shoot, $actor), 404);

        return $shoot;
    }

    public function query(User $actor, array $input): array
    {
        $data = Validator::make($input, [
            'action' => 'required|string|max:64', 'query' => 'nullable|string|max:300',
            'shoot_id' => 'nullable|integer|min:1', 'date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:date', 'next' => 'sometimes|boolean',
            'lock_safe' => 'sometimes|boolean', 'photographer_id' => 'nullable|integer|min:1',
            'start_time' => 'nullable|required_with:end_time|date_format:H:i', 'end_time' => 'nullable|required_with:start_time|date_format:H:i|after:start_time',
            'status' => 'nullable|string|max:32', 'context' => 'nullable|array',
            'identifier' => 'nullable|string|max:80',
        ])->validate();
        $action = $data['action'];
        $capability = $this->capabilities->require($actor, $action);
        $locked = (bool) ($data['lock_safe'] ?? false);
        abort_if($locked && ! $capability['lock_safe'], 403, 'Authenticate to use this action.');

        return match ($action) {
            'briefing', 'directions' => $this->briefing($actor, $data, $locked),
            'shoot.search', 'work.queue' => $this->search($actor, $data),
            'shoot.details', 'shoot.media' => $this->details($actor, $data),
            'availability.read', 'availability.photographers' => $this->availability($actor, $data),
            'call.contacts', 'call.lines', 'sms.conversations', 'email.accounts' => $this->choices($actor, $action, $data),
            'sms.read', 'email.read', 'support.read', 'call.read', 'earnings.read', 'invoices.read', 'reports.read' => $this->existingList($actor, $action, $data),
            'robbie.ask' => app(AssistantRobbie::class)->ask($actor, $input),
            default => abort(422, 'Use an action proposal for changes.'),
        };
    }

    public function result(User $actor, string $spoken, array $cards = [], array $extra = []): array
    {
        return ['schema_version' => 1, 'owner_id' => (string) $actor->id, 'spoken' => $spoken,
            'cards' => $cards, 'as_of' => now()->toIso8601String(), 'timezone' => $this->capabilities->timezone()] + $extra;
    }

    private function briefing(User $actor, array $data, bool $locked): array
    {
        $role = $this->capabilities->role($actor);
        if (! $locked && in_array($role, ['editor', 'editing_manager'], true) && $data['action'] !== 'directions') {
            return $this->search($actor, ['action' => 'work.queue'] + $data);
        }
        $zone = $this->capabilities->timezone();
        $from = Carbon::parse($data['date'] ?? now($zone)->toDateString(), $zone)->startOfDay();
        $next = ($data['next'] ?? false) || $data['action'] === 'directions';
        $to = isset($data['end_date']) ? Carbon::parse($data['end_date'], $zone)->endOfDay()
            : ($next ? $from->copy()->addDays(30)->endOfDay() : $from->copy()->endOfDay());
        abort_if($from->diffInDays($to) > 31, 422, 'Choose a date range of at most 31 days.');
        $range = [$from->copy()->subDay()->utc()->toDateTimeString(), $to->copy()->addDay()->utc()->toDateTimeString()];
        $query = $this->shoots($actor)->with(['photographer', 'services', 'serviceItems.service', 'serviceItems.unit', 'serviceItems.photographer'])
            ->whereNotIn('status', ['cancelled', 'canceled', 'declined', 'on_hold', 'hold_on'])
            ->where(function ($q) use ($range, $from, $to) {
                $q->whereBetween('scheduled_at', $range)->orWhereBetween('scheduled_date', [$from->toDateString(), $to->toDateString()])
                    ->orWhereHas('serviceItems', fn ($items) => $items->whereBetween('scheduled_at', $range));
            });
        if ($role === 'salesRep') {
            $query->where('rep_id', $actor->id);
        }
        // A client briefing concerns their appointments; linked deliveries are discovered explicitly.
        if ($role === 'client') {
            $query->where('client_id', $actor->id);
        }
        $visits = [];
        foreach ($query->get() as $shoot) {
            if (! $this->access->canAccessShootMedia($shoot, $actor)) {
                continue;
            }
            foreach (app(ShootDurationResolver::class)->windowsForShoot($shoot, $role === 'photographer' ? (int) $actor->id : null) as $window) {
                $start = $window['start'] ?? null;
                if (! $start || $start->lt($from) || $start->gt($to) || ($next && $start->lt(now()))) {
                    continue;
                }
                $card = $this->shootCard($shoot, $locked);
                $card['id'] = 'visit:'.$shoot->id.':'.implode(',', $window['row_indexes'] ?? []).':'.$start->timestamp;
                $card['starts_at'] = $start->toIso8601String();
                $card['subtitle'] = $start->format('D, M j, g:i A T');
                $card['service_item_ids'] = array_map('strval', $window['row_indexes'] ?? []);
                $visits[] = $card;
            }
        }
        usort($visits, fn ($a, $b) => strtotime($a['starts_at']) <=> strtotime($b['starts_at']));
        $count = count($visits);
        $cards = array_slice($visits, 0, $next ? 1 : 5);
        $spoken = $next ? ($count ? 'Your next shoot is '.$cards[0]['subtitle'].' at '.$cards[0]['title'].'.' : 'There are no upcoming appointments in the next 30 days.')
            : ($count.' appointment'.($count === 1 ? '' : 's').' on '.$from->format('l, F j').'.'.($count ? ' First, '.$cards[0]['title'].' at '.$cards[0]['subtitle'].'.' : ''));
        $counts = [];
        if (! $locked && ! $next) {
            $scope = $this->shoots($actor);
            if ($role === 'salesRep') {
                $scope->where('rep_id', $actor->id);
            }
            if ($role === 'client') {
                $scope->where('client_id', $actor->id);
            }
            foreach (['requested' => ['requested'], 'editing' => ['editing', 'editing_uploaded', 'editing_issue'],
                'review' => ['review', 'pending_review', 'ready_for_review', 'editing_complete', 'qc'], 'on_hold' => ['on_hold', 'hold_on']] as $name => $states) {
                $counts[$name] = (clone $scope)->whereIn(\Illuminate\Support\Facades\DB::raw("LOWER(COALESCE(NULLIF(workflow_status, ''), status, ''))"), $states)->count();
            }
            $parts = [];
            foreach ($counts as $name => $value) {
                if ($value) {
                    $parts[] = $value.' '.str_replace('_', ' ', $name);
                }
            }
            if ($parts) {
                $spoken .= ' Across your shoots: '.implode(', ', $parts).'.';
            }
        }

        return $this->result($actor, $spoken, $cards, ['total' => $count, 'lock_safe' => $locked, 'counts' => $counts]);
    }

    private function search(User $actor, array $data): array
    {
        $query = $this->shoots($actor);
        $term = trim($data['query'] ?? '');
        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $pattern = '%'.addcslashes($term, '%_\\').'%';
                $q->whereRaw("address LIKE ? ESCAPE '\\'", [$pattern])->orWhere('id', ctype_digit($term) ? (int) $term : -1);
            });
        }
        if (($data['action'] ?? '') === 'work.queue') {
            $states = ['uploaded', 'editing', 'review', 'ready', 'raw_uploaded', 'photos_uploaded', 'in_progress', 'raw_issue', 'editing_uploaded', 'editing_issue', 'pending_review', 'ready_for_review', 'editing_complete', 'qc'];
            $dispatches = \App\Models\ShootEditingDispatch::query()->select('shoot_id')->whereHas('items', function ($items) use ($actor) {
                $items->where('destination', 'human')->whereNotIn('status', ['completed', 'cancelled']);
                if ($this->capabilities->role($actor) === 'editor') {
                    $items->where('editor_id', $actor->id);
                }
            });
            $query->whereNotIn('status', ['cancelled', 'canceled', 'declined', 'on_hold', 'hold_on'])
                ->where(function ($q) use ($states, $dispatches) {
                    $q->whereIn(\Illuminate\Support\Facades\DB::raw("LOWER(COALESCE(NULLIF(workflow_status, ''), status, ''))"), $states)
                        ->orWhereHas('serviceItems', fn ($i) => $i->whereNull('cancelled_at')->whereIn('workflow_status', $states))
                        ->orWhereIn('id', $dispatches);
                });
            if ($this->capabilities->role($actor) === 'salesRep') {
                $query->where('rep_id', $actor->id);
            }
        }
        if (! empty($data['status'])) {
            $query->where(fn ($q) => $q->where('status', $data['status'])->orWhere('workflow_status', $data['status']));
        }
        $total = (clone $query)->count();
        $cards = $query->orderByDesc('updated_at')->limit(20)->get()->filter(fn ($s) => $this->access->canAccessShootMedia($s, $actor))
            ->map(fn ($s) => $this->shootCard($s))->values()->all();

        return $this->result($actor, $total ? $total.' matching shoot'.($total === 1 ? '.' : 's.') : 'No matching shoots are available to this account.', $cards, ['total' => $total]);
    }

    private function details(User $actor, array $data): array
    {
        abort_unless(! empty($data['shoot_id']), 422, 'Choose a shoot.');
        $shoot = $this->shoot($actor, (int) $data['shoot_id']);
        $card = $this->shootCard($shoot);
        $card['route'] = $data['action'] === 'shoot.media' ? 'shoot-gallery' : 'shoot-detail';
        $notesAccess = app(ShootNotesAccessService::class);
        $notes = $notesAccess->canRead($shoot, $actor) ? $notesAccess->visibleNotes($shoot->notes()->get(), $actor, $shoot) : collect();
        $details = ['services' => $shoot->serviceItems->filter(function ($item) use ($actor, $shoot) {
            if ($this->capabilities->role($actor) !== 'editor') {
                return true;
            }

            return (int) $shoot->editor_id === (int) $actor->id || (int) $item->editor_id === (int) $actor->id || (int) $item->video_editor_id === (int) $actor->id;
        })->map(fn ($item) => ['id' => (string) $item->id, 'name' => $item->service?->name, 'status' => $item->workflow_status])->values()->all(),
            'notes' => $notes->map(fn ($n) => ['type' => $n->type, 'visibility' => $n->visibility, 'content' => $n->content])->values()->all()];
        if ($this->capabilities->can($actor, 'invoices') && app(\App\Services\Invoices\InvoiceAuthorizationService::class)->canViewShootInvoice($shoot, $actor)) {
            $details['payment_status'] = $shoot->payment_status;
        }

        return $this->result($actor, $card['title'].'. '.$card['subtitle'].'.', [$card], ['details' => $details]);
    }

    public function shootCard(Shoot $shoot, bool $limited = false): array
    {
        return ['id' => 'shoot:'.$shoot->id, 'kind' => 'shoot', 'record_id' => (string) $shoot->id,
            'title' => implode(', ', array_filter([$shoot->address, $shoot->city, $shoot->state])),
            'subtitle' => $limited ? '' : ucfirst((string) ($shoot->workflow_status ?: $shoot->status)),
            'route' => 'shoot-detail'];
    }

    private function availability(User $actor, array $data): array
    {
        $date = $data['date'] ?? now($this->capabilities->timezone())->toDateString();
        $people = User::query()->where(fn ($q) => $q->where('role', 'photographer')->orWhereJsonContains('secondary_roles', 'photographer'));
        if ($this->capabilities->role($actor) === 'photographer') {
            $people->whereKey($actor->id);
        }
        if (! empty($data['photographer_id'])) {
            $people->whereKey($data['photographer_id']);
        }
        if (! empty($data['query'])) {
            $people->where('name', 'like', '%'.addcslashes($data['query'], '%_').'%');
        }
        $people = $people->get()->filter(fn ($person) => $person->isAccountEligibleForAuthentication());
        $calendars = $people->isEmpty() ? [] : $this->endpoint->invoke($actor, 'POST', 'photographer/availability/bulk-index', [
            'photographer_ids' => $people->pluck('id')->all(), 'from_date' => $date, 'to_date' => $date,
        ]);
        $cards = [];
        foreach ($people as $person) {
            // A single bulk route enforces calendar access without N middleware/network-like dispatches.
            $zone = data_get($calendars, 'meta.timezones.'.$person->id, $this->capabilities->timezone());
            $day = Carbon::parse($date, $zone);
            $slots = $data['action'] === 'availability.photographers' ? [] : app(PhotographerAvailabilityService::class)->getAvailableSlots($person->id, $day, $day);
            $slots = array_values(array_filter($slots[$date] ?? [], fn ($slot) => ! isset($data['start_time'])
                || ($slot['start'] <= $data['start_time'] && $slot['end'] >= $data['end_time'])));
            if ($slots === [] && $data['action'] !== 'availability.photographers') {
                continue;
            }
            $cards[] = ['id' => 'photographer:'.$person->id, 'kind' => 'photographer', 'record_id' => (string) $person->id,
                'title' => $person->name, 'subtitle' => $date.' · '.$zone, 'route' => 'availability', 'timezone' => $zone, 'slots' => $slots];
        }

        return $this->result($actor, count($cards).' photographer'.(count($cards) === 1 ? '' : 's').($data['action'] === 'availability.photographers' ? ' in your calendar choices' : ' available on '.$date)
            .(isset($data['start_time']) ? ' from '.$data['start_time'].' to '.$data['end_time'] : '').'.', $cards, ['total' => count($cards)]);
    }

    private function existingList(User $actor, string $action, array $data): array
    {
        [$path, $route, $label] = match ($action) {
            'sms.read' => ['messaging/sms/threads', 'messages', 'Messages'],
            'email.read' => ['messaging/email/messages', 'messages', 'Email'],
            'support.read' => ['support/tickets', 'support', 'Support requests'],
            'call.read' => ['voice/calls', 'call-history', 'Calls'],
            'earnings.read' => ['photographer/invoices', 'earnings', 'Your earnings'],
            'invoices.read' => ['invoices', 'invoices', 'Invoices'],
            'reports.read' => ['reports/invoices/summary', 'reports', 'Reports'],
        };
        $filters = ['per_page' => 10, 'page' => 1, $action === 'support.read' ? 'query' : 'search' => $data['query'] ?? ''];
        if ($action === 'support.read' && ! empty($data['status'])) {
            $filters['status'] = $data['status'];
        }
        $response = $this->endpoint->invoke($actor, 'GET', $path, $filters);
        if ($action === 'reports.read') {
            $summary = $response['summary'] ?? [];
            $spoken = ($summary['invoice_count'] ?? 0).' invoices for '.($summary['start'] ?? 'this period').'. Total invoiced: '
                .number_format((float) ($summary['total_invoiced'] ?? 0), 2).'. Outstanding: '.number_format((float) ($summary['total_outstanding'] ?? 0), 2).'.';

            return $this->result($actor, $spoken, [['id' => $action, 'kind' => 'workspace', 'record_id' => '', 'title' => $label, 'subtitle' => $spoken, 'route' => $route]]);
        }
        $rows = $response['data'] ?? [];
        $total = (int) ($response['total'] ?? data_get($response, 'meta.total') ?? data_get($response, 'meta.pagination.total') ?? count($rows));
        $cards = [];
        foreach (array_slice($rows, 0, 5) as $row) {
            [$title, $subtitle] = match ($action) {
                'sms.read' => [data_get($row, 'group.name') ?: data_get($row, 'contact.name', 'Conversation'), $row['lastMessageSnippet'] ?? ''],
                'email.read' => [$row['subject'] ?? 'Email', ($row['from_address'] ?? '').'. '.($row['body_text'] ?? '')],
                'support.read' => [$row['subject'] ?? 'Support request', $row['status'] ?? ''],
                'call.read' => [data_get($row, 'contact.name') ?: ($row['contact_name'] ?? (($row['direction'] ?? '') === 'OUTBOUND' ? $row['to_phone'] ?? 'Call' : $row['from_phone'] ?? 'Call')), $row['status'] ?? $row['disposition'] ?? ''],
                default => ['Invoice '.($row['invoice_number'] ?? $row['id']), ucfirst($row['status'] ?? 'Pending').'. Total '.number_format((float) ($row['total_amount'] ?? $row['total'] ?? 0), 2)],
            };
            $cards[] = ['id' => $action.':'.($row['id'] ?? count($cards)), 'kind' => 'workspace', 'record_id' => (string) ($row['id'] ?? ''),
                'title' => (string) $title, 'subtitle' => \Illuminate\Support\Str::limit(strip_tags((string) $subtitle), 200), 'route' => $route];
        }
        $spoken = $total.' '.strtolower($label).'.'.($cards ? ' Most recent: '.$cards[0]['title'].'. '.$cards[0]['subtitle'] : '');

        // Do not forward full route payloads: email channel config, attachments, payment
        // metadata and provider internals are not part of the voice surface.
        return $this->result($actor, $spoken, $cards, ['total' => $total]);
    }

    private function choices(User $actor, string $action, array $data): array
    {
        $cards = [];
        if ($action === 'call.contacts') {
            $term = $data['query'] ?? '';
            if (! empty($data['identifier'])) {
                abort_unless(preg_match('/^(user|contact):([1-9][0-9]*)$/', $data['identifier'], $m), 422);
                $record = $m[1] === 'user' ? User::findOrFail($m[2]) : \App\Models\Contact::findOrFail($m[2]);
                $term = $record->phonenumber ?: $record->phone;
                abort_unless($term, 404);
            }
            $response = $this->endpoint->invoke($actor, 'GET', 'voice/directory', ['q' => mb_substr($term, 0, 120), 'per_page' => 50]);
            foreach ($response['data'] ?? [] as $person) {
                if (! ($person['callable'] ?? false) || (! empty($data['identifier']) && $person['id'] !== $data['identifier'])) {
                    continue;
                }
                $cards[] = ['id' => $person['id'], 'kind' => 'contact', 'record_id' => $person['id'], 'title' => $person['name'],
                    'subtitle' => $person['phone'], 'route' => 'dialer'];
            }
        } elseif ($action === 'call.lines') {
            $response = $this->endpoint->invoke($actor, 'GET', 'voice/numbers');
            foreach ($response['numbers'] ?? [] as $line) {
                if (! ($line['can_call_from'] ?? false)) {
                    continue;
                }
                $cards[] = ['id' => (string) $line['id'], 'kind' => 'line', 'record_id' => $line['phone_number'],
                    'title' => $line['label'] ?: $line['phone_number'], 'subtitle' => $line['phone_number'], 'route' => 'dialer'];
            }
        } elseif ($action === 'sms.conversations') {
            if (! empty($data['identifier'])) {
                $thread = \App\Models\MessageThread::with(['contact', 'smsGroup', 'latestSmsMessage'])->findOrFail($data['identifier']);
                abort_unless(app(\App\Services\Messaging\SmsInboxPolicy::class)->canView($actor, $thread), 404);
                $rows = [(new \App\Http\Resources\Messaging\SmsThreadResource($thread))->resolve(request())];
            } else {
                $response = $this->endpoint->invoke($actor, 'GET', 'messaging/sms/threads', ['search' => $data['query'] ?? '', 'per_page' => 20]);
                $rows = $response['data'] ?? [];
            }
            foreach ($rows as $thread) {
                $cards[] = ['id' => (string) $thread['id'], 'kind' => 'thread', 'record_id' => (string) $thread['id'],
                    'title' => data_get($thread, 'group.name') ?: data_get($thread, 'contact.name', 'Conversation'),
                    'subtitle' => data_get($thread, 'group.memberCount') ? data_get($thread, 'group.memberCount').' people' : data_get($thread, 'contact.phone', ''), 'route' => 'conversation'];
            }
        } else {
            $channels = \App\Models\MessageChannel::where('type', 'EMAIL')
                ->where(fn ($q) => $q->where('owner_scope', 'GLOBAL')->orWhere(fn ($q) => $q->where('owner_scope', 'USER')->where('owner_id', $actor->id)))->get();
            foreach ($channels as $channel) {
                $cards[] = ['id' => (string) $channel->id, 'kind' => 'email_account', 'record_id' => (string) $channel->id,
                    'title' => $channel->from_email, 'subtitle' => $channel->from_name ?: '', 'route' => 'messages'];
            }
        }

        return $this->result($actor, count($cards).' available choices.', $cards);
    }
}
