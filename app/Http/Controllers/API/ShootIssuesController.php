<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\Shoots\ShootIssueParsingService;
use Illuminate\Http\Request;

class ShootIssuesController extends Controller
{
    public function __construct(protected ShootIssueParsingService $shootIssueParsingService)
    {
    }

    public function markIssuesResolved(Request $request, $shootId)
    {
        $user = auth()->user();
        $shoot = Shoot::findOrFail($shootId);

        $isPhotographer = $shoot->photographer_id === $user->id;
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

        $mediaIds = [];
        if (!empty($validated['mediaIds'])) {
            $mediaIds = $validated['mediaIds'];
        } elseif (!empty($validated['mediaId'])) {
            $mediaIds = [$validated['mediaId']];
        }

        $this->shootIssueParsingService->appendIssueRequest(
            $shoot,
            $this->shootIssueParsingService->buildRequestEntry($user, $validated['note'], $mediaIds)
        );

        $mediaFiles = [];
        if (!empty($mediaIds)) {
            $files = ShootFile::whereIn('id', $mediaIds)->get();
            foreach ($files as $file) {
                $mediaFiles[] = [
                    'id' => (string) $file->id,
                    'filename' => $file->filename ?? $file->stored_filename ?? 'unknown',
                ];
            }
        }

        return response()->json([
            'message' => 'Request created successfully',
            'data' => [
                'id' => 'temp_' . time(),
                'shootId' => $shoot->id,
                'note' => $validated['note'],
                'mediaId' => !empty($mediaIds) ? (string) $mediaIds[0] : null,
                'mediaIds' => array_map('strval', $mediaIds),
                'mediaFiles' => $mediaFiles,
                'raisedBy' => [
                    'id' => (string) $user->id,
                    'name' => $user->name,
                    'role' => $user->role,
                ],
                'status' => 'open',
                'createdAt' => now()->toISOString(),
                'updatedAt' => now()->toISOString(),
            ],
        ], 201);
    }

    public function updateIssue($shootId, $issueId, Request $request)
    {
        Shoot::findOrFail($shootId);
        $request->validate([
            'status' => 'nullable|in:open,in-progress,resolved',
        ]);

        return response()->json([
            'message' => 'Request updated successfully',
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

        $this->shootIssueParsingService->assignIssueRole($shoot, $validated['assignedToRole']);

        return response()->json([
            'message' => 'Request assigned successfully',
            'assignedTo' => $validated['assignedToRole'],
        ]);
    }

    public function getClientRequests(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $shoots = Shoot::whereNotNull('admin_issue_notes')
            ->where('admin_issue_notes', 'like', '%[Request from%')
            ->with(['client:id,name'])
            ->get();

        return response()->json([
            'data' => $this->shootIssueParsingService->parseClientRequests($shoots),
        ]);
    }
}
