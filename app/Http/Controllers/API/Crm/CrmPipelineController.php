<?php

namespace App\Http\Controllers\API\Crm;

use App\Models\Crm\CrmContact;
use App\Services\Crm\CrmBoardStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The pipeline board and the client pool. Both go through the owner scope, so reps see only their own. */
class CrmPipelineController extends CrmController
{
    public function board(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'rep_id' => ['nullable'],
            'segment' => ['nullable', Rule::in(CrmContact::SEGMENTS)],
            'market_state' => ['nullable', 'string', 'max:40'],
        ]);

        $columns = [];
        foreach (CrmContact::PIPELINE_STAGES as $stage) {
            $base = CrmContactController::filtered(CrmContact::query(), $filters + ['stage' => $stage]);
            $count = (clone $base)->count();
            $contacts = $base->orderByDesc('stage_changed_at')->orderByDesc('id')->limit(60)->get();
            $this->presenter()->primeUsers($contacts);
            $cards = app(\App\Services\Crm\CrmBoardCards::class)->cards($contacts);
            $columns[] = [
                'stage' => $stage,
                'label' => CrmContact::STAGE_LABELS[$stage],
                'count' => $count,
                'contacts' => $cards,
            ];
        }

        $lostHidden = CrmContactController::filtered(CrmContact::query(), $filters + ['stage' => CrmContact::STAGE_LOST])->count();

        return response()->json([
            'columns' => $columns,
            'lost_hidden' => $lostHidden,
            'stats' => app(CrmBoardStats::class)->pipeline(),
        ]);
    }

    public function clientPool(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'rep_id' => ['nullable'],
            'segment' => ['nullable', Rule::in(CrmContact::SEGMENTS)],
            'client_type' => ['nullable', Rule::in(CrmContact::CLIENT_TYPES)],
            'market_state' => ['nullable', 'string', 'max:40'],
            'health' => ['nullable', Rule::in(['healthy', 'slowing', 'at_risk'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $matrix = [];
        $rows = CrmContact::query()->where('stage', CrmContact::STAGE_CLIENT_POOL)
            ->when(! empty($filters['rep_id']), fn ($q) => $q->where('assigned_rep_id', (int) $filters['rep_id']))
            ->selectRaw('segment, client_type, COUNT(*) as n')->groupBy('segment', 'client_type')->get();
        foreach (CrmContact::SEGMENTS as $segment) {
            foreach (CrmContact::CLIENT_TYPES as $type) {
                $matrix[$segment][$type] = (int) ($rows->first(fn ($r) => $r->segment === $segment && $r->client_type === $type)?->n ?? 0);
            }
        }

        $query = CrmContactController::filtered(CrmContact::query(), $filters + ['stage' => CrmContact::STAGE_CLIENT_POOL])
            ->when(! empty($filters['health']), fn ($q) => $q->where('health', $filters['health']))
            ->orderByDesc('first_shoot_date')->orderBy('name');
        $page = $query->paginate((int) ($filters['per_page'] ?? 50));
        $this->presenter()->primeUsers(collect($page->items()));
        $shootStats = app(\App\Services\Crm\CrmClientShootStats::class)->forContacts(collect($page->items()));

        $healthCounts = CrmContact::query()->where('stage', CrmContact::STAGE_CLIENT_POOL)->whereNotNull('health')
            ->selectRaw('health, COUNT(*) as n')->groupBy('health')->pluck('n', 'health');

        return response()->json([
            'matrix' => $matrix,
            'needs_review' => CrmContact::query()->where('stage', CrmContact::STAGE_CLIENT_POOL)->where('needs_review', true)->count(),
            'health' => ['healthy' => (int) ($healthCounts['healthy'] ?? 0), 'slowing' => (int) ($healthCounts['slowing'] ?? 0), 'at_risk' => (int) ($healthCounts['at_risk'] ?? 0)],
            'data' => collect($page->items())->map(fn (CrmContact $c) => $this->presenter()->contact($c, null, ['shoots' => $shootStats[$c->id] ?? null]))->all(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }
}
