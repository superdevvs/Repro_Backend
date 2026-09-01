<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarSyncDispatcher;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootMediaMutationSupportService;
use Illuminate\Validation\ValidationException;
use Throwable;
use Illuminate\Support\Facades\Log;

class DeleteShootAction
{
    public function __construct(
        protected AutomationService $automationService,
        protected MailService $mailService,
        protected ShootActivityLogger $activityLogger,
        protected GoogleCalendarSyncDispatcher $googleCalendarSyncDispatcher,
        protected ShootMediaMutationSupportService $shootMediaMutationSupportService
    ) {
    }

    public function execute(Shoot $shoot, User $user, array $options = []): array
    {
        $hasReshootDescendants = $shoot->reshootChildren()->exists()
            || $shoot->rootReshootDescendants()->exists();
        $hasCompAuditRecords = $shoot->compReshootItems()->exists()
            || $shoot->compensations()->exists();

        if ($shoot->isComplimentaryReshoot() || $hasReshootDescendants || $hasCompAuditRecords) {
            throw ValidationException::withMessages([
                'shoot' => [
                    'Shoots in a complimentary-reshoot lineage cannot be permanently deleted. Cancel the shoot to preserve its service, responsibility, and payout audit trail.',
                ],
            ]);
        }

        $deleteMedia = (bool) ($options['delete_media'] ?? false);
        $shoot->loadMissing(['client', 'photographer', 'rep', 'service', 'files', 'mediaAlbums']);
        $context = [];
        $deletedMediaFiles = 0;

        try {
            $context = $this->automationService->buildShootContext($shoot);
            if ($shoot->rep) {
                $context['rep'] = $shoot->rep;
            }
        } catch (Throwable $e) {
            Log::warning('Failed to build shoot deletion automation context: ' . $e->getMessage(), [
                'shoot_id' => $shoot->id,
            ]);
        }

        $systemEmailAlreadySent = false;
        if ($shoot->client) {
            try {
                $this->mailService->sendShootRemovedEmail($shoot->client, $shoot);
                $systemEmailAlreadySent = true;
            } catch (Throwable $e) {
                Log::warning('Failed to send shoot deletion email: ' . $e->getMessage(), [
                    'shoot_id' => $shoot->id,
                    'client_id' => $shoot->client->id ?? null,
                ]);
            }
        }

        if ($context !== []) {
            try {
                $context['system_email_already_sent'] = $systemEmailAlreadySent;
                $this->automationService->handleEvent('SHOOT_REMOVED', $context);
            } catch (Throwable $e) {
                Log::warning('Failed to process shoot deletion automation: ' . $e->getMessage(), [
                    'shoot_id' => $shoot->id,
                ]);
            }
        }

        try {
            $this->activityLogger->log(
                $shoot,
                'shoot_deleted',
                [
                    'by' => $user->name,
                    'address' => $shoot->address,
                    'client' => $shoot->client?->name,
                    'photographer' => $shoot->photographer?->name,
                    'status' => $shoot->status,
                    'scheduled_date' => $shoot->scheduled_date?->toDateString(),
                ],
                $user
            );
        } catch (Throwable $e) {
            Log::warning('Failed to log shoot deletion activity: ' . $e->getMessage());
        }

        if ($deleteMedia) {
            $deletedMediaFiles = $this->shootMediaMutationSupportService->deleteShootMediaAssets($shoot);
        }

        $shootId = $shoot->id;
        $shoot->delete();
        $this->googleCalendarSyncDispatcher->dispatchShootRemoval($shootId);

        return [
            'shoot_id' => $shootId,
            'delete_media' => $deleteMedia,
            'deleted_media_files' => $deletedMediaFiles,
        ];
    }
}
