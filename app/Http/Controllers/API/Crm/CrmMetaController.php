<?php

namespace App\Http\Controllers\API\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmNotice;
use App\Services\Crm\CrmBoardStats;
use App\Services\Crm\CrmPresenter;
use App\Services\Crm\CrmRepSuggester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Labels, the team list, what the viewer may do, and their notices. */
class CrmMetaController extends CrmController
{
    public function meta(Request $request): JsonResponse
    {
        $user = $request->user();
        $access = $this->access();
        $canViewAll = $access->canViewAll($user);

        $reps = $canViewAll
            ? app(CrmRepSuggester::class)->reps()->map(fn ($u) => CrmPresenter::person($u))->values()->all()
            : [CrmPresenter::person($user)];

        $counts = [
            'unassigned' => $access->canAssign($user) ? CrmContact::query()->whereNull('assigned_rep_id')->where('stage', '!=', CrmContact::STAGE_LOST)->count() : 0,
            'duplicate_attempts' => $access->canAssign($user) ? \App\Models\Crm\CrmDuplicateAlert::query()->whereNull('resolved_at')->count() : 0,
            'unread_notices' => CrmNotice::query()->where('user_id', $user->id)->whereNull('read_at')->count(),
        ];
        if (\Illuminate\Support\Facades\Schema::hasTable('crm_events')) {
            $counts['follow_ups_due'] = app(\App\Services\Crm\CrmEventPresenter::class)->dueCountFor($user);
        }

        return response()->json([
            'me' => CrmPresenter::person($user),
            'can' => [
                'view_all' => $canViewAll,
                'create' => $access->can($user, 'crm-contacts', 'create'),
                'update' => $access->can($user, 'crm-contacts', 'update'),
                'assign' => $access->canAssign($user),
                'import' => $access->can($user, 'crm-contacts', 'import'),
                'manage_event_types' => $access->can($user, 'crm-events', 'manage-types'),
                'enrol_drips' => $access->can($user, 'crm-drips', 'enrol'),
                'manage_drips' => $access->can($user, 'crm-drips', 'manage'),
                'create_campaigns' => $access->can($user, 'crm-campaigns', 'create'),
                'approve_campaigns' => $access->can($user, 'crm-campaigns', 'approve'),
                'approve_templates' => $access->can($user, 'crm-templates', 'approve'),
                'approve_signatures' => $access->can($user, 'crm-signatures', 'approve'),
                'approve_quotes' => $access->can($user, 'crm-quotes', 'approve'),
                'recruiting' => $access->can($user, 'crm-recruiting', 'view'),
                'recruiting_manage' => $access->can($user, 'crm-recruiting', 'manage'),
                'recruiting_documents' => $access->can($user, 'crm-recruiting', 'documents'),
            ],
            'counts' => $counts,
            'reps' => $reps,
            'stages' => collect(CrmContact::STAGES)->map(fn ($s) => ['key' => $s, 'label' => CrmContact::STAGE_LABELS[$s]])->all(),
            'pipeline_stages' => CrmContact::PIPELINE_STAGES,
            'segments' => [['key' => 'residential', 'label' => 'Residential'], ['key' => 'commercial', 'label' => 'Commercial']],
            'client_types' => [['key' => 'one_off', 'label' => 'One-off'], ['key' => 'recurring', 'label' => 'Recurring']],
            'lost_reasons' => collect(CrmContact::LOST_REASON_LABELS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all(),
            'lead_sources' => collect(CrmContact::LEAD_SOURCE_LABELS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all(),
            'markets' => CrmContact::query()->whereNotNull('market_state')->distinct()->orderBy('market_state')->pluck('market_state')->values()->all(),
            'settings' => [
                'first_contact_business_days' => 1,
                'quote_discount_approval_percent' => (float) config('crm.quote_discount_approval_percent'),
            ],
        ]);
    }

    public function contactStats(Request $request): JsonResponse
    {
        return response()->json(['stats' => app(CrmBoardStats::class)->contacts()]);
    }

    public function notices(Request $request): JsonResponse
    {
        $items = CrmNotice::query()->where('user_id', $request->user()->id)->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => $items->map(fn (CrmNotice $n) => [
            'id' => $n->id, 'type' => $n->type, 'title' => $n->title, 'body' => $n->body,
            'contact_id' => $n->contact_id, 'read' => $n->read_at !== null, 'created_at' => $n->created_at?->toIso8601String(),
        ])->all()]);
    }

    public function readNotices(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['integer']]);
        CrmNotice::query()->where('user_id', $request->user()->id)->whereNull('read_at')
            ->when(! empty($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
