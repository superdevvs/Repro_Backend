<?php

namespace App\Http\Controllers\API\Crm;

use App\Models\Recruiting\RecruitApplicant;
use App\Models\Recruiting\RecruitJobPosting;
use App\Models\Recruiting\RecruitRoster;
use App\Services\Recruiting\RecruitCoverage;
use App\Services\Recruiting\RecruitPostingService;
use App\Services\Recruiting\RecruitPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Job postings, the coverage map and the roster of hired people with expiry dates. */
class CrmRecruitingSetupController extends CrmController
{
    public function __construct(
        private readonly RecruitPostingService $postings,
        private readonly RecruitPresenter $present,
    ) {}

    public function coverage(): JsonResponse
    {
        return response()->json(app(RecruitCoverage::class)->map());
    }

    public function roster(Request $request): JsonResponse
    {
        $expiring = $request->boolean('expiring');
        $soon = now()->startOfDay()->addDays((int) config('crm.expiry_reminder_days', 30))->toDateString();
        $rows = RecruitRoster::query()->where('active', true)
            ->when($expiring, fn ($q) => $q->where(fn ($w) => $w->where('part107_expiry', '<=', $soon)->orWhere('insurance_expiry', '<=', $soon)))
            ->orderBy('id')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn ($r) => $this->present->roster($r))->all()]);
    }

    public function postings(): JsonResponse
    {
        $counts = RecruitApplicant::query()->whereNotNull('posting_id')->selectRaw('posting_id, COUNT(*) as c')->groupBy('posting_id')->pluck('c', 'posting_id');
        $items = RecruitJobPosting::query()->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END")->orderByDesc('id')->get();

        return response()->json(['data' => $items->map(fn ($p) => $this->present->posting($p, (int) ($counts[$p->id] ?? 0)))->all()]);
    }

    public static function postingRules(bool $creating): array
    {
        $r = $creating ? 'required' : 'sometimes';

        return [
            'role' => [$r, Rule::in(RecruitApplicant::ROLES)],
            'title' => [$r, 'string', 'max:160'],
            'state' => [$r, 'string', 'max:40'],
            'region' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function storePosting(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-recruiting', 'manage')) {
            return $denied;
        }
        $posting = $this->postings->create($request->validate(self::postingRules(true)), $this->actor($request));

        return response()->json(['posting' => $this->present->posting($posting, 0)], 201);
    }

    public function updatePosting(Request $request, RecruitJobPosting $posting): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-recruiting', 'manage')) {
            return $denied;
        }
        $this->postings->update($posting, $request->validate(self::postingRules(false)));

        return response()->json(['posting' => $this->present->posting($posting->fresh())]);
    }

    public function postingStatus(Request $request, RecruitJobPosting $posting): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-recruiting', 'manage')) {
            return $denied;
        }
        $data = $request->validate(['status' => ['required', Rule::in(RecruitPostingService::STATUSES)]]);
        $this->postings->setStatus($posting, $data['status']);

        return response()->json(['posting' => $this->present->posting($posting->fresh())]);
    }
}
