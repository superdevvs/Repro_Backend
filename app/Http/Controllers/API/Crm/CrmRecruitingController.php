<?php

namespace App\Http\Controllers\API\Crm;

use App\Models\Crm\CrmEmailTemplate;
use App\Models\Recruiting\RecruitApplicant;
use App\Models\Recruiting\RecruitApplicantFile;
use App\Models\Recruiting\RecruitJobPosting;
use App\Services\Crm\CrmEventPresenter;
use App\Services\Crm\CrmEventService;
use App\Services\Crm\Email\CrmEmailSender;
use App\Services\Crm\Email\CrmHtmlSanitizer;
use App\Services\Crm\Email\CrmSendBlocked;
use App\Services\Recruiting\RecruitApplicantService;
use App\Services\Recruiting\RecruitFileService;
use App\Services\Recruiting\RecruitHireService;
use App\Services\Recruiting\RecruitPresenter;
use App\Support\DatabaseSql;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Recruiting board and applicant records (frames 10, 11, 15, 16). The route
 * group requires crm-recruiting:view; changes need manage, documents need
 * documents. Applicants are never deleted.
 */
class CrmRecruitingController extends CrmController
{
    public function __construct(
        private readonly RecruitApplicantService $applicants,
        private readonly RecruitPresenter $present,
        private readonly RecruitFileService $files,
    ) {}

