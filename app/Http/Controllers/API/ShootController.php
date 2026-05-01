<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShootRequest;
use App\Http\Requests\UpdateShootStatusRequest;
use App\Http\Resources\ShootResource;
use App\Models\Shoot;
use App\Services\PhotographerAvailabilityService;
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
            fn (Shoot $shoot, bool $isClientUser) => $this->shootPresenter->transformOperationalShoot($shoot, $isClientUser)
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
            'dropboxFolders', 'workflowLogs.user', 'verifiedBy',
        ])->findOrFail($id);

        if (!$this->shootAuthorizationSupport->canAccessShootMedia($shoot, auth()->user())) {
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
        } catch (\Exception $e) {
            \Log::error('Error creating shoot', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'validated' => $request->validated(),
                'user_id' => $user->id ?? null,
            ]);

            return response()->json([
                'message' => 'Failed to create shoot: ' . $e->getMessage(),
                'error' => config('app.debug') ? $e->getTraceAsString() : 'Internal server error',
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

        if ($user->role === 'photographer' && !$this->shootAuthorizationSupport->isPhotographerAssignedToShoot($shoot, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $shoot = $this->scheduleShootAction->execute($request, $shoot, $user);

            return response()->json([
                'message' => 'Shoot scheduled successfully',
                'data' => new ShootResource($shoot),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
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

        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'rep', 'representative'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (in_array($user->role, ['rep', 'representative'], true)) {
            $client = $shoot->client;
            if ($client && $client->rep_id !== $user->id) {
                return response()->json(['message' => 'You can only approve shoots for your assigned clients'], 403);
            }
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
            return response()->json(['message' => $e->getMessage()], 422);
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

        $shoot = $this->updateShootAction->execute($request, $shoot, $user);

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
        if (!$user || !in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'service_id' => 'required|integer',
            'photographer_id' => 'nullable|exists:users,id',
        ]);

        $shoot = $this->assignServicePhotographerAction->execute($shoot, $validated, $user);

        return response()->json([
            'message' => 'Service photographer assigned successfully',
            'data' => new ShootResource($shoot),
        ]);
    }

    public function assignServicePhotographers(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
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
            'service_photographers.*.service_id' => 'required|integer',
            'service_photographers.*.photographer_id' => 'nullable|exists:users,id',
            'assignments' => 'nullable|array',
            'assignments.*.service_id' => 'required|integer',
            'assignments.*.photographer_id' => 'nullable|exists:users,id',
            'services' => 'nullable|array',
            'services.*.service_id' => 'required|integer',
            'services.*.photographer_id' => 'nullable|exists:users,id',
        ]);

        $shoot = $this->assignServicePhotographerAction->execute($shoot, [
            'service_photographers' => $assignments,
        ], $user);

        return response()->json([
            'message' => 'Service photographers assigned successfully',
            'data' => new ShootResource($shoot),
        ]);
    }
}
