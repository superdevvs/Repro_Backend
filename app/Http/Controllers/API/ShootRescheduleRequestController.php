<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\ShootRescheduleRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShootRescheduleRequestController extends Controller
{
    public function index(Shoot $shoot)
    {
        $requests = $shoot->rescheduleRequests()->with(['requester', 'approver'])->latest()->get();

        return response()->json([
            'data' => $requests,
        ]);
    }

    public function store(Request $request, Shoot $shoot)
    {
        $validated = $request->validate([
            'requested_date' => 'required|date',
            'requested_time' => 'nullable|string|max:25',
            'reason' => 'nullable|string|max:2000',
        ]);

        $user = $request->user();

        $record = ShootRescheduleRequest::create([
            'shoot_id' => $shoot->id,
            'requested_by' => $user?->id,
            'original_date' => $shoot->scheduled_date,
            'requested_date' => $validated['requested_date'],
            'requested_time' => $validated['requested_time'] ?? $shoot->time,
            'reason' => $validated['reason'] ?? null,
            'status' => $this->userCanApprove($user) ? 'approved' : 'pending',
            'reviewed_at' => $this->userCanApprove($user) ? now() : null,
            'approved_by' => $this->userCanApprove($user) ? $user?->id : null,
        ]);

        if ($this->userCanApprove($user)) {
            $this->applyScheduleChanges($shoot, $record);
        }

        return response()->json([
            'message' => $record->status === 'approved'
                ? 'Shoot rescheduled successfully.'
                : 'Reschedule request submitted for review.',
            'data' => $record->fresh(['requester', 'approver']),
        ], $record->wasRecentlyCreated ? 201 : 200);
    }

    public function updateStatus(Request $request, ShootRescheduleRequest $rescheduleRequest)
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'status' => 'required|in:approved,rejected',
        ]);

        DB::beginTransaction();

        try {
            $rescheduleRequest->status = $validated['status'];
            $rescheduleRequest->reviewed_at = now();
            $rescheduleRequest->approved_by = $request->user()->id;
            $rescheduleRequest->save();

            if ($validated['status'] === 'approved') {
                $this->applyScheduleChanges($rescheduleRequest->shoot, $rescheduleRequest);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Unable to update reschedule request.',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Reschedule request updated.',
            'data' => $rescheduleRequest->fresh(['shoot', 'requester', 'approver']),
        ]);
    }

    private function applyScheduleChanges(Shoot $shoot, ShootRescheduleRequest $request): void
    {
        $shoot->scheduled_date = $request->requested_date;
        if (!empty($request->requested_time)) {
            $shoot->time = $request->requested_time;
        }
        $shoot->status = 'scheduled';
        $shoot->save();
    }

    private function userCanApprove(?\App\Models\User $user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower($user->role ?? '');
        return in_array($role, ['admin', 'superadmin'], true);
    }

    private function authorizeAdmin(Request $request): void
    {
        if (!$this->userCanApprove($request->user())) {
            abort(403, 'Only admins can update reschedule requests.');
        }
    }
}





