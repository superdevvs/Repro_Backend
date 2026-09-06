<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AccountLink;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootEditingAssignmentService;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootIssueParsingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

        if (! $this->shootAuthorizationSupport->canResolveShootIssues($shoot, $user)) {
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
            'data' => app(\App\Services\Shoots\ShootPresenter::class)->transformShoot($shoot->fresh()),
        ]);
    }

    public function getIssues($shootId, Request $request)
    {
        $shoot = Shoot::findOrFail($shootId);
        $this->shootAuthorizationSupport->ensureShootAccess($shoot, $request->user());

        return response()->json([
            'data' => $this->shootIssueParsingService->parseShootRequests($shoot, $request->user()),
        ]);
    }

    public function createIssue($shootId, Request $request)
    {
        $shoot = Shoot::findOrFail($shootId);
        $user = $request->user();
        abort_unless($this->shootAuthorizationSupport->canSubmitShootRequest($shoot, $user), 403, 'Forbidden');

        $validated = $request->validate([
            'note' => 'required|string',
            'mediaId' => ['nullable', Rule::exists('shoot_files', 'id')->where('shoot_id', $shoot->id)],
            'mediaIds' => 'nullable|array',
            'mediaIds.*' => [Rule::exists('shoot_files', 'id')->where('shoot_id', $shoot->id)],
            'assignedToRole' => 'nullable|in:editor,photographer',
            'assignedToUserId' => 'nullable|exists:users,id',
        ]);

        $assignedToRole = $validated['assignedToRole'] ?? null;
        if (! $this->shootAuthorizationSupport->canManageShootOperations($user)) {
            $assignedToRole = null;
            $validated['assignedToUserId'] = null;
        }
        $this->ensureValidAssignee($shoot, $assignedToRole, $validated['assignedToUserId'] ?? null);

        $mediaIds = [];
        if (!empty($validated['mediaIds'])) {
            $mediaIds = $validated['mediaIds'];
        } elseif (!empty($validated['mediaId'])) {
            $mediaIds = [$validated['mediaId']];
        }
        foreach ($shoot->files()->whereIn('id', $mediaIds)->get() as $file) {
            abort_unless($this->shootAuthorizationSupport->canInteractWithShootMediaFile($shoot, $file, $user), 403, 'Forbidden');
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
                isset($validated['assignedToUserId']) ? (int) $validated['assignedToUserId'] : null,
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
        abort_unless($this->shootAuthorizationSupport->canResolveShootIssues($shoot, $request->user()), 403, 'Forbidden');
        $visibleIssue = collect($this->shootIssueParsingService->parseShootRequests($shoot, $request->user()))
            ->firstWhere('id', (string) $issueId);
        abort_unless($visibleIssue, 404, 'Request not found');
        if (! $this->shootAuthorizationSupport->canManageShootOperations($request->user())) {
            abort_if(! empty($visibleIssue['assignedToRole'])
                && ! $this->shootAuthorizationSupport->hasRole($request->user(), [$visibleIssue['assignedToRole']]), 403, 'Forbidden');
        }
        $validated = $request->validate([
            'status' => 'nullable|in:open,in-progress,resolved,dismissed',
        ]);

        $updatedRequest = $this->shootIssueParsingService->updateIssueStatus(
            $shoot,
            (string) $issueId,
            $validated['status'] ?? 'open',
            $request->user(),
        );

        if (!$updatedRequest) {
            return response()->json(['message' => 'Request not found'], 404);
        }

        return response()->json([
            'message' => 'Request updated successfully',
            'data' => collect($this->shootIssueParsingService->parseShootRequests($shoot->fresh(), $request->user()))
                ->firstWhere('id', (string) $issueId),
        ]);
    }

    public function assignIssue($shootId, $issueId, Request $request)
    {
        $shoot = Shoot::findOrFail($shootId);
        $user = $request->user();
        $this->shootAuthorizationSupport->ensureShootAccess($shoot, $user);

        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            return response()->json(['message' => 'Only admins can assign requests'], 403);
        }

        $validated = $request->validate([
            'assignedToRole' => 'required|in:editor,photographer',
            'assignedToUserId' => 'nullable|exists:users,id',
        ]);
        $this->ensureValidAssignee($shoot, $validated['assignedToRole'], $validated['assignedToUserId'] ?? null);

        $updatedRequest = $this->shootIssueParsingService->assignIssueRole(
            $shoot,
            (string) $issueId,
            $validated['assignedToRole'],
            isset($validated['assignedToUserId']) ? (int) $validated['assignedToUserId'] : null,
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
        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'editor', 'photographer', 'client'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $shootsQuery = Shoot::whereNotNull('admin_issue_notes')
            ->where('admin_issue_notes', 'like', '%[Request from%')
            ->with(['client:id,name']);

        if ($user->role === 'editor') {
            $this->shootEditingAssignmentService->scopeAssignedToEditor($shootsQuery, $user->id);
        } elseif ($user->role === 'photographer') {
            $shootsQuery->where(function ($query) use ($user) {
                $query->where('photographer_id', $user->id)
                    ->orWhereHas('services', function ($serviceQuery) use ($user) {
                        $serviceQuery->where('shoot_service.photographer_id', $user->id);
                    });
            });
        } elseif ($user->role === 'client') {
            $linkedClientIds = AccountLink::query()
                ->where('main_account_id', $user->id)
                ->where('status', 'active')
                ->get()
                ->filter(fn ($link) => $link->sharesDetail('shoots'))
                ->pluck('linked_account_id')
                ->all();

            $shootsQuery->whereIn('client_id', array_values(array_unique([
                $user->id,
                ...$linkedClientIds,
            ])));
        }

        $shoots = $this->shootAuthorizationSupport->scopeAccessibleShootMedia($shootsQuery, $user)->get();

        return response()->json([
            'data' => $this->shootIssueParsingService->parseClientRequests($shoots, $user),
        ]);
    }

    private function ensureValidAssignee(Shoot $shoot, ?string $role, mixed $userId): void
    {
        if (! $userId) {
            return;
        }

        $assignee = User::find($userId);
        abort_unless($role && $this->shootAuthorizationSupport->hasRole($assignee, [$role])
            && $this->shootAuthorizationSupport->canResolveShootIssues($shoot, $assignee),
            422, 'Assignee must have this role and an assignment on this shoot.');
    }
}