    public function meta(Request $request): JsonResponse
    {
        $user = $this->actor($request);

        return response()->json([
            'stages' => collect(RecruitApplicant::STAGE_LABELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'roles' => [['key' => 'photographer', 'label' => 'Photographer'], ['key' => 'sales_rep', 'label' => 'Sales rep']],
            'sources' => RecruitApplicant::SOURCES,
            'specialties' => RecruitApplicant::SPECIALTIES,
            'score_keys' => RecruitApplicantService::SCORE_KEYS,
            'file_kinds' => RecruitApplicantFile::KINDS,
            'states' => \App\Services\Recruiting\RecruitCoverage::STATES,
            'can' => [
                'manage' => $this->access()->can($user, 'crm-recruiting', 'manage'),
                'documents' => $this->access()->can($user, 'crm-recruiting', 'documents'),
            ],
        ]);
    }

    /** Board columns for one role, plus how many are not moving forward. */
    public function board(Request $request): JsonResponse
    {
        $data = $request->validate(['role' => ['nullable', Rule::in(RecruitApplicant::ROLES)], 'q' => ['nullable', 'string', 'max:120'], 'state' => ['nullable', 'string', 'max:40']]);
        $role = $data['role'] ?? 'photographer';
        $query = $this->filtered(RecruitApplicant::query()->where('role', $role), $data);
        $items = (clone $query)->whereIn('stage', RecruitApplicant::STAGES)->orderByDesc('stage_changed_at')->limit(600)->get();
        $postings = RecruitJobPosting::query()->pluck('title', 'id')->all();

        return response()->json([
            'role' => $role,
            'columns' => collect(RecruitApplicant::STAGES)->map(fn ($stage) => [
                'stage' => $stage,
                'label' => RecruitApplicant::STAGE_LABELS[$stage],
                'cards' => $items->where('stage', $stage)->values()->map(fn ($a) => $this->present->card($a, $postings))->all(),
            ])->all(),
            'not_moving_forward' => (clone $query)->where('stage', RecruitApplicant::EXIT)->count(),
            'counts' => [
                'photographer' => RecruitApplicant::query()->where('role', 'photographer')->whereNotIn('stage', ['hired', RecruitApplicant::EXIT])->count(),
                'sales_rep' => RecruitApplicant::query()->where('role', 'sales_rep')->whereNotIn('stage', ['hired', RecruitApplicant::EXIT])->count(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role' => ['nullable', Rule::in(RecruitApplicant::ROLES)], 'stage' => ['nullable', 'string', 'max:24'],
            'q' => ['nullable', 'string', 'max:120'], 'state' => ['nullable', 'string', 'max:40'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = $this->filtered(RecruitApplicant::query(), $data)
            ->when($data['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($data['stage'] ?? null, fn ($q, $stage) => $q->where('stage', $stage))
            ->orderByDesc('updated_at');
        $page = $query->paginate((int) ($data['per_page'] ?? 50));
        $postings = RecruitJobPosting::query()->pluck('title', 'id')->all();

        return response()->json([
            'data' => collect($page->items())->map(fn ($a) => $this->present->card($a, $postings))->all(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public static function applicantRules(bool $creating): array
    {
        $r = $creating ? 'required' : 'sometimes';

        return [
            'role' => [$r, Rule::in(RecruitApplicant::ROLES)],
            'name' => [$r, 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:40'],
            'posting_id' => ['nullable', 'integer'],
            'source' => ['nullable', Rule::in(RecruitApplicant::SOURCES)],
            'states' => ['nullable', 'array', 'max:60'],
            'states.*' => ['string', 'max:40'],
            'metros' => ['nullable', 'string', 'max:255'],
            'travel_radius' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'portfolio_url' => ['nullable', 'url', 'max:500'],
            'gear' => ['nullable', 'array', 'max:30'],
            'gear.*' => ['string', 'max:120'],
            'flies_drone' => ['nullable', 'boolean'],
            'part107_number' => ['nullable', 'string', 'max:40'],
            'part107_expiry' => ['nullable', 'date'],
            'insurance_expiry' => ['nullable', 'date'],
            'sales_experience' => ['nullable', 'string', 'max:5000'],
            'specialty' => ['nullable', Rule::in(RecruitApplicant::SPECIALTIES)],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-recruiting', 'manage')) {
            return $denied;
        }
        $data = $request->validate(self::applicantRules(true));
        $data['source'] ??= 'other';
        $applicant = $this->applicants->create($data, $this->actor($request));

        return response()->json(['applicant' => $this->full($request, $applicant)], 201);
    }

    public function show(Request $request, RecruitApplicant $applicant): JsonResponse
    {
        return response()->json(['applicant' => $this->full($request, $applicant)]);
    }

    public function update(Request $request, RecruitApplicant $applicant): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-recruiting', 'manage')) {
            return $denied;
        }
        $this->applicants->update($applicant, $request->validate(self::applicantRules(false)), $this->actor($request));

        return response()->json(['applicant' => $this->full($request, $applicant->fresh())]);
    }

    public function stage(Request $request, RecruitApplicant $applicant): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-recruiting', 'manage')) {
            return $denied;
        }
        $data = $request->validate(['stage' => ['required', 'string'], 'reason' => ['nullable', 'string', 'max:255']]);
        $this->applicants->move($applicant, $data['stage'], $this->actor($request), $data['reason'] ?? null);

        return response()->json(['applicant' => $this->full($request, $applicant->fresh())]);
    }

    public function exit(Request $request, RecruitApplicant $applicant): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-recruiting', 'manage')) {
            return $denied;
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->applicants->exit($applicant, $data['reason'], $this->actor($request));

        return response()->json(['applicant' => $this->full($request, $applicant->fresh())]);
    }

    public function reopen(Request $request, RecruitApplicant $applicant): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-recruiting', 'manage')) {
            return $denied;
        }
        $this->applicants->reopen($applicant, $this->actor($request));

        return response()->json(['applicant' => $this->full($request, $applicant->fresh())]);
    }

    public function hire(Request $request, RecruitApplicant $applicant): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-recruiting', 'manage')) {
            return $denied;
        }
        $data = $request->validate(['link_existing' => ['nullable', 'boolean'], 'send_invite' => ['nullable', 'boolean'], 'start_date' => ['nullable', 'date']]);
        $result = app(RecruitHireService::class)->hire($applicant, $this->actor($request), [
            'link_existing' => (bool) ($data['link_existing'] ?? false),
            'send_invite' => (bool) ($data['send_invite'] ?? true),
            'start_date' => $data['start_date'] ?? null,
        ]);

        return response()->json([
            'applicant' => $this->full($request, $applicant->fresh()),
            'account' => ['id' => $result['user']->id, 'role' => $result['user']->role, 'created' => $result['created']],
        ]);
    }

    public function note(Request $request, RecruitApplicant $applicant): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-recruiting', 'manage')) {
            return $denied;
        }
        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'scores' => ['nullable', 'array'],
            'scores.*' => ['integer', 'min:1', 'max:5'],
        ]);
        $note = $this->applicants->addNote($applicant, $data['body'], $data['scores'] ?? null, 'note', $this->actor($request));

        return response()->json(['note' => $this->present->note($note), 'score' => $applicant->fresh()->score], 201);
    }

