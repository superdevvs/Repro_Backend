<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceArea;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ServiceAreaMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Photographer service-area assignment tool (Req 10).
 *
 * Exposes the four seams from the design (section 3):
 *   - assign  : POST /admin/photographers/{user}/service-areas  (AC 10.1, 10.4)
 *   - filter  : GET  /admin/service-area/photographers          (AC 10.2)
 *   - preview : POST /admin/assignments/preview                 (AC 10.3, 10.5)
 *   - commit  : POST /admin/assignments/commit                  (AC 10.4)
 *
 * preview and commit share the exact match step (runMatch) so the previewed match
 * set is identical to the set commit computes; only commit persists, and it does so
 * inside a transaction. preview writes nothing.
 */
class ServiceAreaController extends Controller
{
    public function __construct(
        private readonly ServiceAreaMatcher $matcher,
        private readonly AuditLogService $auditLog,
    ) {
    }

    /**
     * Assign one or more service areas (region/state/area) to a photographer (AC 10.1, 10.4).
     *
     * Each {kind, value} is resolved to a ServiceArea (created on first use) and attached to the
     * photographer without detaching existing areas. The write runs in a transaction.
     */
    public function assign(Request $request, User $user): JsonResponse
    {
        $validated = $this->validateAreas($request);

        $areas = DB::transaction(
            fn () => $this->assignAreasToUser($user, $validated['service_areas'])
        );

        $this->auditLog->record('photographer.service_areas_assigned', $request->user(), $user, [
            'service_areas' => $areas->map(fn (ServiceArea $a) => [
                'id' => $a->id,
                'kind' => $a->kind,
                'value' => $a->value,
            ])->all(),
        ]);

        return response()->json([
            'user_id' => $user->id,
            'service_areas' => $this->serializeAreas($user->serviceAreas()->get()),
        ]);
    }

    /**
     * List photographers whose service areas match the given filter (AC 10.2).
     */
    public function filter(Request $request): JsonResponse
    {
        $filter = $this->validateFilter($request);

        $matches = $this->runMatch($filter);

        return response()->json([
            'filter' => $filter,
            'photographers' => $this->serializePhotographers($matches),
        ]);
    }

    /**
     * Preview the matching photographers for a filter WITHOUT persisting anything (AC 10.3, 10.5).
     *
     * Uses the identical match step as commit so preview and commit agree on the match set.
     */
    public function preview(Request $request): JsonResponse
    {
        $filter = $this->validateFilter($request);

        $matches = $this->runMatch($filter);

        return response()->json([
            'preview' => true,
            'filter' => $filter,
            'photographers' => $this->serializePhotographers($matches),
        ]);
    }

    /**
     * Persist a previewed assignment in a transaction (AC 10.4).
     *
     * Runs the same match step as preview (so the returned `photographers` set equals what
     * preview returns for the same filter), then attaches the filter's service area to the
     * selected photographer. Only this endpoint writes.
     */
    public function commit(Request $request): JsonResponse
    {
        $filter = $this->validateFilter($request);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        // Shared match step — computed before persistence so it matches preview exactly.
        $matches = $this->runMatch($filter);

        $user = User::findOrFail($data['user_id']);

        $assigned = DB::transaction(fn () => $this->assignAreasToUser($user, [[
            'kind' => $filter['kind'],
            'value' => $filter['value'],
        ]]));

        $this->auditLog->record('photographer.service_area_committed', $request->user(), $user, [
            'service_areas' => $assigned->map(fn (ServiceArea $a) => [
                'id' => $a->id,
                'kind' => $a->kind,
                'value' => $a->value,
            ])->all(),
        ]);

        return response()->json([
            'committed' => true,
            'filter' => $filter,
            'user_id' => $user->id,
            'assigned' => $this->serializeAreas($assigned),
            'photographers' => $this->serializePhotographers($matches),
        ]);
    }

    /**
     * The shared match step used by filter/preview/commit.
     *
     * Loads photographers with their serviceAreas relation and delegates to the pure
     * ServiceAreaMatcher so every caller resolves the same set for a given filter.
     *
     * @param  array{kind: string, value: string}  $filter
     * @return Collection<int, User>
     */
    private function runMatch(array $filter): Collection
    {
        $photographers = User::query()
            ->where(function (Builder $query) {
                $query->where('role', 'photographer')
                    ->orWhereJsonContains('secondary_roles', 'photographer');
            })
            ->with('serviceAreas')
            ->get();

        return $this->matcher->match($photographers, $filter);
    }

    /**
     * Resolve each {kind, value} to a ServiceArea (creating it if needed) and attach it to the
     * photographer without detaching existing areas.
     *
     * @param  array<int, array{kind: string, value: string, label?: string|null}>  $areas
     * @return Collection<int, ServiceArea>
     */
    private function assignAreasToUser(User $user, array $areas): Collection
    {
        $resolved = collect($areas)->map(function (array $area) {
            return ServiceArea::firstOrCreate(
                ['kind' => $area['kind'], 'value' => $area['value']],
                ['label' => $area['label'] ?? null],
            );
        });

        $user->serviceAreas()->syncWithoutDetaching($resolved->pluck('id')->all());

        return $resolved->values();
    }

    /**
     * Validate the assign payload: a non-empty list of {kind, value, label?}.
     *
     * @return array{service_areas: array<int, array{kind: string, value: string, label?: string|null}>}
     */
    private function validateAreas(Request $request): array
    {
        return $request->validate([
            'service_areas' => ['required', 'array', 'min:1'],
            'service_areas.*.kind' => ['required', 'string', Rule::in(ServiceArea::KINDS)],
            'service_areas.*.value' => ['required', 'string', 'max:255'],
            'service_areas.*.label' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * Validate and normalize a (kind, value) filter from request input.
     *
     * @return array{kind: string, value: string}
     */
    private function validateFilter(Request $request): array
    {
        $validated = $request->validate([
            'service_area_kind' => ['required', 'string', Rule::in(ServiceArea::KINDS)],
            'service_area_value' => ['required', 'string', 'max:255'],
        ]);

        return [
            'kind' => $validated['service_area_kind'],
            'value' => $validated['service_area_value'],
        ];
    }

    /**
     * @param  Collection<int, ServiceArea>  $areas
     * @return array<int, array<string, mixed>>
     */
    private function serializeAreas(Collection $areas): array
    {
        return $areas->map(fn (ServiceArea $area) => [
            'id' => $area->id,
            'kind' => $area->kind,
            'value' => $area->value,
            'label' => $area->label,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, User>  $photographers
     * @return array<int, array<string, mixed>>
     */
    private function serializePhotographers(Collection $photographers): array
    {
        return $photographers->map(fn (User $photographer) => [
            'id' => $photographer->id,
            'name' => $photographer->name,
            'email' => $photographer->email,
            'service_areas' => $this->serializeAreas($photographer->serviceAreas),
        ])->values()->all();
    }
}
