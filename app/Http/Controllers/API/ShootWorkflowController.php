<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShootResource;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\Shoots\Actions\ApproveCancellationAction;
use App\Services\Shoots\Actions\ApproveHoldAction;
use App\Services\Shoots\Actions\AssignEditorAction;
use App\Services\Shoots\Actions\CancelShootAction;
use App\Services\Shoots\Actions\DeclineShootAction;
use App\Services\Shoots\Actions\MarkCompletedAction;
use App\Services\Shoots\Actions\PutShootOnHoldAction;
use App\Services\Shoots\Actions\RejectCancellationAction;
use App\Services\Shoots\Actions\RejectHoldAction;
use App\Services\Shoots\Actions\RequestCancellationAction;
use App\Services\Shoots\Actions\RequestHoldAction;
use App\Services\Shoots\Actions\StartEditingAction;
use App\Services\Shoots\Actions\SubmitForReviewAction;
use App\Services\Shoots\Actions\WithdrawRequestedShootAction;
use Illuminate\Http\Request;

class ShootWorkflowController extends Controller
{
    public function __construct(
        protected PutShootOnHoldAction $putShootOnHoldAction,
        protected CancelShootAction $cancelShootAction,
        protected RequestCancellationAction $requestCancellationAction,
        protected ApproveCancellationAction $approveCancellationAction,
        protected RejectCancellationAction $rejectCancellationAction,
        protected StartEditingAction $startEditingAction,
        protected SubmitForReviewAction $submitForReviewAction,
        protected MarkCompletedAction $markCompletedAction,
        protected DeclineShootAction $declineShootAction,
        protected RequestHoldAction $requestHoldAction,
        protected ApproveHoldAction $approveHoldAction,
        protected RejectHoldAction $rejectHoldAction,
        protected AssignEditorAction $assignEditorAction,
        protected WithdrawRequestedShootAction $withdrawRequestedShootAction
    ) {
    }

    public function putOnHold(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'salesRep', 'rep', 'representative'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $this->putShootOnHoldAction->execute($request, $shoot, $user);

            return response()->json([
                'message' => 'Shoot has been placed on hold.',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services'])),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'superadmin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $this->cancelShootAction->execute($request, $shoot, $user);

            return response()->json([
                'message' => 'Shoot has been cancelled.',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services'])),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function requestCancellation(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        $isPrivilegedUser = in_array($user->role, ['admin', 'superadmin', 'salesRep', 'rep', 'representative'], true);
        $isClientOwner = $user->role === 'client' && (string) $shoot->client_id === (string) $user->id;
        if (!$isPrivilegedUser && !$isClientOwner) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $this->requestCancellationAction->execute($request, $shoot, $user);

            return response()->json([
                'message' => 'Cancellation request submitted. Pending approval.',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services'])),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function withdrawRequested(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if ($user->role !== 'client' || (string) $shoot->client_id !== (string) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $this->withdrawRequestedShootAction->execute($request, $shoot, $user);

            return response()->json([
                'message' => 'Shoot request cancelled.',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services'])),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function approveCancellation(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'salesRep', 'rep', 'representative'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $this->approveCancellationAction->execute($request, $shoot, $user);

            return response()->json([
                'message' => 'Cancellation request approved. Shoot has been cancelled.',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services'])),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function rejectCancellation(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'salesRep', 'rep', 'representative'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $this->rejectCancellationAction->execute($request, $shoot, $user);

            return response()->json([
                'message' => 'Cancellation request rejected.',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services'])),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function pendingCancellations(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'salesRep', 'rep', 'representative'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $shoots = Shoot::whereNotNull('cancellation_requested_at')
            ->with(['client', 'photographer', 'services'])
            ->orderBy('cancellation_requested_at', 'desc')
            ->get();

        return response()->json([
            'data' => ShootResource::collection($shoots),
        ]);
    }

    public function startEditing(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $this->startEditingAction->execute($request, $shoot, $user);

            return response()->json([
                'message' => 'Shoot moved to editing.',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services', 'editor'])),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function readyForReview(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'editor'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $this->submitForReviewAction->execute($request, $shoot, $user);

            return response()->json([
                'message' => 'Shoot marked as ready for review.',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services'])),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function complete(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $this->markCompletedAction->execute($request, $shoot, $user);

            return response()->json([
                'message' => 'Shoot has been completed and delivered.',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services'])),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function decline(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'salesRep', 'rep', 'representative'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $this->declineShootAction->execute($request, $shoot, $user);

            return response()->json([
                'message' => 'Shoot request has been declined.',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services'])),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function requestHold(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if ($user->role !== 'client' || (string) $shoot->client_id !== (string) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $this->requestHoldAction->execute($request, $shoot, $user);

            return response()->json([
                'message' => 'Hold request submitted. Pending approval.',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services'])),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function approveHold(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'salesRep', 'rep', 'representative'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $this->approveHoldAction->execute($request, $shoot, $user);

            return response()->json([
                'message' => 'Hold request approved. Shoot has been placed on hold.',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services'])),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function rejectHold(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'salesRep', 'rep', 'representative'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $this->rejectHoldAction->execute($request, $shoot, $user);

            return response()->json([
                'message' => 'Hold request rejected.',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services'])),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function assignEditor(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $shoot = $this->assignEditorAction->execute($request, $shoot, $user);

            return response()->json([
                'message' => 'Editor assigned successfully',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services', 'editor'])),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function getWorkflowStatus($shootId)
    {
        $shoot = Shoot::with(['files', 'workflowLogs.user'])->findOrFail($shootId);

        return response()->json([
            'shoot_id' => $shoot->id,
            'workflow_status' => $shoot->workflow_status,
            'file_stats' => [
                'total' => $shoot->files->count(),
                'todo' => $shoot->files->where('workflow_stage', ShootFile::STAGE_TODO)->count(),
                'completed' => $shoot->files->where('workflow_stage', ShootFile::STAGE_COMPLETED)->count(),
                'verified' => $shoot->files->where('workflow_stage', ShootFile::STAGE_VERIFIED)->count(),
                'flagged' => $shoot->files->where('workflow_stage', ShootFile::STAGE_FLAGGED)->count(),
            ],
            'workflow_logs' => $shoot->workflowLogs->take(10),
            'can_upload_photos' => $shoot->canUploadPhotos(),
            'can_move_to_completed' => $shoot->canMoveToCompleted(),
            'can_verify' => $shoot->canVerify(),
        ]);
    }
}
