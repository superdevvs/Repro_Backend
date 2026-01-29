<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\ShootRescheduleRequest;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
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

        // Auto-approve all reschedule requests - update shoot immediately
        $record = ShootRescheduleRequest::create([
            'shoot_id' => $shoot->id,
            'requested_by' => $user?->id,
            'original_date' => $shoot->scheduled_date,
            'requested_date' => $validated['requested_date'],
            'requested_time' => $validated['requested_time'] ?? $shoot->time,
            'reason' => $validated['reason'] ?? null,
            'status' => 'approved',
            'reviewed_at' => now(),
            'approved_by' => $user?->id,
        ]);

        // Always apply schedule changes immediately
        $this->applyScheduleChanges($shoot, $record);

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
        
        // Also update scheduled_at to keep it in sync
        $timeStr = $request->requested_time ?? $shoot->time ?? '10:00';
        // Parse time (e.g., "10:15 AM" or "10:15")
        $timeParsed = date_parse($timeStr);
        $hours = $timeParsed['hour'] ?? 10;
        $minutes = $timeParsed['minute'] ?? 0;
        
        $scheduledAt = \Carbon\Carbon::parse($request->requested_date)
            ->setTime($hours, $minutes, 0);
        $shoot->scheduled_at = $scheduledAt;
        
        // Don't change status - keep original status (e.g., 'requested' stays 'requested')
        $shoot->save();

        $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
        $automationService = app(AutomationService::class);
        $context = $automationService->buildShootContext($shoot);
        if ($shoot->rep) {
            $context['rep'] = $shoot->rep;
        }
        $context['scheduled_at'] = $shoot->scheduled_at?->toISOString();
        $automationService->handleEvent('SHOOT_SCHEDULED', $context);
        $automationService->handleEvent('SHOOT_UPDATED', $context);

        if ($shoot->client) {
            app(MailService::class)->sendShootUpdatedEmail($shoot->client, $shoot);
        }
        
        // Log activity for the reschedule
        $this->logRescheduleActivity($shoot, $request);
    }
    
    private function logRescheduleActivity(Shoot $shoot, ShootRescheduleRequest $request): void
    {
        try {
            $requester = $request->requester;
            $requesterName = $requester ? $requester->name : 'System';
            
            $originalDate = $request->original_date 
                ? \Carbon\Carbon::parse($request->original_date)->format('M j, Y')
                : 'Unknown';
            $newDate = \Carbon\Carbon::parse($request->requested_date)->format('M j, Y');
            $newTime = $request->requested_time ?? 'same time';
            
            // Create activity log entry
            \App\Models\ShootActivityLog::create([
                'shoot_id' => $shoot->id,
                'user_id' => $request->requested_by,
                'action' => 'rescheduled',
                'description' => "{$requesterName} rescheduled shoot from {$originalDate} to {$newDate} at {$newTime}",
                'metadata' => [
                    'original_date' => $request->original_date,
                    'new_date' => $request->requested_date,
                    'new_time' => $request->requested_time,
                    'reason' => $request->reason,
                ],
            ]);
        } catch (\Throwable $e) {
            // Don't fail the reschedule if activity logging fails
            \Illuminate\Support\Facades\Log::warning('Failed to log reschedule activity: ' . $e->getMessage());
        }
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





