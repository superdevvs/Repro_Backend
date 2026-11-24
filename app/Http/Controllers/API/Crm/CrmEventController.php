<?php

namespace App\Http\Controllers\API\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEvent;
use App\Models\Crm\CrmEventType;
use App\Services\Crm\CrmBusinessTime;
use App\Services\Crm\CrmEventPresenter;
use App\Services\Crm\CrmEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Follow-ups: overdue, today and the next 7 days, per rep; scheduling and completing events. */
class CrmEventController extends CrmController
{
    public function __construct(
        private readonly CrmEventService $events,
        private readonly CrmEventPresenter $present,
    ) {}

    public function followUps(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['nullable', 'integer'], 'days' => ['nullable', 'integer', 'min:1', 'max:31']]);
        $viewer = $this->actor($request);
        $userId = (int) ($data['user_id'] ?? $viewer->id);
        if ($userId !== $viewer->id && ! $this->access()->canViewAll($viewer)) {
            return response()->json(['message' => 'You can only see your own follow-ups.'], 403);
        }

        $today = CrmBusinessTime::startOfDay();
        $endOfToday = CrmBusinessTime::endOfDay();
        $until = now(CrmBusinessTime::TIMEZONE)->startOfDay()->addDays(1 + (int) ($data['days'] ?? 7))->setTimezone(config('app.timezone'));
        $events = CrmEvent::query()->visible($viewer)->where('assigned_user_id', $userId)->where('status', 'planned')
            ->where('starts_at', '<', $until)->orderBy('starts_at')->get();

        $groups = ['overdue' => [], 'today' => [], 'upcoming' => []];
        foreach ($events as $event) {
            $key = $event->starts_at->lt($today) ? 'overdue' : ($event->starts_at->lte($endOfToday) ? 'today' : 'upcoming');
            $groups[$key][] = $this->present->event($event);
        }
        $done = CrmEvent::query()->visible($viewer)->where('assigned_user_id', $userId)->whereIn('status', ['done', 'missed'])
            ->where('completed_at', '>=', $today->copy()->subDays(7))->orderByDesc('completed_at')->limit(50)->get();

        return response()->json([
            'user' => $this->presenter()->user($userId),
            'groups' => $groups,
            'recently_done' => $done->map(fn ($e) => $this->present->event($e))->all(),
            'calendar' => app(\App\Services\Crm\CrmCalendarStatus::class)->for($userId),
        ]);
    }

    public function forContact(Request $request, CrmContact $contact): JsonResponse
    {
        $events = CrmEvent::query()->where('contact_id', $contact->id)->orderByDesc('starts_at')->limit(100)->get();

        return response()->json(['data' => $events->map(fn ($e) => $this->present->event($e, false))->all()]);
    }

    public static function eventRules(bool $creating): array
    {
        return [
            'type_id' => [$creating ? 'required_without:type' : 'sometimes', 'integer'],
            'type' => ['nullable', 'string', 'max:40'],
            'starts_at' => [$creating ? 'required' : 'sometimes', 'date'],
            'duration_min' => ['nullable', 'integer', 'min:5', 'max:720'],
            'assigned_user_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function store(Request $request, CrmContact $contact): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'update')) {
            return $denied;
        }
        $data = $request->validate(self::eventRules(true));
        $event = $this->events->schedule($contact, $data, $this->actor($request));

        return response()->json(['event' => $this->present->event($event->fresh())], 201);
    }

    public function update(Request $request, CrmEvent $event): JsonResponse
    {
        $this->authorizeEvent($request, $event);
        $data = $request->validate(self::eventRules(false));
        $this->events->update($event, $data, $this->actor($request));

        return response()->json(['event' => $this->present->event($event->fresh())]);
    }

    public function complete(Request $request, CrmEvent $event): JsonResponse
    {
        $this->authorizeEvent($request, $event);
        $data = $request->validate([
            'status' => ['required', 'in:done,missed'],
            'outcome_note' => ['nullable', 'string', 'max:2000'],
            'next' => ['nullable', 'array'],
            'next.type_id' => ['required_with:next', 'integer'],
            'next.starts_at' => ['required_with:next', 'date'],
            'next.duration_min' => ['nullable', 'integer', 'min:5', 'max:720'],
            'next.note' => ['nullable', 'string', 'max:2000'],
        ]);
        $result = $this->events->complete($event, $data, $this->actor($request));

        return response()->json([
            'event' => $this->present->event($result['event']->fresh()),
            'next' => $result['next'] ? $this->present->event($result['next']->fresh()) : null,
        ]);
    }

    /** Events on contacts the viewer cannot see answer 404; reps may only change their own. */
    private function authorizeEvent(Request $request, CrmEvent $event): void
    {
        $viewer = $this->actor($request);
        $visible = CrmEvent::query()->visible($viewer)->whereKey($event->id)->exists();
        abort_unless($visible, 404);
        abort_unless($this->access()->can($viewer, 'crm-contacts', 'update'), 403);
        if ((int) $event->assigned_user_id !== (int) $viewer->id && ! $this->access()->canViewAll($viewer)) {
            abort(403, 'This follow-up belongs to someone else.');
        }
    }

    public function types(Request $request): JsonResponse
    {
        return response()->json(['data' => CrmEventType::query()->orderBy('sort')->orderBy('label')->get()->map(fn ($t) => [
            'id' => $t->id, 'key' => $t->key, 'label' => $t->label, 'icon' => $t->icon,
            'is_system' => $t->is_system, 'is_active' => $t->is_active, 'counts_as_contact' => $t->counts_as_contact,
        ])->all()]);
    }

    public function storeType(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-events', 'manage-types')) {
            return $denied;
        }
        $data = $request->validate(['label' => ['required', 'string', 'max:80'], 'icon' => ['nullable', 'string', 'max:32'], 'counts_as_contact' => ['nullable', 'boolean']]);
        $key = Str::slug($data['label'], '_');
        abort_if(CrmEventType::query()->where('key', $key)->exists(), 422, 'An event type with this name already exists.');
        $type = CrmEventType::create([
            'key' => $key, 'label' => $data['label'], 'icon' => $data['icon'] ?? 'calendar',
            'counts_as_contact' => $data['counts_as_contact'] ?? true, 'is_system' => false, 'is_active' => true, 'sort' => 100,
        ]);

        return response()->json(['type' => $type], 201);
    }

    public function updateType(Request $request, CrmEventType $type): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-events', 'manage-types')) {
            return $denied;
        }
        $data = $request->validate(['label' => ['sometimes', 'string', 'max:80'], 'icon' => ['nullable', 'string', 'max:32'], 'is_active' => ['nullable', 'boolean'], 'counts_as_contact' => ['nullable', 'boolean']]);
        if ($type->is_system) {
            unset($data['is_active']);
        }
        $type->fill($data)->save();

        return response()->json(['type' => $type]);
    }
}
