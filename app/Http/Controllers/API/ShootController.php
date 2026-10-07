<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShootRequest;
use App\Http\Requests\UpdateShootStatusRequest;
use App\Http\Resources\ShootResource;
use App\Models\Shoot;
use App\Services\PhotographerAvailabilityService;
use App\Services\Shoots\Actions\ApplyAlternateDateAction;
use App\Services\Shoots\Actions\ApproveShootAction;
use App\Services\Shoots\Actions\AssignServicePhotographerAction;
use App\Services\Shoots\Actions\CreateShootAction;
use App\Services\Shoots\Actions\DeleteShootAction;
use App\Services\Shoots\Actions\ScheduleShootAction;
use App\Services\Shoots\Actions\UpdateShootAction;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootHistoryService;
use App\Services\Shoots\ShootListingService;
use App\Services\Shoots\ShootPresenter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShootController extends Controller
{
    public function __construct(
        protected PhotographerAvailabilityService $availabilityService,
        protected ShootListingService $shootListingService,
        protected ShootHistoryService $shootHistoryService,
        protected ShootPresenter $shootPresenter,
        protected ShootAuthorizationSupport $shootAuthorizationSupport,
        protected AssignServicePhotographerAction $assignServicePhotographerAction,
        protected ApplyAlternateDateAction $applyAlternateDateAction,
        protected CreateShootAction $createShootAction,
        protected ScheduleShootAction $scheduleShootAction,
        protected ApproveShootAction $approveShootAction,
        protected UpdateShootAction $updateShootAction,
        protected DeleteShootAction $deleteShootAction
    ) {
    }

    public function index(Request $request)
    {
        return $this->shootListingService->index(
            $request,
            auth()->user(),
            fn (Shoot $shoot, bool $isClientUser, bool $includeFiles = true) => $this->shootPresenter->transformOperationalShoot($shoot, $isClientUser, $includeFiles)
        );
    }

    public function history(Request $request)
    {
        return $this->shootHistoryService->history($request, auth()->user());
    }

    public function exportHistory(Request $request): StreamedResponse
    {
        return $this->shootHistoryService->exportHistory($request, auth()->user());
    }

    public function show($id)
    {
        $shoot = Shoot::with([
            'client', 'photographer', 'service', 'services.category', 'files', 'payments',
            'dropboxFolders', 'workflowLogs.user', 'verifiedBy', 'reshootOf', 'reshootChildren',
            'rootReshootDescendants', 'rootShoot.rootReshootDescendants',
        ])->findOrFail($id);

        if (!$this->shootAuthorizationSupport->canViewShootDetails($shoot, auth()->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(['data' => $this->shootPresenter->transformShoot($shoot)]);
    }

    public function store(StoreShootRequest $request)
    {
        $user = $request->user();

        try {
            $result = $this->createShootAction->execute($request, $user);
            $shoot = $result->shoot;
            $treatAsClientRequest = $result->treatAsClientRequest;

            $message = $treatAsClientRequest
                ? 'Shoot request submitted successfully. It will be reviewed by our team.'
                : 'Shoot created successfully';

            return response()->json([
                'message' => $message,
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services', 'notes'])),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'message' => 'Failed to create shoot: ' . \App\Services\ApiErrorResponder::publicMessage($e),
                'error' => config('app.debug') ? null : 'Internal server error',
            ], 500);
        }
    }

    public function getPhotographerAvailability(Request $request, int $id)
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $from = \Carbon\Carbon::parse($validated['from']);
        $to = \Carbon\Carbon::parse($validated['to']);

        if ($from->diffInDays($to) > 90) {
            return response()->json([
                'message' => 'Date range cannot exceed 90 days',
            ], 422);
        }

        $availability = $this->availabilityService->getAvailabilitySummary($id, $from, $to);

        return response()->json([
            'data' => $availability,
            'photographer_id' => $id,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    public function schedule(UpdateShootStatusRequest $request, Shoot $shoot)
    {
        $user = $request->user();

        if (! $this->shootAuthorizationSupport->canScheduleShoot($shoot, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $shoot = $this->scheduleShootAction->execute($request, $shoot, $user);

            return response()->json([
                'message' => 'Shoot scheduled successfully',
                'data' => new ShootResource($shoot),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => \App\Services\ApiErrorResponder::publicMessage($e)], 422);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            if (isset($errors['scheduled_at'][0]) && $errors['scheduled_at'][0] === 'scheduled_at is required') {
                return response()->json(['message' => 'scheduled_at is required'], 422);
            }

            return response()->json(['message' => 'Validation failed', 'errors' => $errors], 422);
        }
    }

    public function approve(Request $request, Shoot $shoot)
    {
        $user = $request->user();

        if (! $this->shootAuthorizationSupport->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'salesRep'])
            || ! $this->shootAuthorizationSupport->canViewShootDetails($shoot, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($shoot->status !== Shoot::STATUS_REQUESTED && $shoot->workflow_status !== Shoot::STATUS_REQUESTED) {
            return response()->json(['message' => 'Only requested shoots can be approved'], 422);
        }

        try {
            $shoot = $this->approveShootAction->execute($request, $shoot, $user);

            return response()->json([
                'message' => 'Shoot approved successfully',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services'])),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => \App\Services\ApiErrorResponder::publicMessage($e)], 422);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }
    }

    public function update(Request $request, $shoot)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$shoot instanceof Shoot) {
            $shoot = Shoot::findOrFail($shoot);
        }

        try {
            $shoot = $this->updateShootAction->execute($request, $shoot, $user);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => \App\Services\ApiErrorResponder::publicMessage($e),
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'message' => 'Shoot updated',
            'data' => $this->shootPresenter->transformShoot($shoot),
        ]);
    }

    public function destroy(Request $request, $shootId)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $shoot = Shoot::findOrFail($shootId);
        $deleteMedia = $request->boolean('delete_media');
        $result = $this->deleteShootAction->execute($shoot, $user, [
            'delete_media' => $deleteMedia,
        ]);

        return response()->json([
            'message' => $deleteMedia
                ? 'Shoot and uploaded media deleted successfully'
                : 'Shoot deleted from the dashboard successfully',
            'data' => $result,
        ]);
    }

    public function assignServicePhotographer(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        $access = app(\App\Services\Shoots\ShootAuthorizationSupport::class);
        if (! $access->canManageShootOperations($user) && ! $access->canEditShootAppointment($shoot, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'service_id' => 'required_without:shoot_service_id|nullable|integer',
            'shoot_service_id' => 'nullable|integer',
            'expected_units_revision' => 'nullable|integer|min:0',
            'travel_location_confirmed' => 'nullable|boolean',
            'travel_override' => 'nullable|boolean',
            'travel_override_confirmed' => 'nullable|boolean',
            'travel_override_confirmation_version' => 'nullable|string|size:64',
            'travel_override_reason' => 'nullable|string|max:500',
            'photographer_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'photographer')),
            ],
            'override' => 'nullable|boolean',
            'override_reason' => 'nullable|string|max:500',
        ]);

        $shoot = $this->assignServicePhotographerAction->execute($shoot, $validated, $user);

        return response()->json([
            'message' => 'Service photographer assigned successfully',
            'data' => new ShootResource($shoot),
        ]);
    }

    public function applyAlternateDate(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        $management = app(\App\Services\Shoots\ShootManagementAccess::class);
        if (!$management->can($user, 'manage') || !$management->canEdit($shoot, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'scope' => 'nullable|in:main,all_services',
            'expected_units_revision' => 'nullable|integer|min:0',
            'travel_location_confirmed' => 'nullable|boolean',
            'travel_override' => 'nullable|boolean',
            'travel_override_confirmed' => 'nullable|boolean',
            'travel_override_confirmation_version' => 'nullable|string|size:64',
            'travel_override_reason' => 'nullable|string|max:500',
        ]);
        $scope = $validated['scope'] ?? 'main';

        try {
            $shoot = $this->applyAlternateDateAction->execute($shoot, $scope, $user, $validated['expected_units_revision'] ?? null, $validated);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => \App\Services\ApiErrorResponder::publicMessage($e),
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'message' => 'Alternate date applied successfully',
            'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services'])),
        ]);
    }

    public function assignServicePhotographers(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        $access = app(\App\Services\Shoots\ShootAuthorizationSupport::class);
        if (! $access->canManageShootOperations($user) && ! $access->canEditShootAppointment($shoot, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $assignments = $request->input('service_photographers')
            ?? $request->input('assignments')
            ?? $request->input('services')
            ?? [];

        if (!is_array($assignments) || count($assignments) === 0) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => [
                    'service_photographers' => ['At least one service photographer assignment is required.'],
                ],
            ], 422);
        }

        $request->validate([
            'service_photographers' => 'nullable|array',
            'service_photographers.*.service_id' => $shoot->units()->exists() ? 'nullable|integer' : 'required|integer',
            'service_photographers.*.shoot_service_id' => 'nullable|integer',
            'service_photographers.*.photographer_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'photographer')),
            ],
            'assignments' => 'nullable|array',
            'assignments.*.service_id' => $shoot->units()->exists() ? 'nullable|integer' : 'required|integer',
            'assignments.*.shoot_service_id' => 'nullable|integer',
            'assignments.*.photographer_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'photographer')),
            ],
            'services' => 'nullable|array',
            'services.*.service_id' => $shoot->units()->exists() ? 'nullable|integer' : 'required|integer',
            'services.*.shoot_service_id' => 'nullable|integer',
            'services.*.photographer_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'photographer')),
            ],
            'expected_units_revision' => 'nullable|integer|min:0',
            'travel_location_confirmed' => 'nullable|boolean',
            'travel_override' => 'nullable|boolean',
            'travel_override_confirmed' => 'nullable|boolean',
            'travel_override_confirmation_version' => 'nullable|string|size:64',
            'travel_override_reason' => 'nullable|string|max:500',
            'override' => 'nullable|boolean',
            'override_reason' => 'nullable|string|max:500',
        ]);

        $shoot = $this->assignServicePhotographerAction->execute($shoot, [
            'service_photographers' => $assignments,
            'expected_units_revision' => $request->input('expected_units_revision'),
            'override' => $request->input('override'),
            'override_reason' => $request->input('override_reason'),
            'travel_location_confirmed' => $request->boolean('travel_location_confirmed'),
            'travel_override' => $request->boolean('travel_override'),
            'travel_override_confirmed' => $request->boolean('travel_override_confirmed'),
            'travel_override_confirmation_version' => $request->input('travel_override_confirmation_version'),
            'travel_override_reason' => $request->input('travel_override_reason'),
        ], $user);

        return response()->json([
            'message' => 'Service photographers assigned successfully',
            'data' => new ShootResource($shoot),
        ]);
    }
}
