<?php

namespace App\Services\Crm\Calendar;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEvent;
use App\Models\Crm\CrmEventCalendarLink;
use App\Models\Crm\CrmEventType;
use App\Models\GoogleCalendarConnection;
use App\Services\Crm\CrmActivityLogger;
use App\Services\Crm\CrmBusinessTime;
use App\Services\GoogleCalendar\GoogleCalendarService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Two-way time sync between CRM follow-ups and the owner's Google Calendar.
 * Scheduling or moving a follow-up writes it to their calendar; moving it in
 * Google moves it in the CRM (polled every five minutes). A cancelled
 * follow-up is removed from the calendar; deleting it in Google only unlinks
 * it, so nothing in the CRM is lost. Uses the Dashboard's existing Google
 * connection and only touches events it created.
 */
class CrmCalendarSync
{
    /** The poll only pulls planned follow-ups starting in this window (crm:calendar-reconcile reports outside it). */
    public const POLL_PAST_DAYS = 1;

    public const POLL_FUTURE_DAYS = 60;

    public function __construct(
        private readonly GoogleCalendarService $google,
        private readonly CrmActivityLogger $activity,
    ) {}

    /** Called after a follow-up is saved. Failures are kept on the link and retried by the poll. */
    public function queue(CrmEvent $event): void
    {
        if ($this->connection((int) $event->assigned_user_id) && in_array($event->status, ['planned', 'cancelled'], true)) {
            $this->link($event, true)->forceFill(['push_pending' => true])->save();
        }
        Cache::lock('crm-calendar:'.$event->id, 180)->get(function () use ($event) {
            $event->refresh();
            $this->removeOldLinks($event);
            try {
                $this->push($event);
            } catch (\Throwable $e) {
                report($e);
                $this->link($event)?->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 255), 'push_pending' => true])->save();
            }
        });
    }

    private function removeOldLinks(CrmEvent $event): void
    {
        foreach (CrmEventCalendarLink::query()->where('event_id', $event->id)
            ->where('user_id', '!=', $event->assigned_user_id)->get() as $oldLink) {
            try {
                if ($oldLink->google_event_id) {
                    $oldAccount = $this->connection((int) $oldLink->user_id);
                    if (! $oldAccount) {
                        $oldLink->forceFill(['push_pending' => true, 'last_error' => 'Reconnect the previous calendar to remove this follow-up.'])->save();

                        continue;
                    }
                    $this->google->deleteEvent($oldAccount, $oldLink->calendar_id, $oldLink->google_event_id);
                }
                $oldLink->delete();
            } catch (\Throwable $e) {
                report($e);
                $oldLink->forceFill(['push_pending' => true, 'last_error' => mb_substr($e->getMessage(), 0, 255)])->save();
            }
        }
    }

    public function push(CrmEvent $event): void
    {
        $connection = $this->connection((int) $event->assigned_user_id);
        if (! $connection) {
            return;
        }
        $link = $this->link($event, true);

        if ($event->status === 'cancelled') {
            $link->forceFill(['push_pending' => true])->save();
            if ($link->google_event_id) {
                $this->google->deleteEvent($connection, $link->calendar_id, $link->google_event_id);
            }
            $link->forceFill(['google_event_id' => null, 'last_synced_at' => now(), 'last_error' => null, 'push_pending' => false])->save();

            return;
        }
        if ($event->status !== 'planned') {
            return;
        }

        $payload = $this->payload($event);
        $fingerprint = hash('sha256', json_encode($payload));
        if ($link->google_event_id && $link->fingerprint === $fingerprint) {
            if ($link->push_pending) {
                $link->forceFill(['push_pending' => false, 'last_error' => null])->save();
            }

            return;
        }
        $link->forceFill(['push_pending' => true])->save();
        $calendarId = $link->google_event_id ? $link->calendar_id : ($connection->calendar_id ?: 'primary');
        $id = hash('sha256', config('app.url').'|'.$event->id.'|'.$link->id.'|'.$event->created_at?->timestamp.'|'.$link->last_synced_at?->timestamp);
        $result = $link->google_event_id
            ? $this->google->updateLinkedEvent($connection, $calendarId, $link->google_event_id, $payload)
            : $this->google->createEvent($connection, $payload + ['id' => $id]);

        DB::transaction(function () use ($event, $link, $result, $calendarId, $fingerprint) {
            $latest = CrmEvent::query()->lockForUpdate()->findOrFail($event->id);
            $changed = $latest->status !== $event->status || $latest->assigned_user_id !== $event->assigned_user_id
                || hash('sha256', json_encode($this->payload($latest))) !== $fingerprint;
            $link->forceFill([
                'google_event_id' => $result['id'] ?? null, 'calendar_id' => $calendarId,
                'fingerprint' => $fingerprint, 'last_synced_at' => now(), 'last_error' => null, 'push_pending' => $changed,
            ])->save();
        });
    }

    /** @return array{checked: int, moved: int, unlinked: int, retried: int} */
    public function pollAll(int $limit = 200): array
    {
        $out = ['checked' => 0, 'moved' => 0, 'unlinked' => 0, 'retried' => 0];

        // Retry pushes that failed.
        $failed = DB::table('crm_event_calendar_links')->where('push_pending', true)->orderBy('updated_at')->limit($limit)->pluck('event_id');
        foreach (CrmEvent::query()->whereIn('id', $failed)->whereIn('status', ['planned', 'cancelled'])->get() as $event) {
            $this->queue($event);
            $out['retried']++;
        }

        $links = DB::table('crm_event_calendar_links as l')->join('crm_events as e', 'e.id', '=', 'l.event_id')
            ->whereNotNull('l.google_event_id')->where('l.push_pending', false)->whereColumn('l.user_id', 'e.assigned_user_id')->where('e.status', 'planned')
            ->whereBetween('e.starts_at', [now()->subDays(self::POLL_PAST_DAYS), now()->addDays(self::POLL_FUTURE_DAYS)])
            ->orderBy('l.last_synced_at')->limit($limit)->get(['l.id as link_id', 'l.user_id', 'l.calendar_id', 'l.google_event_id', 'e.id as event_id']);

        foreach ($links as $row) {
            $outcome = Cache::lock('crm-calendar:'.$row->event_id, 180)->get(fn () => $this->pull($row));
            if (is_string($outcome)) {
                $out['checked']++;
                if ($outcome === 'moved' || $outcome === 'unlinked') {
                    $out[$outcome]++;
                }
            }
        }

        return $out;
    }

    private function pull(object $row): ?string
    {
        $connection = $this->connection((int) $row->user_id);
        if (! $connection) {
            return null;
        }
        try {
            $remote = $this->google->getEvent($connection, $row->google_event_id, $row->calendar_id);

            return DB::transaction(function () use ($row, $remote) {
                $event = CrmEvent::query()->lockForUpdate()->find($row->event_id);
                $link = CrmEventCalendarLink::query()->lockForUpdate()->find($row->link_id);
                if (! $link || $link->push_pending || $link->google_event_id !== $row->google_event_id
                    || ! $event || $event->status !== 'planned' || (int) $event->assigned_user_id !== (int) $row->user_id) {
                    return null;
                }
                // An edit can commit while Google's HTTP response is in flight.
                if ($link->fingerprint !== hash('sha256', json_encode($this->payload($event)))) {
                    $link->forceFill(['push_pending' => true])->save();

                    return null;
                }
                if ($remote === null) {
                    $link->forceFill(['google_event_id' => null, 'fingerprint' => null, 'last_error' => null, 'last_synced_at' => now()])->save();

                    return 'unlinked';
                }
                $outcome = 'checked';
                $start = data_get($remote, 'start.dateTime');
                $end = data_get($remote, 'end.dateTime');
                if ($start) {
                    $startAt = Carbon::parse($start)->utc();
                    $minutes = $end ? max(5, (int) round($startAt->diffInMinutes(Carbon::parse($end)))) : $event->duration_min;
                    if (! $startAt->equalTo($event->starts_at) || $minutes !== (int) $event->duration_min) {
                        $event->forceFill(['starts_at' => $startAt, 'duration_min' => $minutes])->save();
                        $contact = $event->contact_id ? CrmContact::unscopedForSystem()->find($event->contact_id) : null;
                        if ($contact) {
                            $this->activity->log($contact, 'event_moved', null, 'Follow-up moved in Google Calendar to '.$startAt->copy()->timezone(CrmBusinessTime::TIMEZONE)->format('D j M, g:i a T'), ['event_id' => $event->id]);
                        }
                        $outcome = 'moved';
                    }
                }
                $link->forceFill(['fingerprint' => hash('sha256', json_encode($this->payload($event))), 'last_synced_at' => now(), 'last_error' => null])->save();

                return $outcome;
            });
        } catch (\Throwable $e) {
            report($e);
            CrmEventCalendarLink::query()->whereKey($row->link_id)->update(['last_error' => mb_substr($e->getMessage(), 0, 255), 'last_synced_at' => now()]);

            return 'checked';
        }
    }

    private function payload(CrmEvent $event): array
    {
        $type = CrmEventType::query()->find($event->type_id);
        $contact = $event->contact_id ? CrmContact::unscopedForSystem()->find($event->contact_id) : null;
        $who = $contact ? $contact->name.($contact->company ? ' ('.$contact->company.')' : '') : 'CRM follow-up';
        $base = rtrim((string) config('crm.email.public_base_url'), '/');
        $tz = CrmBusinessTime::TIMEZONE;
        $start = $event->starts_at->copy()->timezone($tz);

        return [
            'summary' => ($type?->label ?? 'Follow-up').' · '.$who,
            'description' => trim(($event->note ? $event->note."\n\n" : '').($contact ? 'Open in the CRM: '.$base.'/crm/contacts/'.$contact->id : '')),
            'start' => ['dateTime' => $start->toRfc3339String(), 'timeZone' => $tz],
            'end' => ['dateTime' => $start->copy()->addMinutes((int) $event->duration_min)->toRfc3339String(), 'timeZone' => $tz],
            'extendedProperties' => ['private' => ['repro_crm_event_id' => (string) $event->id]],
            'reminders' => ['useDefault' => true],
        ];
    }

    private function connection(int $userId): ?GoogleCalendarConnection
    {
        if ($userId <= 0) {
            return null;
        }

        return GoogleCalendarConnection::query()->where('user_id', $userId)->where('sync_enabled', true)->first();
    }

    private function link(CrmEvent $event, bool $create = false): ?CrmEventCalendarLink
    {
        $key = ['event_id' => $event->id, 'user_id' => (int) $event->assigned_user_id];

        return $create
            ? CrmEventCalendarLink::query()->firstOrCreate($key, ['calendar_id' => 'primary'])
            : CrmEventCalendarLink::query()->where($key)->first();
    }
}
