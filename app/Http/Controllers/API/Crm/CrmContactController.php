<?php

namespace App\Http\Controllers\API\Crm;

use App\Models\Crm\CrmActivity;
use App\Models\Crm\CrmContact;
use App\Services\Crm\CrmAssignmentService;
use App\Services\Crm\CrmContactService;
use App\Services\Crm\CrmDuplicateException;
use App\Services\Crm\CrmStageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CrmContactController extends CrmController
{
    public function __construct(
        private readonly CrmContactService $contacts,
        private readonly CrmStageService $stages,
        private readonly CrmAssignmentService $assignment,
    ) {}

    /** Every contact the viewer can see: pipeline, client pool and (on request) lost. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'stage' => ['nullable', Rule::in(CrmContact::STAGES)],
            'rep_id' => ['nullable'],
            'segment' => ['nullable', Rule::in(CrmContact::SEGMENTS)],
            'client_type' => ['nullable', Rule::in(CrmContact::CLIENT_TYPES)],
            'market_state' => ['nullable', 'string', 'max:40'],
            'include_lost' => ['nullable', 'boolean'],
            'needs_review' => ['nullable', 'boolean'],
            'company_id' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in(['recent', 'name', 'stage_changed'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = self::filtered(CrmContact::query(), $filters);
        $sort = $filters['sort'] ?? 'recent';
        $query->when($sort === 'name', fn ($q) => $q->orderBy('name'))
            ->when($sort === 'stage_changed', fn ($q) => $q->orderByDesc('stage_changed_at'))
            ->when($sort === 'recent', fn ($q) => $q->orderByDesc('id'));

        $page = $query->paginate((int) ($filters['per_page'] ?? 50));
        $this->presenter()->primeUsers(collect($page->items()));

        return response()->json([
            'data' => collect($page->items())->map(fn (CrmContact $c) => $this->presenter()->contact($c))->all(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** Shared list filters, also used by the board, exports and campaign audiences. */
    public static function filtered($query, array $filters)
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return $query
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
                $digits = preg_replace('/\D+/', '', $search);
                $q->where(function ($inner) use ($like, $digits) {
                    $inner->whereRaw('LOWER(name) LIKE ? ESCAPE \'!\'', [$like])
                        ->orWhereRaw('LOWER(COALESCE(company, \'\')) LIKE ? ESCAPE \'!\'', [$like])
                        ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ? ESCAPE \'!\'', [$like]);
                    if (strlen((string) $digits) >= 4) {
                        $inner->orWhere('phone_digits', 'like', '%'.$digits.'%');
                    }
                });
            })
            ->when(! empty($filters['stage']), fn ($q) => $q->where('stage', $filters['stage']))
            ->when(empty($filters['stage']) && ! ($filters['include_lost'] ?? false), fn ($q) => $q->where('stage', '!=', CrmContact::STAGE_LOST))
            ->when(isset($filters['rep_id']) && $filters['rep_id'] !== '' && $filters['rep_id'] !== null, function ($q) use ($filters) {
                $filters['rep_id'] === 'unassigned' ? $q->whereNull('assigned_rep_id') : $q->where('assigned_rep_id', (int) $filters['rep_id']);
            })
            ->when(! empty($filters['segment']), fn ($q) => $q->where('segment', $filters['segment']))
            ->when(! empty($filters['client_type']), fn ($q) => $q->where('client_type', $filters['client_type']))
            ->when(! empty($filters['market_state']), fn ($q) => $q->where('market_state', $filters['market_state']))
            ->when(! empty($filters['company_id']), fn ($q) => $q->where('company_id', (int) $filters['company_id']))
            ->when($filters['needs_review'] ?? false, fn ($q) => $q->where('needs_review', true));
    }

    public static function fieldRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:160'],
            'company' => ['nullable', 'string', 'max:160'],
            'title' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:190'],
            'market_state' => ['nullable', 'string', 'max:40'],
            'market_city' => ['nullable', 'string', 'max:120'],
            'segment' => [$required, Rule::in(CrmContact::SEGMENTS)],
            'client_type' => ['nullable', Rule::in(CrmContact::CLIENT_TYPES)],
            'lead_source' => ['nullable', Rule::in(CrmContact::LEAD_SOURCES)],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'create')) {
            return $denied;
        }

        $data = $request->validate(self::fieldRules(true) + [
            'assigned_rep_id' => ['nullable', 'integer'],
            'confirm_different_person' => ['nullable', 'boolean'],
        ]);
        $actor = $this->actor($request);
        $options = ['confirm_different_person' => (bool) ($data['confirm_different_person'] ?? false)];
        if ($request->exists('assigned_rep_id')) {
            $options['assigned_rep_id'] = $data['assigned_rep_id'] ?? null;
        }
        unset($data['assigned_rep_id'], $data['confirm_different_person']);

        try {
            $contact = $this->contacts->create($data, $actor, $options);
        } catch (CrmDuplicateException $e) {
            return $this->duplicateResponse($e, $actor);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['contact' => $this->presenter()->contact($contact->fresh())], 201);
    }

    /** Live check while typing in the add form. Reps learn only that a match exists. */
    public function checkDuplicate(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:160'], 'email' => ['nullable', 'string', 'max:190'], 'phone' => ['nullable', 'string', 'max:40'], 'ignore_id' => ['nullable', 'integer']]);
        $match = app(\App\Services\Crm\CrmDuplicateGuard::class)->find($data, $data['ignore_id'] ?? null);
        if (! $match) {
            return response()->json(['duplicate' => null]);
        }
        $payload = ['blocked' => ! $match->isOverridable(), 'reason' => $match->type];
        if ($this->access()->canViewAll($request->user())) {
            $payload['match'] = $this->presenter()->contact($match->contact);
            $payload['overridable'] = $match->isOverridable();
        }

        return response()->json(['duplicate' => $payload]);
    }

    public function show(Request $request, CrmContact $contact): JsonResponse
    {
        return response()->json(['contact' => $this->presenter()->contact($contact, $request->user(), $this->extras($contact))]);
    }

    public function review(Request $request, CrmContact $contact): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'assign')) {
            return $denied;
        }

        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        \App\Support\LockedWrite::run(fn () => \Illuminate\Support\Facades\DB::transaction(function () use ($request, $contact, $data) {
            $current = CrmContact::query()->lockForUpdate()->findOrFail($contact->id);
            if ($current->needs_review) {
                app(\App\Services\Crm\CrmActivityLogger::class)->log(
                    $current, 'import_reviewed', $this->actor($request), 'Client review completed',
                    ['note' => $data['note'], 'review_reasons' => $current->review_reasons],
                );
                $current->forceFill(['needs_review' => false, 'review_reasons' => null])->save();
            }
        }), 'crm.contact.review');

        return $this->show($request, $contact->refresh());
    }

    /** Hook for later phases (next event, drip, health) without changing the base shape. */
    protected function extras(CrmContact $contact): array
    {
        return app(\App\Services\Crm\CrmContactExtras::class)->for($contact);
    }

    public function update(Request $request, CrmContact $contact): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'update')) {
            return $denied;
        }
        if ($request->exists('assigned_rep_id')) {
            return response()->json(['message' => 'Only an admin can change who owns a contact, using Assign.'], 403);
        }

        $data = $request->validate(self::fieldRules(false) + ['confirm_different_person' => ['nullable', 'boolean']]);
        $confirm = (bool) ($data['confirm_different_person'] ?? false);
        unset($data['confirm_different_person']);

        try {
            $this->contacts->update($contact, $data, $this->actor($request), $confirm);
        } catch (CrmDuplicateException $e) {
            return $this->duplicateResponse($e, $this->actor($request));
        }

        return response()->json(['contact' => $this->presenter()->contact($contact->fresh(), $request->user(), $this->extras($contact))]);
    }

    public function stage(Request $request, CrmContact $contact): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'update')) {
            return $denied;
        }
        $data = $request->validate(['stage' => ['required', 'string']]);
        $this->stages->move($contact, $data['stage'], $this->actor($request));

        return response()->json(['contact' => $this->presenter()->contact($contact->fresh())]);
    }

    public function lost(Request $request, CrmContact $contact): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'update')) {
            return $denied;
        }
        $data = $request->validate(['reason' => ['required', Rule::in(CrmContact::LOST_REASONS)], 'note' => ['nullable', 'string', 'max:2000']]);
        $this->stages->markLost($contact, $data['reason'], $data['note'] ?? null, $this->actor($request));

        return response()->json(['contact' => $this->presenter()->contact($contact->fresh())]);
    }

    public function reopen(Request $request, CrmContact $contact): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'update')) {
            return $denied;
        }
        $data = $request->validate(['stage' => ['required', 'string']]);
        $this->stages->reopen($contact, $data['stage'], $this->actor($request));

        return response()->json(['contact' => $this->presenter()->contact($contact->fresh())]);
    }

    public function firstShoot(Request $request, CrmContact $contact): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'update')) {
            return $denied;
        }
        $data = $request->validate(['date' => ['required', 'date', 'before_or_equal:today'], 'client_type' => ['nullable', Rule::in(CrmContact::CLIENT_TYPES)]]);
        $this->stages->firstShootCompleted($contact, $data['date'], $data['client_type'] ?? null, $this->actor($request));

        return response()->json(['contact' => $this->presenter()->contact($contact->fresh())]);
    }

    public function assign(Request $request, CrmContact $contact): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'assign')) {
            return $denied;
        }
        $data = $request->validate(['rep_id' => ['nullable', 'integer']]);
        $this->assignment->assign($contact, $data['rep_id'] ?? null, $this->actor($request));

        return response()->json(['contact' => $this->presenter()->contact($contact->fresh())]);
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'assign')) {
            return $denied;
        }
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer'], 'rep_id' => ['nullable', 'integer']]);
        $actor = $this->actor($request);
        $contacts = CrmContact::query()->whereIn('id', $data['ids'])->get();
        foreach ($contacts as $contact) {
            $this->assignment->assign($contact, $data['rep_id'] ?? null, $actor);
        }

        return response()->json(['updated' => $contacts->count()]);
    }

    public function activity(Request $request, CrmContact $contact): JsonResponse
    {
        $data = $request->validate(['type' => ['nullable', 'string', 'max:40'], 'before_id' => ['nullable', 'integer']]);
        $items = CrmActivity::query()
            ->where('contact_id', $contact->id)
            ->when(! empty($data['type']), fn ($q) => $q->where('type', $data['type']))
            ->when(! empty($data['before_id']), fn ($q) => $q->where('id', '<', (int) $data['before_id']))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $items->map(fn ($a) => $this->presenter()->activity($a))->all()]);
    }
}
