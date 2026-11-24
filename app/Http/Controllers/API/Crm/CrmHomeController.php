<?php

namespace App\Http\Controllers\API\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEvent;
use App\Models\Crm\CrmGoal;
use App\Models\Crm\CrmNotice;
use App\Services\Crm\CrmBoardStats;
use App\Services\Crm\CrmClientHealth;
use App\Services\Crm\CrmEventPresenter;
use App\Services\Crm\CrmGoalService;
use App\Services\Crm\CrmReports;
use App\Services\Crm\Robbie\CrmInsights;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Home "my day" (F1), goals and reports (F2). */
class CrmHomeController extends CrmController
{
    public function home(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $events = app(CrmEventPresenter::class);
        $today = CrmEvent::query()->visible($user)->where('assigned_user_id', $user->id)->where('status', 'planned')
            ->where('starts_at', '<=', now()->endOfDay())->orderBy('starts_at')->limit(30)->get();
        $mineOnly = ! $this->access()->canViewAll($user);

        return response()->json([
            'greeting_name' => explode(' ', trim($user->name))[0] ?? $user->name,
            'today' => $today->map(fn ($e) => $events->event($e))->all(),
            'overdue_count' => $today->filter(fn ($e) => $e->starts_at->lt(now()->startOfDay()))->count(),
            'goals' => app(CrmGoalService::class)->progress($user->id),
            'pipeline' => app(CrmBoardStats::class)->pipeline(),
            'at_risk' => CrmContact::query()->where('health', CrmClientHealth::AT_RISK)->where('stage', '!=', CrmContact::STAGE_LOST)
                ->when($mineOnly, fn ($q) => $q->where('assigned_rep_id', $user->id))
                ->orderBy('last_shoot_on')->limit(5)->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'company' => $c->company, 'usual_gap_days' => $c->usual_gap_days, 'last_shoot_on' => $c->last_shoot_on?->toDateString()])->all(),
            'new_leads' => CrmContact::query()->whereIn('stage', [CrmContact::STAGE_OUTREACH, CrmContact::STAGE_INQUIRY])->whereNull('last_contacted_at')
                ->whereNotNull('first_contact_due_at')
                ->when($mineOnly, fn ($q) => $q->where('assigned_rep_id', $user->id))
                ->orderBy('first_contact_due_at')->limit(5)->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'company' => $c->company, 'lead_source' => $c->lead_source, 'first_contact_due_at' => $c->first_contact_due_at?->toIso8601String(), 'late' => $c->first_contact_due_at?->isPast() ?? false])->all(),
            'notices' => CrmNotice::query()->where('user_id', $user->id)->whereNull('read_at')->latest('id')->limit(8)->get()
                ->map(fn ($n) => ['id' => $n->id, 'type' => $n->type, 'title' => $n->title, 'body' => $n->body, 'contact_id' => $n->contact_id, 'data' => $n->data, 'created_at' => $n->created_at?->toIso8601String()])->all(),
            'insights' => app(CrmInsights::class)->for($user),
        ]);
    }

    public function goals(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $data = $request->validate(['month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'], 'user_id' => ['nullable', 'integer']]);
        $month = CrmGoalService::month($data['month'] ?? null);
        $service = app(CrmGoalService::class);

        if (! $this->access()->canViewAll($user)) {
            return response()->json(['month' => $month, 'people' => [['user' => $this->presenter()->user($user->id), 'goals' => $service->progress($user->id, $month)]], 'metrics' => CrmGoal::METRIC_LABELS, 'can_set' => false]);
        }
        $people = isset($data['user_id'])
            ? collect([(int) $data['user_id']])
            : app(\App\Services\Crm\CrmRepSuggester::class)->reps()->pluck('id');

        return response()->json([
            'month' => $month,
            'people' => $people->map(fn ($id) => ['user' => $this->presenter()->user((int) $id), 'goals' => $service->progress((int) $id, $month)])->filter(fn ($p) => $p['user'])->values()->all(),
            'metrics' => CrmGoal::METRIC_LABELS,
            'can_set' => $this->access()->canAssign($user),
        ]);
    }

    public function setGoals(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'assign')) {
            return $denied;
        }
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'targets' => ['required', 'array'],
            'targets.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        $targets = collect($data['targets'])->only(CrmGoal::METRICS)->all();
        app(CrmGoalService::class)->set((int) $data['user_id'], $data['month'], $targets, $this->actor($request));

        return response()->json(['goals' => app(CrmGoalService::class)->progress((int) $data['user_id'], $data['month'])]);
    }

    public function reports(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'rep_id' => ['nullable', 'integer'], 'range' => ['nullable', Rule::in(['month', 'last_month', 'quarter', 'year', '30d', '90d'])]]);
        [$from, $to] = $this->range($data);
        $repId = $this->access()->canViewAll($user) ? ($data['rep_id'] ?? null) : null;

        return response()->json(app(CrmReports::class)->build($from, $to, $repId ? (int) $repId : null));
    }

    private function range(array $data): array
    {
        if (! empty($data['from']) && ! empty($data['to'])) {
            return [Carbon::parse($data['from']), Carbon::parse($data['to'])];
        }

        return match ($data['range'] ?? 'month') {
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'quarter' => [now()->startOfQuarter(), now()],
            'year' => [now()->startOfYear(), now()],
            '30d' => [now()->subDays(29), now()],
            '90d' => [now()->subDays(89), now()],
            default => [now()->startOfMonth(), now()],
        };
    }
}
