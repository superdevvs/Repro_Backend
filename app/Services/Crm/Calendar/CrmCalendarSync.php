<?php

namespace App\Services\Crm\Calendar;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEvent;
use App\Models\Crm\CrmEventCalendarLink;
use App\Models\Crm\CrmEventType;
use App\Models\GoogleCalendarConnection;
use App\Services\Crm\CrmActivityLogger;
use App\Services\GoogleCalendar\GoogleCalendarService;
use Illuminate\Support\Carbon;
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
    public function __construct(
        private readonly GoogleCalendarService $google,
        private readonly CrmActivityLogger $activity,
    ) {}

    /** Called after a follow-up is saved. Failures are kept on the link and retried by the poll. */
    public function queue(CrmEvent $event): void
    {
        try {
            $this->push($event);
        } catch (\Throwable $e) {
            report($e);
            $this->link($event)?->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 255)])->save();
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
            if ($link->google_event_id) {
                $this->google->deleteEvent($connection, $link->calendar_id, $link->google_event_id);
            }
            $link->forceFill(['google_event_id' => null, 'last_synced_at' => now(), 'last_error' => null])->save();

            return;
        }
        if ($event->status !== 'planned') {
            return;
        }

        $payload = $this->payload($event);
        $fingerprint = hash('sha256', json_encode($payload));
        if ($link->google_event_id && $link->fingerprint === $fingerprint) {
            return;
        }
        $result = $link->google_event_id
            ? $this->google->updateEvent($connection, $link->google_event_id, $payload)
            : $this->google->createEvent($connection, $payload);

        $link->forceFill([
            'google_event_id' => $result['id'] ?? $link->google_event_id, 'calendar_id' => $connection->calendar_id ?? 'primary',
            'fingerprint' => $fingerprint, 'last_synced_at' => now(), 'last_error' => null,
        ])->save();
    }

    /** @return array{checked: int, moved: int, unlinked: int, retried: int} */
    public function pollAll(int $limit = 200): array
    {
        $out = ['checked' => 0, 'moved' => 0, 'unlinked' => 0, 'retried' => 0];

        // Retry pushes that failed.
        $failed = DB::table('crm_event_calendar_links')->whereNull('google_event_id')->whereNotNull('last_error')->limit($limit)->pluck('event_id');
        foreach (CrmEvent::query()->whereIn('id', $failed)->where('status', 'planned')->get() as $event) {
            $this->queue($event);
            $out['retried']++;
        }

        $links = DB::table('crm_event_calendar_links as l')->join('crm_events as e', 'e.id', '=', 'l.event_id')
            ->whereNotNull('l.google_event_id')->where('e.status', 'planned')
            ->whereBetween('e.starts_at', [now()->subDay(), now()->addDays(60)])
            ->orderBy('l.last_synced_at')->limit($limit)->get(['l.id as link_id', 'l.user_id', 'l.google_event_id', 'e.id as event_id']);

        foreach ($links as $row) {
            $connection = $this->connection((int) $row->user_id);
            if (! $connection) {
                continue;
            }
            $out['checked']++;
            try {
                $remote = $this->google->getEvent($connection, $row->google_event_id);
                $event = CrmEvent::query()->find($row->event_id);
                if (! $event) {
                    continue;
                }
                if ($remote === null) {
                    DB::table('crm_event_calendar_links')->where('id', $row->link_id)->update(['google_event_id' => null, 'last_error' => null, 'fingerprint' => null, 'last_synced_at' => now(), 'updated_at' => now()]);
                    $out['unlinked']++;

                    continue;
                }
                $start = data_get($remote, 'start.dateTime');
                $end = data_get($remote, 'end.dateTime');
                if (! $start) {
                    continue;
                }
                $startAt = Carbon::parse($start)->utc();
                $minutes = $end ? max(5, (int) round(Carbon::parse($start)->diffInMinutes(Carbon::parse($end)))) : $event->duration_min;
                if (! $startAt->equalTo($event->starts_at->copy()->utc()) || $minutes !== (int) $event->duration_min) {
                    $event->forceFill(['starts_at' => $startAt, 'duration_min' => $minutes])->save();
                    $contact = $event->contact_id ? CrmContact::unscopedForSystem()->find($event->contact_id) : null;
                    if ($contact) {
                        $this->activity->log($contact, 'event_moved', null, 'Follow-up moved in Google Calendar to '.$startAt->copy()->timezone(config('app.timezone'))->format('D j M, g:i a'), ['event_id' => $event->id]);
                    }
                    $out['moved']++;
                }
                DB::table('crm_event_calendar_links')->where('id', $row->link_id)->update([
                    'fingerprint' => hash('sha256', json_encode($this->payload($event->fresh()))), 'last_synced_at' => now(), 'last_error' => null, 'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                report($e);
                DB::table('crm_event_calendar_links')->where('id', $row->link_id)->update(['last_error' => mb_substr($e->getMessage(), 0, 255), 'updated_at' => now()]);
            }
        }

        return $out;
    }

    private function payload(CrmEvent $event): array
    {
        $type = CrmEventType::query()->find($event->type_id);
        $contact = $event->contact_id ? CrmContact::unscopedForSystem()->find($event->contact_id) : null;
        $who = $contact ? $contact->name.($contact->company ? ' ('.$contact->company.')' : '') : 'CRM follow-up';
        $base = rtrim((string) config('crm.email.public_base_url'), '/');
        $tz = (string) config('app.timezone', 'UTC');
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
