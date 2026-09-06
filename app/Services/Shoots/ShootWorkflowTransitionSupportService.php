<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ShootWorkflowTransitionSupportService
{
    public function __construct(
        protected MailService $mailService,
        protected AutomationService $automationService
    ) {
    }

    public function sendCancellationRequestSideEffects(Shoot $shoot, User $user): void
    {
        $shoot->loadMissing(['client', 'photographer', 'rep', 'services']);
        $this->notifyCancellationStakeholders(
            $shoot,
            'cancellation_request',
            'Cancellation request needs review',
            sprintf('%s requested cancellation for shoot #%d (%s).', $user->name, $shoot->id, $shoot->address ?? 'No address'),
            [
                'requested_by' => $user->id,
                'reason' => $shoot->cancellation_reason,
            ]
        );

        try {
            $this->automationService->handleEvent('SHOOT_CANCELLATION_REQUESTED', [
                'shoot' => $shoot->fresh(['client', 'photographer', 'rep', 'services']),
                'user' => $user,
                'reason' => $shoot->cancellation_reason,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to trigger SHOOT_CANCELLATION_REQUESTED automation: ' . $e->getMessage());
        }

        if ($shoot->client && $shoot->client->email) {
            try {
                $this->mailService->sendShootCancellationRequestedEmail($shoot->client, $shoot);
            } catch (\Throwable $e) {
                Log::warning('Failed to send cancellation request email to client', [
                    'shoot_id' => $shoot->id,
                    'client_id' => $shoot->client->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (
            $shoot->photographer
            && $shoot->photographer->email
            && (!$shoot->client || (int) $shoot->photographer->id !== (int) $shoot->client->id)
        ) {
            try {
                $this->mailService->sendShootCancellationRequestedEmail($shoot->photographer, $shoot);
            } catch (\Throwable $e) {
                Log::warning('Failed to send cancellation request email to photographer', [
                    'shoot_id' => $shoot->id,
                    'photographer_id' => $shoot->photographer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function sendCancellationSideEffects(Shoot $shoot, User $user): void
    {
        $shoot->loadMissing(['client', 'photographer', 'services']);

        $systemEmailAlreadySent = false;
        if ($shoot->client && $shoot->client->email) {
            try {
                $this->mailService->sendShootCancelledEmail($shoot->client, $shoot);
                $systemEmailAlreadySent = true;
            } catch (\Throwable $e) {
                Log::warning('Failed to send cancellation email: ' . $e->getMessage());
            }
        }

        if (
            $shoot->photographer
            && $shoot->photographer->email
            && (!$shoot->client || (int) $shoot->photographer->id !== (int) $shoot->client->id)
        ) {
            try {
                $this->mailService->sendShootCancelledEmail($shoot->photographer, $shoot);
                $systemEmailAlreadySent = true;
            } catch (\Throwable $e) {
                Log::warning('Failed to send cancellation email to photographer', [
                    'shoot_id' => $shoot->id,
                    'photographer_id' => $shoot->photographer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $this->automationService->handleEvent('SHOOT_CANCELED', [
                'shoot' => $shoot->fresh(['client', 'photographer', 'services']),
                'user' => $user,
                'system_email_already_sent' => $systemEmailAlreadySent,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to trigger SHOOT_CANCELED automation: ' . $e->getMessage());
        }
    }

    public function sendCancellationApprovalSideEffects(Shoot $shoot, User $user, bool $feeCharged, float $cancellationFee): void
    {
        $shoot->loadMissing(['client', 'photographer', 'rep', 'services']);
        $this->notifyUser(
            $shoot->client,
            $feeCharged ? 'cancellation_approved_fee' : 'cancellation_approved_waived',
            'Cancellation approved',
            $feeCharged
                ? sprintf('Your cancellation was approved and a $%.2f cancellation fee was applied.', $cancellationFee)
                : 'Your cancellation was approved and no cancellation fee was applied.',
            [
                'shoot_id' => $shoot->id,
                'approved_by' => $user->id,
                'cancellation_fee' => round($cancellationFee, 2),
            ]
        );

        try {
            $this->automationService->handleEvent('SHOOT_CANCELLATION_APPROVED', [
                'shoot' => $shoot->fresh(['client', 'photographer', 'rep', 'services']),
                'user' => $user,
                'fee_charged' => $feeCharged,
                'cancellation_fee' => round($cancellationFee, 2),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to trigger SHOOT_CANCELLATION_APPROVED automation: ' . $e->getMessage());
        }
    }

    public function sendCancellationRejectionSideEffects(Shoot $shoot, User $user, ?string $reason = null): void
    {
        $shoot->loadMissing(['client', 'photographer', 'rep', 'services']);
        $this->notifyUser(
            $shoot->client,
            'cancellation_rejected',
            'Cancellation request rejected',
            'Your cancellation request was rejected. The shoot remains scheduled.',
            [
                'shoot_id' => $shoot->id,
                'rejected_by' => $user->id,
                'reason' => $reason,
            ]
        );

        try {
            $this->automationService->handleEvent('SHOOT_CANCELLATION_REJECTED', [
                'shoot' => $shoot->fresh(['client', 'photographer', 'rep', 'services']),
                'user' => $user,
                'reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to trigger SHOOT_CANCELLATION_REJECTED automation: ' . $e->getMessage());
        }
    }

    public function sendCompletionSideEffects(Shoot $shoot, User $user): void
    {
        $systemEmailAlreadySent = false;
        $shoot->loadMissing(['client', 'photographer', 'rep', 'services']);

        if ($shoot->client && $shoot->client->email) {
            try {
                $this->mailService->sendShootReadyEmail($shoot->client, $shoot);
                $systemEmailAlreadySent = true;
            } catch (\Throwable $e) {
                Log::warning('Failed to send completion email', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $context = $this->automationService->buildShootContext($shoot);
            if ($shoot->rep) {
                $context['rep'] = $shoot->rep;
            }
            $context['system_email_already_sent'] = $systemEmailAlreadySent;
            $this->automationService->handleEvent('SHOOT_COMPLETED', $context);
        } catch (\Throwable $e) {
            Log::warning('Failed to trigger completion automation', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sendDeclineSideEffects(Shoot $shoot, User $user): void
    {
        $shoot->loadMissing(['client', 'photographer', 'services']);

        try {
            $freshShoot = $shoot->fresh(['client', 'photographer', 'services']) ?? $shoot;
            $context = $this->automationService->buildShootContext($freshShoot);
            $context['user'] = $user;
            $context['decline_reason'] = $shoot->declined_reason;

            $declineDispatch = $this->automationService->handleEvent('SHOOT_REQUEST_DECLINED', $context);
            if (
                $shoot->client
                && $shoot->client->email
                && $this->automationService->shouldUseFallback('SHOOT_REQUEST_DECLINED', $declineDispatch) !== false
            ) {
                $this->mailService->sendShootRequestDeclinedEmail($shoot->client, $shoot);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to trigger SHOOT_REQUEST_DECLINED automation: ' . $e->getMessage());
        }
    }

    protected function notifyCancellationStakeholders(Shoot $shoot, string $type, string $title, string $message, array $data = []): void
    {
        $recipients = User::query()
            ->whereIn('role', ['admin', 'superadmin'])
            ->get()
            ->push($shoot->rep)
            ->filter()
            ->unique('id');

        foreach ($recipients as $recipient) {
            $this->notifyUser($recipient, $type, $title, $message, array_merge([
                'shoot_id' => $shoot->id,
                'client_id' => $shoot->client_id,
            ], $data));
        }
    }

    protected function notifyUser(?User $user, string $type, string $title, string $message, array $data = []): void
    {
        if (!$user) {
            return;
        }

        try {
            if (!class_exists('App\\Models\\Notification') || !Schema::hasTable('notifications')) {
                return;
            }

            \App\Models\Notification::create([
                'user_id' => $user->id,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => $data,
                'read' => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to create cancellation workflow notification', [
                'user_id' => $user->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function resolveEditor(?int $editorId = null, ?string $lane = null): User
    {
        if ($editorId) {
            $selectedEditor = User::find($editorId);
            if (!$selectedEditor || $selectedEditor->role !== 'editor') {
                throw new \App\Exceptions\PublicBusinessRuleException('Selected user is not an editor');
            }

            if ($lane && !$selectedEditor->canEditLane($lane)) {
                throw new \App\Exceptions\PublicBusinessRuleException("Selected editor cannot handle {$lane} editing.");
            }

            return $selectedEditor;
        }

        $editors = User::query()
            ->where('role', 'editor')
            ->orderBy('id')
            ->get(['id', 'name', 'metadata']);

        if ($lane) {
            $editors = $editors
                ->filter(fn (User $editor) => $editor->canEditLane($lane))
                ->values();
        }

        if ($editors->isEmpty()) {
            throw new \App\Exceptions\PublicBusinessRuleException($lane ? "No {$lane} editors available" : 'No editors available');
        }

        $editorIds = $editors->pluck('id');
        $loadMap = Shoot::whereIn('editor_id', $editorIds)
            ->whereIn('workflow_status', [Shoot::STATUS_UPLOADED, Shoot::STATUS_EDITING])
            ->select('editor_id', DB::raw('count(*) as total'))
            ->groupBy('editor_id')
            ->get()
            ->pluck('total', 'editor_id');

        return $editors->sortBy(fn (User $editor) => $loadMap[$editor->id] ?? 0)->first();
    }
}
