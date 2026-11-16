<?php
namespace App\Services\IguideLab;

use App\Exceptions\PublicApiException;

/**
 * Public tour error factory (spec 10.4, D11). Only these texts reach the client: each is at most 200 characters
 * and passes the bridge's safeMessage rule /^[\w\s.,:;'"()!?%/+-]+$/ (unit-tested). abort() is never used for a
 * client-visible 409, 422 or 429, because ApiErrorResponder replaces abort() texts with per-status defaults.
 */
final class LabTourErrors
{
    /**
     * Body UUID rule (spec 10.2) for requestId, importId, caseId and runId, checked before lowercasing. It equals the
     * reader's z.string().uuid() (zod 4.6.7): version nibble 1-8 and variant 8/9/a/b, or the nil or max UUID.
     * Use it in array form ('regex:'.UUID_RULE): the pipe-separated rule string would split the alternation.
     */
    public const UUID_RULE = '/\A(?:[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|0{8}-0{4}-0{4}-0{4}-0{12}|f{8}-f{4}-f{4}-f{4}-f{12})\z/Di';

    /**
     * Contract key (TOUR_CONFLICT_MESSAGES, tourContract.ts) => [status, code, text]. requestReused is backend-only
     * until the owner approves adding it to the contract (spec 12.1, 15.2 item 1).
     */
    public const MESSAGES = [
        'staleSelection' => [409, 'tour_selection_stale', 'The selection changed since it was loaded. Reload and try again.'],
        'selectionMismatch' => [409, 'tour_selection_mismatch', 'The selection on screen does not match the saved selection. Reload and try again.'],
        'duplicateActiveRun' => [409, 'tour_run_duplicate_active', 'A run for this preset is already in progress for this tour.'],
        'runsPaused' => [409, 'tour_runs_paused', 'Runs are paused right now. Your selection is saved.'],
        'analysisNotReady' => [409, 'tour_analysis_not_ready', 'The tour is still being analysed.'],
        'nothingFits' => [409, 'tour_nothing_fits', 'No rooms fit within the current limits.'],
        'notHeld' => [409, 'tour_not_held', 'Only a room that was kept original can be accepted.'],
        'importNotReady' => [409, 'tour_import_not_ready', 'The import has not finished yet.'],
        'surveyCapReached' => [429, 'tour_survey_cap', 'The tour analysis limit for this lab has been reached. Try again later.'],
        'runLimitReached' => [409, 'tour_run_limit', 'This tour has reached its limit of runs.'],
        'presetNotRunnable' => [409, 'tour_preset_not_runnable', 'This preset cannot be run on this tour yet.'],
        'requestReused' => [409, 'tour_request_reused', 'This request was already used with different settings. Reload and try again.'],
    ];

    /**
     * Backend-only request errors by code => [status, text] (422, and 413 for the body limit). %d in
     * tour_too_many_captures is the configured LabTourConfig::maxCaptures() (1..80).
     */
    public const UNPROCESSABLE = [
        'tour_unsupported_field' => [422, 'This request has an unsupported field.'],
        'tour_invalid_type' => [422, 'The request has an invalid value.'],
        'tour_duplicate_change' => [422, 'Each room can appear only once, and an item cannot be both added and removed.'],
        'tour_too_many_captures' => [422, 'This tour has more than %d photos.'],
        'tour_capture_not_surveyed' => [422, 'A selected room has not been analysed yet.'],
        'tour_candidate_not_selectable' => [422, 'A selected item is not available for this preset.'],
        'tour_candidate_not_default' => [422, 'Only items that are selected by default can be removed.'],
        'tour_group_split' => [422, 'Items that touch must be selected together.'],
        'tour_body_too_large' => [413, 'The request is too large.'],
    ];

    /** A contract-keyed error (MESSAGES), for example public('staleSelection'). */
    public static function public(string $key): PublicApiException
    {
        if (! isset(self::MESSAGES[$key])) throw new \InvalidArgumentException('Unknown tour error key.');
        [$status, $code, $text] = self::MESSAGES[$key];

        return new PublicApiException($text, $code, $status);
    }

    /** A backend-only request error by code (UNPROCESSABLE), for example unprocessable('tour_invalid_type'). */
    public static function unprocessable(string $code): PublicApiException
    {
        if (! isset(self::UNPROCESSABLE[$code])) throw new \InvalidArgumentException('Unknown tour error code.');
        [$status, $text] = self::UNPROCESSABLE[$code];
        if ($code === 'tour_too_many_captures') $text = sprintf($text, LabTourConfig::maxCaptures());

        return new PublicApiException($text, $code, $status);
    }
}
