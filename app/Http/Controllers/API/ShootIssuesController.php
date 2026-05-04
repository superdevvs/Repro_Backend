<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Services\Shoots\ShootEditingAssignmentService;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootIssueParsingService;
use Illuminate\Http\Request;

class ShootIssuesController extends Controller
{
    public function __construct(
        protected ShootIssueParsingService $shootIssueParsingService,
        protected ShootEditingAssignmentService $shootEditingAssignmentService,
        protected ShootAuthorizationSupport $shootAuthorizationSupport,
    )
    {
    }

    public function markIssuesResolved(Request $request, $shootId)
    {
        $user = auth()->user();
        $shoot = Shoot::findOrFail($shootId);

        $isPhotographer = $this->shootAuthorizationSupport->isPhotographerAssignedToShoot($shoot, $user);
        $isEditor = $shoot->editor_id === $user->id || $user->role === 'editor';
        $isAdmin = in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true);

        if (!$isPhotographer && !$isEditor && !$isAdmin) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $hasIssues = in_array($shoot->workflow_status, [Shoot::STATUS_ON_HOLD], true) || $shoot->is_flagged;
        if (!$hasIssues) {
            return response()->json([
                'message' => 'Shoot is not on hold with issues',
                'current_status' => $shoot->workflow_status,
                'is_flagged' => $shoot->is_flagged,
            ], 400);
        }

        $shoot->issues_resolved_at = now();
        $shoot->issues_resolved_by = $user->id;
        $shoot->is_flagged = false;
        $shoot->workflow_status = Shoot::STATUS_UPLOADED;
        $shoot->status = Shoot::STATUS_UPLOADED;
        $shoot->save();

        $shoot->workflowLogs()->create([
            'user_id' => $user->id,
            'action' => 'issues_resolved',
            'details' => 'Photographer marked issues as resolved',
            'metadata' => [
                'resolved_by' => $user->id,
                'timestamp' => now()->toISOString(),
            ],
        ]);

        return response()->json([
            'message' => 'Issues marked as resolved. Shoot resubmitted for review.',
            'data' => $shoot->fresh(['client', 'photographer', 'service', 'files']),
        ]);
    }

    public function getIssues($shootId, Request $request)
    {
        $shoot = Shoot::findOrFail($shootId);

        return response()->json([
            'data' => $this->shootIssueParsingService->parseShootRequests($shoot, $request->user()),
        ]);
    }

    public function createIssue($shootId, Request $request)
    {
        $shoot = Shoot::findOrFail($shootId);
        $user = $request->user();

        $validated = $request->validate([
            'note' => 'required|string',
            'mediaId' => 'nullable|exists:shoot_files,id',
            'mediaIds' => 'nullable|array',
            'mediaIds.*' => 'exists:shoot_files,id',
            'assignedToRole' => 'nullable|in:editor,photographer',
            'assignedToUserId' => 'nullable|exists:users,id',
        ]);

        $assignedToRole = $validated['assignedToRole'] ?? null;
        if (strtolower((string) $user->role) === 'client') {
            $assignedToRole = null;
        }

        $mediaIds = [];
        if (!empty($validated['mediaIds'])) {
            $mediaIds = $validated['mediaIds'];
        } elseif (!empty($validated['mediaId'])) {
            $mediaIds = [$validated['mediaId']];
        }

        $requestId = $this->shootIssueParsingService->generateRequestId($shoot);
        $this->shootIssueParsingService->appendIssueRequest(
            $shoot,
            $this->shootIssueParsingService->buildRequestEntry(
                $user,
                $validated['note'],
                $mediaIds,
                $requestId,
                'open',
                $assignedToRole,
            )
        );

        $createdRequest = collect(
            $this->shootIssueParsingService->parseShootRequests($shoot->fresh(), $request->user())
        )->firstWhere('id', $requestId);

        return response()->json([
            'message' => 'Request created successfully',
            'data' => $createdRequest,
        ], 201);
    }

    public function updateIssue($shootId, $issueId, Request $request)
    {
        $shoot = Shoot::findOrFail($shootId);
        $validated = $request->validate([
            'status' => 'nullable|in:open,in-progress,resolved,dismissed',
        ]);

        $updatedRequest = $this->shootIssueParsingService->updateIssueStatus(
            $shoot,
            (string) $issueId,
            $validated['status'] ?? 'open',
        );

        if (!$updatedRequest) {
            return response()->json(['message' => 'Request not found'], 404);
        }

        return response()->json([
            'message' => 'Request updated successfully',
            'data' => $updatedRequest,
        ]);
    }

    public function assignIssue($shootId, $issueId, Request $request)
    {
        $shoot = Shoot::findOrFail($shootId);
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            return response()->json(['message' => 'Only admins can assign requests'], 403);
        }

        $validated = $request->validate([
            'assignedToRole' => 'required|in:editor,photographer',
            'assignedToUserId' => 'nullable|exists:users,id',
        ]);

        $updatedRequest = $this->shootIssueParsingService->assignIssueRole(
            $shoot,
            (string) $issueId,
            $validated['assignedToRole'],
        );

        if (!$updatedRequest) {
            return response()->json(['message' => 'Request not found'], 404);
        }

        return response()->json([
            'message' => 'Request assigned successfully',
            'assignedTo' => $validated['assignedToRole'],
            'data' => $updatedRequest,
        ]);
    }

    public function getClientRequests(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'editor'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $shootsQuery = Shoot::whereNotNull('admin_issue_notes')
            ->where('admin_issue_notes', 'like', '%[Request from%')
            ->with(['client:id,name']);

        if ($user->role === 'editor') {
            $this->shootEditingAssignmentService->scopeAssignedToEditor($shootsQuery, $user->id);
        }

        $shoots = $shootsQuery->get();

        return response()->json([
            'data' => $this->shootIssueParsingService->parseClientRequests($shoots),
        ]);
    }
}
