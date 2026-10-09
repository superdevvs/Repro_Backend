<?php

namespace App\Services\Copilot;

use App\Http\Controllers\API\StudioWorkspaceController;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\PayoutReportService;
use App\Services\ReproAi\SupportKnowledgeBase;
use App\Services\ReproAi\Tools\RobbieRecordAccess;
use App\Services\RolePermissionService;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootManagementAccess;
use App\Services\Shoots\ShootNotesAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

final class CopilotData
{
    public function __construct(private readonly RobbieRecordAccess $access, private readonly RolePermissionService $permissions) {}

    public function query(User $user): Builder
    {
        abort_unless($this->permissions->userCan($user, 'shoots', 'view'), 403, 'Shoot access is disabled.');

        return $this->access->query($user)->where('status', '!=', Shoot::STATUS_IMPORT_DRAFT);
    }

    public function shoot(int $id, User $user): Shoot
    {
        $shoot = $this->query($user)->with(['services', 'client:id,name', 'photographer:id,name', 'editor:id,name'])->find($id);
        abort_unless($shoot && $this->access->canRead($shoot, $user), 404, 'Shoot not found.');

        return $shoot;
    }

    public function projection(Shoot $shoot, User $user): array
    {
        $data = ['id' => $shoot->id, 'address' => $shoot->address, 'city' => $shoot->city, 'state' => $shoot->state,
            'zip' => $shoot->zip, 'status' => $shoot->status, 'workflow_status' => $shoot->workflow_status,
            'scheduled_at' => $shoot->scheduled_at?->toIso8601String(), 'timezone' => $shoot->timezone,
            'client' => $shoot->client?->name, 'photographer' => $shoot->photographer?->name,
            'services' => $shoot->services->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all(),
            'media_counts' => ['raw' => $shoot->raw_photo_count, 'edited' => $shoot->edited_photo_count,
                'raw_missing' => $shoot->raw_missing_count, 'edited_missing' => $shoot->edited_missing_count],
            'source_url' => $this->url($shoot), 'updated_at' => $shoot->updated_at?->toIso8601String()];
        if ($this->access->canReadBilling($shoot, $user) && (! $this->access->isStaff($user) || $this->permissions->userCan($user, 'accounting', 'view'))) {
            $data['billing'] = ['quoted_total' => $shoot->total_quote, 'payment_status' => $shoot->payment_status];
        }

        return $data;
    }

    public function detail(int $id, User $user): array
    {
        $shoot = $this->shoot($id, $user);
        $data = $this->projection($shoot, $user);
        $notes = app(ShootNotesAccessService::class);
        if ($notes->canRead($shoot, $user)) {
            $role = strtolower($user->role);
            $fields = match ($role) {
                'admin', 'superadmin', 'editing_manager', 'salesrep' => ['shoot_notes', 'company_notes', 'photographer_notes', 'editor_notes'],
                'photographer' => ['shoot_notes', 'photographer_notes'], 'editor' => ['editor_notes'], default => ['shoot_notes'],
            };
            $data['notes'] = $shoot->only($fields);
        }
        $data['observed_blockers'] = $this->blockers($shoot);
        $data['evidence'] = $shoot->only(['photos_uploaded_at', 'editing_completed_at', 'admin_verified_at', 'completed_at']);
        $data['interpretation'] = 'These are recorded states. Missing timestamps do not establish an agreed deadline or prove why someone has not acted.';

        return $data;
    }

    public function listing(array $args, User $user): array
    {
        $page = max(1, (int) ($args['page'] ?? 1));
        $query = $this->query($user)->with(['services', 'client:id,name', 'photographer:id,name']);
        if (! empty($args['query'])) {
            $query->where(fn ($q) => $q->where('address', 'like', '%'.$args['query'].'%')->orWhere('city', 'like', '%'.$args['query'].'%')->orWhere('id', $args['query']));
        }
        if (! empty($args['status'])) {
            $query->where(fn ($q) => $q->where('status', $args['status'])->orWhere('workflow_status', $args['status']));
        }
        foreach (['client_id', 'photographer_id'] as $key) {
            if (isset($args[$key])) {
                $query->where($key, $args[$key]);
            }
        }
        if (isset($args['from'])) {
            $query->whereDate('scheduled_date', '>=', $args['from']);
        }
        if (isset($args['to'])) {
            $query->whereDate('scheduled_date', '<=', $args['to']);
        }
        $result = $query->orderByDesc('scheduled_date')->orderByDesc('id')->paginate(20, ['*'], 'page', $page);

        return ['data' => $result->getCollection()->map(fn ($s) => $this->projection($s, $user))->all(), 'page' => $page,
            'total' => $result->total(), 'has_more' => $result->hasMorePages(), 'date_basis' => 'scheduled_date'];
    }

