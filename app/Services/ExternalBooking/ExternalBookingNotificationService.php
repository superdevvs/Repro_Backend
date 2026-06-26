<?php

namespace App\Services\ExternalBooking;

use App\Models\Shoot;
use App\Models\ShootActivityLog;

/**
 * Decides whether a processed external booking needs human review and, if so, raises the
 * dashboard `shoot_assignment_review` notification (Requirements 2.19, 2.20).
 *
 * The dashboard has no dedicated notifications table: `DashboardController::notifications`
 * derives in-app notifications from `ShootActivityLog` rows via `getActivityLogsForRole`
 * (Admin/Super Admin/editing_manager/salesrep already see all shoot activity logs — the
 * existing scheduling-review role group). So the review "notification" is simply a
 * `ShootActivityLog` row whose `action = 'shoot_assignment_review'` and whose structured
 * payload lives in `metadata`.
 *
 * `needsReview` (2.19) — review is required when the shoot originated from the external site
 * AND the mapping is anything other than clean and unambiguous. Concretely:
 *
 *   needsReview = sourceIsExternal AND (
 *       warnings are non-empty
 *       OR mappingStatus IN {needs_review, partially_mapped}
 *       OR multiple photographers were requested for one service (case C flag)
 *       OR a photographer could not be mapped across services (case D/E flag)
 *       OR a schedule was auto-mapped across MULTIPLE services (a guess worth confirming)
 *   )
 *
 * The "a photographer/schedule is unassigned" and "schedules were auto-mapped" signals from
 * Requirement 2.19 are interpreted in the context of ambiguity: a single-service legacy
 * booking that simply has no photographer (nothing was requested) or that maps its only
 * preferred schedule onto its only service is NOT ambiguous and must NOT raise a review
 * notification (Requirements 3.7, 3.8). Those benign "unassigned"/"auto-mapped" situations
 * produce a `fully_mapped` status with no warnings/flags, so they correctly fall through to
 * `needsReview = false`. A *multi-service* schedule guess (preferred→service 1,
 * alternate→service 2) is genuinely worth confirming, so it is treated as a review trigger.
 *
 * Validates: Requirements 2.19, 2.20
 */
final class ExternalBookingNotificationService
{
    public const ACTION = 'shoot_assignment_review';
    public const TYPE = 'shoot_assignment_review';
    public const ACTION_TYPE = 'open_shoot_details_popup';
    public const FOCUS = 'schedule_assignments';
    public const TITLE = 'Booking Needs Review';

    /**
     * Create a `shoot_assignment_review` notification when the booking needs review.
     *
     * @param string[] $warnings the warnings persisted on the shoot
     * @return bool whether a notification was created
     */
    public function notifyIfNeeded(Shoot $shoot, MappingResult $result, array $warnings): bool
    {
        if (!$this->needsReview($shoot, $result, $warnings)) {
            return false;
        }

        $shootId = (int) $shoot->id;
        $message = $this->buildMessage($shootId, $warnings);

        ShootActivityLog::create([
            'shoot_id' => $shootId,
            'user_id' => null, // external bookings have no authenticated actor
            'action' => self::ACTION,
            'description' => $message,
            'metadata' => [
                'type' => self::TYPE,
                'shoot_id' => $shootId,
                'title' => self::TITLE,
                'message' => $message,
                'action_type' => self::ACTION_TYPE,
                'action_payload' => [
                    'shoot_id' => $shootId,
                    'focus' => self::FOCUS,
                ],
            ],
        ]);

        return true;
    }

    /**
     * Whether the processed booking needs human review (2.19).
     *
     * @param string[] $warnings
     */
    public function needsReview(Shoot $shoot, MappingResult $result, array $warnings): bool
    {
        if (!$this->sourceIsExternal($shoot)) {
            return false;
        }

        $flags = $result->flags;

        return !empty($warnings)
            || in_array($result->mappingStatus, [
                MappingResult::STATUS_NEEDS_REVIEW,
                MappingResult::STATUS_PARTIALLY_MAPPED,
            ], true)
            || !empty($flags['multiplePhotographersForOneService'])
            || !empty($flags['unmappablePhotographers'])
            || $this->hasMultiServiceScheduleGuess($result);
    }

    /**
     * Detect a shoot that originated from the external booking site. The controller stamps
     * `created_by`/`updated_by` as "External (<source>)" for these bookings, so the
     * "External" prefix is the reliable external-source signal.
     */
    private function sourceIsExternal(Shoot $shoot): bool
    {
        return str_starts_with((string) $shoot->created_by, 'External')
            || str_starts_with((string) $shoot->updated_by, 'External');
    }

    /**
     * True when a schedule was auto-mapped onto more than one service — the multi-service
     * preferred/alternate guess that a reviewer should confirm.
     */
    private function hasMultiServiceScheduleGuess(MappingResult $result): bool
    {
        if (count($result->serviceAssignments) <= 1) {
            return false;
        }

        $scheduledCount = 0;
        foreach ($result->serviceAssignments as $assignment) {
            if (($assignment['scheduled_at'] ?? null) !== null) {
                $scheduledCount++;
            }
        }

        return $scheduledCount > 0;
    }

    /**
     * Build the human-readable review message (also stored as the activity description).
     *
     * @param string[] $warnings
     */
    private function buildMessage(int $shootId, array $warnings): string
    {
        if (!empty($warnings)) {
            return "External booking for shoot #{$shootId} needs review: " . implode(' ', $warnings);
        }

        return "External booking for shoot #{$shootId} needs photographer/schedule assignment review.";
    }
}
