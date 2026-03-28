<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShootWorkflowTransitionSupportService
{
    public function __construct(
        protected MailService $mailService,
        protected AutomationService $automationService
    ) {
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

        $systemEmailAlreadySent = false;
        if ($shoot->client && $shoot->client->email) {
            try {
                $this->mailService->sendShootRemovedEmail($shoot->client, $shoot);
                $systemEmailAlreadySent = true;
            } catch (\Throwable $e) {
                Log::warning('Failed to send decline email: ' . $e->getMessage());
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

    public function resolveEditor(?int $editorId = null): User
    {
        if ($editorId) {
            $selectedEditor = User::find($editorId);
            if (!$selectedEditor || $selectedEditor->role !== 'editor') {
                throw new \InvalidArgumentException('Selected user is not an editor');
            }

            return $selectedEditor;
        }

        $editors = User::where('role', 'editor')->get(['id', 'name']);
        if ($editors->isEmpty()) {
            throw new \InvalidArgumentException('No editors available');
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