    public function operations(array $args, User $user): array
    {
        $this->range($args);
        $query = $this->query($user)->with(['services', 'client:id,name', 'photographer:id,name'])
            ->whereBetween('scheduled_date', [$args['from'], $args['to']])->whereNotIn('status', ['cancelled', 'on_hold', 'delivered'])
            ->where(fn ($q) => $q->whereNull('photographer_id')->orWhere('raw_missing_count', '>', 0)->orWhere('edited_missing_count', '>', 0)
                ->orWhereIn('workflow_status', ['uploaded', 'editing', 'review']));
        $total = (clone $query)->count();

        return ['data' => $query->orderBy('scheduled_date')->limit(100)->get()->map(fn ($s) => [...$this->projection($s, $user), 'observed_blockers' => $this->blockers($s)])->all(),
            'total' => $total, 'truncated' => $total > 100, 'date_basis' => 'scheduled_date', 'as_of' => now()->toIso8601String()];
    }

    public function services(array $args, User $user): array
    {
        $client = isset($args['client_id']) ? User::findOrFail($args['client_id']) : $user;
        abort_unless($client->id === $user->id || app(ShootManagementAccess::class)->can($user), 403);
        $records = Service::query()->bookable()->with('sqftRanges')->visibleToClient(strtolower($client->role) === 'client' ? $client : null)->orderBy('name')->get();

        return ['data' => $records->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'pricing_type' => $s->pricing_type,
            'catalog_price' => $s->pricing_type === 'variable' && ! isset($args['sqft']) ? null : $s->getPriceForSqft($args['sqft'] ?? null),
            'duration_minutes' => $s->getShootDurationMinutes(), 'photographer_required' => $s->requiresPhotographer()])->all(),
            'price_basis' => 'Catalog only. Tax, discounts and final booking rules are applied during review and submission.'];
    }

    public function finance(array $args, User $user): array
    {
        abort_unless(in_array(strtolower($user->role), ['admin', 'superadmin'], true) && $this->permissions->userCan($user, 'accounting', 'view'), 403);
        [$start, $end] = $this->range($args);
        $payments = Payment::with('refunds')->whereIn('status', [Payment::STATUS_COMPLETED, Payment::STATUS_REFUNDED])->whereBetween('processed_at', [$start, $end])->get();
        $report = app(PayoutReportService::class);
        $photo = $report->buildPhotographerSummaries($start, $end);
        $rep = $report->buildSalesRepSummaries($start, $end);
        $editor = $report->buildEditorSummaries($start, $end);

        return ['period' => $args, 'collections_by_currency' => $payments->groupBy(fn ($p) => strtoupper($p->currency ?: 'UNKNOWN'))
            ->map(fn ($rows) => ['gross_recorded' => round($rows->sum('amount'), 2), 'refunds_against_those_payments' => round($rows->sum(fn ($p) => $p->refundedAmount()), 2),
                'net_recorded' => round($rows->sum(fn ($p) => $p->netAmount()), 2)])->all(),
            'earned_payouts' => ['photographers' => round($photo->sum('payout_total'), 2), 'sales_reps' => round($rep->sum('payout_total'), 2), 'editors' => round($editor->sum('gross_total'), 2)],
            'payee_count' => ['photographers' => $photo->count(), 'sales_reps' => $rep->count(), 'editors' => $editor->count()],
            'basis' => ['collections' => 'Completed payment records processed in period, reduced by all currently recorded successful refunds against those payments.',
                'photographer_and_rep' => 'Existing earned payout report rules; scheduled/completed shoots and compensation earned in period.', 'editor' => 'Existing editor earned payout report rules.'],
            'limitations' => ['This is not net profit; overhead, other expenses, processor fees and unrecorded payouts are excluded.',
                'Period bases differ. Refunds may have occurred outside this period. Payouts use the dashboard reporting currency; do not subtract them from mixed-currency collections.',
                'Calculated payouts are provisional and preserve invoice approval and payment gates.'], 'source_url' => config('copilot.issuer').'/accounting'];
    }

    public function studio(string $id, Request $request): array
    {
        \App\Services\Studio\StudioClientAccess::authorize($request->user());
        $response = app(StudioWorkspaceController::class)->show($request, $id)->getData(true);
        $workspace = $response['data'];

        return ['workspace' => Arr::only($workspace, ['id', 'name', 'presetId', 'shootId', 'status', 'progress', 'requiresReview', 'version', 'updatedAt']),
            'source_url' => config('copilot.issuer').'/studio', 'generation' => Arr::only($workspace['generation'] ?? [], ['stage', 'completed', 'total']),
            'interpretation' => 'No provider work was submitted by this lookup. Open Studio for authorized previews and processing controls.'];
    }

    public function listingPack(int $id, User $user): array
    {
        $shoot = $this->shoot($id, $user);

        return ['shoot' => $this->projection($shoot, $user), 'property_facts' => Arr::only($shoot->property_details ?? [], ['bedrooms', 'bathrooms', 'sqft', 'square_feet', 'lot_size', 'year_built', 'property_type']),
            'media_available' => app(ShootAuthorizationSupport::class)->isShootDeliveredForClientAccess($shoot),
            'media_url' => $this->url($shoot), 'draft_instructions' => 'Draft a listing description, three social captions and a posting plan from these facts. Mark missing facts unknown; do not infer amenities from the address or internal shoot notes. Do not claim publication or attach unreleased media. Media access and payment gates remain in Repro.'];
    }

    public function clients(array $args, User $user): array
    {
        abort_unless(app(ShootManagementAccess::class)->can($user), 403);
        [$start, $end] = $this->range($args);
        $days = (int) $start->diffInDays($end->copy()->startOfDay()) + 1;
        $previousFrom = $start->copy()->subDays($days);
        $previousTo = $start->copy()->subDay()->endOfDay();
        $base = $this->query($user)->whereNotIn('status', ['cancelled', 'on_hold']);
        $current = (clone $base)->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])->selectRaw('client_id, COUNT(*) AS bookings')->groupBy('client_id')->pluck('bookings', 'client_id');
        $prior = (clone $base)->whereBetween('scheduled_date', [$previousFrom->toDateString(), $previousTo->toDateString()])->selectRaw('client_id, COUNT(*) AS bookings')->groupBy('client_id')->pluck('bookings', 'client_id');
        $ids = $current->keys()->merge($prior->keys())->unique();
        $users = User::whereIn('id', $ids)->pluck('name', 'id');
        $rows = $ids->map(fn ($id) => ['client_id' => $id, 'name' => $users[$id] ?? 'Unknown', 'current_bookings' => (int) ($current[$id] ?? 0),
            'previous_bookings' => (int) ($prior[$id] ?? 0), 'change' => (int) ($current[$id] ?? 0) - (int) ($prior[$id] ?? 0)])->sortBy('change')->values();

        return ['data' => $rows->take(50)->all(), 'total' => $rows->count(), 'truncated' => $rows->count() > 50,
            'current_period' => $args, 'comparison_period' => ['from' => $previousFrom->toDateString(), 'to' => $previousTo->toDateString()],
            'basis' => 'Scheduled bookings excluding held and cancelled shoots. A decline is observed activity, not proof of client dissatisfaction. No outreach was sent.'];
    }

    public function guide(string $query, User $user): array
    {
        $knowledge = app(SupportKnowledgeBase::class);

        return ['articles' => array_values(array_slice($knowledge->search($query, $user), 0, 3)), 'knowledge_version' => $knowledge->version()];
    }

    public function range(array $args): array
    {
        Validator::make($args, ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();
        $start = Carbon::parse($args['from'])->startOfDay();
        $end = Carbon::parse($args['to'])->endOfDay();
        abort_if($start->diffInDays($end) > 366, 422, 'Choose a range of at most one year.');

        return [$start, $end];
    }

    private function blockers(Shoot $shoot): array
    {
        $result = [];
        if (! $shoot->photographer_id) {
            $result[] = 'No shoot-level photographer assigned; check service assignments.';
        }
        if ((int) $shoot->raw_missing_count > 0) {
            $result[] = 'Recorded missing RAW media.';
        }
        if ((int) $shoot->edited_missing_count > 0) {
            $result[] = 'Recorded missing final media.';
        }
        if ($shoot->photos_uploaded_at && ! $shoot->editing_completed_at) {
            $result[] = 'Upload recorded; editing completion not recorded.';
        }
        if ($shoot->editing_completed_at && ! $shoot->admin_verified_at) {
            $result[] = 'Editing recorded; admin verification not recorded.';
        }

        return $result;
    }

    private function url(Shoot $shoot): string
    {
        return config('copilot.issuer').'/shoots/'.$shoot->id;
    }
}
