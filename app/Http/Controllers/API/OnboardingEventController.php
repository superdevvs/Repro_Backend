<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\OnboardingEvent;
use App\Services\Onboarding\OnboardingTelemetryService;
use App\Services\Users\DashboardOnboardingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class OnboardingEventController extends Controller
{
    public function __construct(
        private readonly OnboardingTelemetryService $telemetry,
        private readonly DashboardOnboardingService $onboarding,
    ) {
    }

    /**
     * Record one or more onboarding telemetry events for the authenticated user.
     *
     * Accepts EITHER a single event payload OR a batch: { "events": [ ... ] }.
     * POST /api/onboarding/events
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Normalise to a list of events (single object OR { events: [...] }).
        $events = $request->input('events');
        if (!is_array($events)) {
            $events = [$request->all()];
        }

        if ($events === []) {
            return response()->json(['message' => 'The events payload is required.'], 422);
        }

        $onboardedRoles = ['client', 'photographer', 'salesRep', 'editing_manager', 'editor'];

        $validatedEvents = [];
        foreach ($events as $index => $event) {
            if (!is_array($event)) {
                return response()->json([
                    'message' => "Event at index {$index} must be an object.",
                ], 422);
            }

            $validator = Validator::make($event, [
                'event_type' => ['required', 'string', Rule::in(OnboardingTelemetryService::EVENT_TYPES)],
                'role' => ['required', 'string', Rule::in($onboardedRoles)],
                'onboarding_key' => ['required', 'string', 'max:100'],
                'version' => ['nullable', 'integer'],
                'step_index' => ['nullable', 'integer', 'min:0', 'max:100'],
                'step_target' => ['nullable', 'string', 'max:100'],
                'session_uuid' => ['required', 'string', 'uuid', 'max:64'],
                'source' => ['nullable', 'string', 'max:100'],
                'meta' => ['nullable', 'array'],
            ]);

            $validator->after(function ($validator) use ($event) {
                $role = $event['role'] ?? null;
                $key = $event['onboarding_key'] ?? null;
                if ($role !== null && $this->onboarding->isOnboardedRole($role)) {
                    $expected = $this->onboarding->keyForRole($role);
                    if ($key !== null && $key !== $expected) {
                        $validator->errors()->add(
                            'onboarding_key',
                            "onboarding_key must be [{$expected}] for role [{$role}]."
                        );
                    }
                }
            });

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Invalid onboarding event payload.',
                    'index' => $index,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validatedEvents[] = $validator->validated();
        }

        // Persist using the authenticated user's identity (role is the source of truth).
        $recorded = $this->telemetry->recordBatch($user, $validatedEvents);

        return response()->json(['recorded' => $recorded]);
    }

    /**
     * Admin funnel summary (query only). Returns per-role counts by event_type and
     * per-step drop-off (step_viewed grouped by step_index / step_target).
     *
     * Optional query params: role, from (date), to (date).
     * GET /api/onboarding/funnel
     */
    public function funnel(Request $request)
    {
        $validated = $request->validate([
            'role' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $applyFilters = function ($query) use ($validated) {
            if (!empty($validated['role'])) {
                $query->where('role', $validated['role']);
            }
            if (!empty($validated['from'])) {
                $query->where('created_at', '>=', Carbon::parse($validated['from'])->startOfDay());
            }
            if (!empty($validated['to'])) {
                $query->where('created_at', '<=', Carbon::parse($validated['to'])->endOfDay());
            }

            return $query;
        };

        // Per-role counts by event_type.
        $countsQuery = $applyFilters(
            OnboardingEvent::query()
                ->select('role', 'event_type', DB::raw('COUNT(*) as total'))
                ->groupBy('role', 'event_type')
        );

        $countsByRole = [];
        foreach ($countsQuery->get() as $row) {
            $countsByRole[$row->role][$row->event_type] = (int) $row->total;
        }

        // Per-step drop-off: step_viewed grouped by step_index / step_target.
        $stepsQuery = $applyFilters(
            OnboardingEvent::query()
                ->select('role', 'step_index', 'step_target', DB::raw('COUNT(*) as total'))
                ->where('event_type', 'step_viewed')
                ->groupBy('role', 'step_index', 'step_target')
                ->orderBy('role')
                ->orderBy('step_index')
        );

        $stepDropoff = [];
        foreach ($stepsQuery->get() as $row) {
            $stepDropoff[$row->role][] = [
                'step_index' => $row->step_index !== null ? (int) $row->step_index : null,
                'step_target' => $row->step_target,
                'views' => (int) $row->total,
            ];
        }

        return response()->json([
            'filters' => [
                'role' => $validated['role'] ?? null,
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
            ],
            'counts_by_role' => $countsByRole,
            'step_dropoff' => $stepDropoff,
        ]);
    }
}
