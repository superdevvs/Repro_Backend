<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceArea;
use App\Models\Shoot;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Shoots\TestShoot\TestShootService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin endpoints for the Test_Shoot generator/simulator (Req 10.7-10.9).
 *
 * Exposes the three seams from the design (section 3 → "Test_Shoot
 * generator/simulator"):
 *   - createTestShoot : POST /admin/test-shoots                                    (AC 10.7)
 *   - previewEligible : GET  /admin/test-shoots/{shoot}/eligible-photographers     (AC 10.8)
 *   - assignTestShoot : POST /admin/test-shoots/{shoot}/assign                     (AC 10.9)
 *
 * The endpoints are thin orchestrators over {@see TestShootService}: the controller
 * validates input and loads collaborators, the service runs the production matching /
 * date logic so a Test_Shoot exercises the same code paths as a real shoot.
 *
 * Mounted under `auth:sanctum` + `role:admin,superadmin,editing_manager` to match the
 * other admin endpoints (see routes/api.php).
 */
class TestShootController extends Controller
{
    public function __construct(
        private readonly TestShootService $testShoots,
        private readonly AuditLogService $auditLog,
    ) {
    }

    /**
     * Create a Test_Shoot scoped to a region/state/area at a specific instant in the
     * provided IANA timezone (AC 10.7).
     *
     * Request body:
     *   - kind:         one of ServiceArea::KINDS (region|state|area)
     *   - value:        the area's value (e.g. "MD", "Northeast")
     *   - scheduled_at: ISO-8601 datetime — the absolute instant the Test_Shoot is scheduled for
     *   - timezone:     IANA timezone (e.g. "America/New_York") — the region's local timezone
     */
    public function createTestShoot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind'         => ['required', 'string', Rule::in(ServiceArea::KINDS)],
            'value'        => ['required', 'string', 'max:255'],
            'scheduled_at' => ['required', 'date'],
            'timezone'     => ['required', 'string', 'timezone'],
        ]);

        // Parse the scheduled instant as an absolute moment. Carbon accepts ISO-8601 and
        // common datetime forms; we normalize to UTC so the absolute instant is unambiguous
        // before TestShootService converts to the region timezone for the local calendar day.
        try {
            $when = CarbonImmutable::parse($validated['scheduled_at'])->setTimezone('UTC');
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['The scheduled_at field must be a valid datetime.'],
            ]);
        }

        $shoot = $this->testShoots->create(
            ['kind' => $validated['kind'], 'value' => $validated['value']],
            $when,
            $validated['timezone'],
        );

        $this->auditLog->record('test_shoot.created', $request->user(), $shoot, [
            'service_area_kind'  => $shoot->service_area_kind,
            'service_area_value' => $shoot->service_area_value,
            'timezone'           => $shoot->timezone,
            'scheduled_at'       => optional($shoot->scheduled_at)->toIso8601String(),
            'scheduled_date'     => $shoot->scheduled_date instanceof \DateTimeInterface
                ? $shoot->scheduled_date->format('Y-m-d')
                : (string) $shoot->scheduled_date,
        ]);

        return response()->json([
            'shoot' => $this->serializeTestShoot($shoot),
        ], 201);
    }

    /**
     * Return the photographers eligible for the Test_Shoot — those whose service-area
     * assignments match the Test_Shoot's (kind, value) scope (AC 10.8).
     *
     * Delegates to {@see TestShootService::eligiblePhotographers} so preview shares the
     * exact match path as the production assignment tool's `ServiceAreaMatcher`.
     */
    public function previewEligible(Shoot $shoot): JsonResponse
    {
        $this->ensureTestShoot($shoot);

        $photographers = User::query()
            ->where(function (Builder $query) {
                $query->where('role', 'photographer')
                    ->orWhereJsonContains('secondary_roles', 'photographer');
            })
            ->with('serviceAreas')
            ->get();

        $eligible = $this->testShoots->eligiblePhotographers($shoot, $photographers);

        return response()->json([
            'shoot_id'       => $shoot->id,
            'service_area'   => [
                'kind'  => $shoot->service_area_kind,
                'value' => $shoot->service_area_value,
            ],
            'photographers'  => $this->serializePhotographers($eligible),
        ]);
    }

    /**
     * Assign a Photographer to the Test_Shoot and persist the link (AC 10.9).
     *
     * Validates `user_id` against `users.id` and confirms the target is a photographer
     * (`role = photographer` or has `photographer` in `secondary_roles`) so the simulator
     * cannot accidentally link a non-photographer account.
     */
    public function assignTestShoot(Request $request, Shoot $shoot): JsonResponse
    {
        $this->ensureTestShoot($shoot);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $photographer = User::findOrFail($data['user_id']);

        if (! $this->isPhotographer($photographer)) {
            throw ValidationException::withMessages([
                'user_id' => ['The selected user is not a photographer.'],
            ]);
        }

        $this->testShoots->assign($shoot, $photographer);

        $shoot->refresh();

        $this->auditLog->record('test_shoot.assigned', $request->user(), $shoot, [
            'photographer_id' => $photographer->id,
        ]);

        return response()->json([
            'assigned' => true,
            'shoot'    => $this->serializeTestShoot($shoot),
        ]);
    }

    /**
     * Guard endpoints to only operate on Test_Shoots — non-test shoots have no
     * service_area_kind/value scope and would silently misbehave with the simulator.
     */
    private function ensureTestShoot(Shoot $shoot): void
    {
        if ($shoot->shoot_type !== Shoot::SHOOT_TYPE_INTERNAL_TEST) {
            abort(404, 'Test_Shoot not found.');
        }
    }

    private function isPhotographer(User $user): bool
    {
        if ($user->role === 'photographer') {
            return true;
        }

        $secondary = $user->secondary_roles;
        if (is_string($secondary)) {
            $decoded = json_decode($secondary, true);
            $secondary = is_array($decoded) ? $decoded : [];
        }

        return is_array($secondary) && in_array('photographer', $secondary, true);
    }

    /**
     * Compact API shape for a Test_Shoot — surfaces enough scope/timezone/schedule fields
     * for the Dashboard simulator panel without leaking unrelated shoot internals.
     *
     * @return array<string, mixed>
     */
    private function serializeTestShoot(Shoot $shoot): array
    {
        $scheduledDate = $shoot->scheduled_date instanceof \DateTimeInterface
            ? $shoot->scheduled_date->format('Y-m-d')
            : (string) $shoot->scheduled_date;

        return [
            'id'                 => $shoot->id,
            'shoot_type'         => $shoot->shoot_type,
            'status'             => $shoot->status,
            'service_area_kind'  => $shoot->service_area_kind,
            'service_area_value' => $shoot->service_area_value,
            'timezone'           => $shoot->timezone,
            'scheduled_at'       => optional($shoot->scheduled_at)->toIso8601String(),
            'scheduled_date'     => $scheduledDate,
            'photographer_id'    => $shoot->photographer_id,
        ];
    }

    /**
     * @param  Collection<int, User>  $photographers
     * @return array<int, array<string, mixed>>
     */
    private function serializePhotographers(Collection $photographers): array
    {
        return $photographers->map(fn (User $photographer) => [
            'id'    => $photographer->id,
            'name'  => $photographer->name,
            'email' => $photographer->email,
            'service_areas' => $photographer->serviceAreas->map(fn (ServiceArea $area) => [
                'id'    => $area->id,
                'kind'  => $area->kind,
                'value' => $area->value,
                'label' => $area->label,
            ])->values()->all(),
        ])->values()->all();
    }
}