    public function upload(Request $request, RecruitApplicant $applicant): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-recruiting', 'documents')) {
            return $denied;
        }
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:'.RecruitFileService::MIMES, 'max:'.RecruitFileService::MAX_KB],
            'kind' => ['required', Rule::in(RecruitApplicantFile::KINDS)],
        ]);
        $file = $this->files->store($applicant, $data['file'], $data['kind'], $this->actor($request));

        return response()->json(['file' => $this->present->file($file)], 201);
    }

    public function download(Request $request, RecruitApplicantFile $recruitFile): StreamedResponse|JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-recruiting', 'documents')) {
            return $denied;
        }

        return $this->files->download($recruitFile, $request->boolean('inline'));
    }

    /** Interviews and other events with an applicant. An interview moves early applicants to Interview. */
    public function schedule(Request $request, RecruitApplicant $applicant): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-recruiting', 'manage')) {
            return $denied;
        }
        $data = $request->validate(CrmEventController::eventRules(true));
        $data['type'] ??= 'interview';
        $user = $this->actor($request);
        $data['assigned_user_id'] ??= $user->id;
        $event = app(CrmEventService::class)->schedule(null, $data, $user, false, $applicant->id);
        $type = app(CrmEventService::class)->resolveType($data);
        if ($type->key === 'interview' && in_array($applicant->stage, ['applied', 'screened'], true)) {
            $this->applicants->transition($applicant, 'interview', $user, 'Interview scheduled');
        }

        return response()->json(['event' => app(CrmEventPresenter::class)->event($event->fresh(), false)], 201);
    }

    /** A recruiting email from the signed-in user's approved signature. Not marketing: no unsubscribe footer. */
    public function email(Request $request, RecruitApplicant $applicant): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-recruiting', 'manage')) {
            return $denied;
        }
        $data = $request->validate([
            'template_id' => ['nullable', 'integer'],
            'subject' => ['required_without:template_id', 'nullable', 'string', 'max:200'],
            'body_html' => ['required_without:template_id', 'nullable', 'string', 'max:50000'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);
        $version = null;
        if (! empty($data['template_id'])) {
            $template = CrmEmailTemplate::query()->whereKey($data['template_id'])->whereNotNull('approved_version_id')->firstOrFail();
            $version = $template->approvedVersion;
        }
        $user = $this->actor($request);
        $content = [
            'subject' => trim((string) ($data['subject'] ?? '')) ?: (string) $version?->subject,
            'body_html' => ! empty($data['body_html']) ? CrmHtmlSanitizer::clean($data['body_html']) : (string) $version?->body_html,
            'preheader' => $version?->preheader, 'image_url' => $version?->image_url, 'cta_label' => $version?->cta_label, 'cta_url' => $version?->cta_url,
        ];
        [$first] = explode(' ', trim($applicant->name).' ');

        try {
            $send = app(CrmEmailSender::class)->send([
                'to_email' => $applicant->email,
                'applicant_id' => $applicant->id,
                'sender' => $user,
                'source_type' => 'recruiting',
                'source_id' => $applicant->id,
                'template_version' => $version,
                'content' => $content,
                'vars' => ['first_name' => $first, 'full_name' => $applicant->name],
                'idempotency_key' => ! empty($data['idempotency_key']) ? 'recruit-'.$user->id.'-'.$data['idempotency_key'] : null,
                'marketing' => false,
            ]);
        } catch (CrmSendBlocked $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json(['email' => ['id' => $send->id, 'status' => $send->status, 'subject' => $send->subject]], 201);
    }

    private function filtered($query, array $data)
    {
        if ($q = trim((string) ($data['q'] ?? ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($q)).'%';
            $query->where(function ($inner) use ($like) {
                foreach (['name', 'email', 'city'] as $column) {
                    $inner->orWhereRaw(...DatabaseSql::sqliteLowerLike($column, $like, $inner->getConnection()));
                }
            });
        }
        if ($state = \App\Services\Recruiting\RecruitCoverage::stateCode($data['state'] ?? null)) {
            $query->where(fn ($w) => $w->where('state', $state)->orWhere('states', 'like', '%"'.$state.'"%'));
        }

        return $query;
    }

    private function full(Request $request, RecruitApplicant $applicant): array
    {
        return $this->present->applicant($applicant, $this->access()->can($this->actor($request), 'crm-recruiting', 'documents'));
    }
}
