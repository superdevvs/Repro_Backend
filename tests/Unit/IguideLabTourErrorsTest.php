<?php

namespace Tests\Unit;

use App\Exceptions\PublicApiException;
use App\Services\ApiErrorResponder;
use App\Services\IguideLab\LabTourErrors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Public tour error texts (spec 10.4, D11) and the body UUID rule (spec 10.2). */
class IguideLabTourErrorsTest extends TestCase
{
    /** The bridge's safeMessage rule (README "Bridge facts" 4): /^[\w\s.,:;'"()!?%/+-]+$/ */
    private const BRIDGE_SAFE_MESSAGE = '#^[\w\s.,:;\'"()!?%/+-]+$#D';

    private const CONTRACT = [
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

    private const BACKEND = [
        'tour_unsupported_field' => [422, 'This request has an unsupported field.'],
        'tour_invalid_type' => [422, 'The request has an invalid value.'],
        'tour_duplicate_change' => [422, 'Each room can appear only once, and an item cannot be both added and removed.'],
        'tour_too_many_captures' => [422, 'This tour has more than 80 photos.'],
        'tour_capture_not_surveyed' => [422, 'A selected room has not been analysed yet.'],
        'tour_candidate_not_selectable' => [422, 'A selected item is not available for this preset.'],
        'tour_candidate_not_default' => [422, 'Only items that are selected by default can be removed.'],
        'tour_group_split' => [422, 'Items that touch must be selected together.'],
        'tour_body_too_large' => [413, 'The request is too large.'],
    ];

    public function test_every_message_passes_the_bridge_filter_and_fits_200_characters(): void
    {
        $exceptions = array_merge(array_map(fn ($key) => LabTourErrors::public($key), array_keys(LabTourErrors::MESSAGES)),
            array_map(fn ($code) => LabTourErrors::unprocessable($code), array_keys(LabTourErrors::UNPROCESSABLE)));
        $this->assertCount(21, $exceptions);
        foreach ([1, 80] as $limit) {
            config(['iguide-lab-tour.caps.max_captures' => $limit]);
            $exceptions[] = LabTourErrors::unprocessable('tour_too_many_captures');
        }
        foreach ($exceptions as $e) {
            $this->assertInstanceOf(PublicApiException::class, $e);
            $this->assertMatchesRegularExpression(self::BRIDGE_SAFE_MESSAGE, $e->getMessage());
            $this->assertLessThanOrEqual(200, strlen($e->getMessage()));
            $this->assertMatchesRegularExpression('/\A[a-z0-9_]{1,64}\z/D', $e->errorCode);
        }
    }

    public function test_contract_keys_codes_statuses_and_texts_are_exact(): void
    {
        $this->assertSame(array_keys(self::CONTRACT), array_keys(LabTourErrors::MESSAGES));
        foreach (self::CONTRACT as $key => [$status, $code, $text]) {
            $this->assertSame([$status, $code, $text], LabTourErrors::MESSAGES[$key]);
            $e = LabTourErrors::public($key);
            $this->assertSame([$status, $code, $text], [$e->getStatusCode(), $e->errorCode, $e->getMessage()]);
        }
        $this->assertSame(array_keys(self::BACKEND), array_keys(LabTourErrors::UNPROCESSABLE));
        foreach (self::BACKEND as $code => [$status, $text]) {
            $e = LabTourErrors::unprocessable($code);
            $this->assertSame([$status, $code, $text], [$e->getStatusCode(), $e->errorCode, $e->getMessage()]);
        }
    }

    public function test_too_many_captures_text_uses_the_configured_limit(): void
    {
        config(['iguide-lab-tour.caps.max_captures' => 50]);
        $this->assertSame('This tour has more than 50 photos.', LabTourErrors::unprocessable('tour_too_many_captures')->getMessage());
        config(['iguide-lab-tour.caps.max_captures' => 500]);
        $this->assertSame('This tour has more than 80 photos.', LabTourErrors::unprocessable('tour_too_many_captures')->getMessage());
    }

    public function test_unknown_keys_are_programming_errors(): void
    {
        foreach ([fn () => LabTourErrors::public('tour_selection_stale'), fn () => LabTourErrors::unprocessable('staleSelection'),
            fn () => LabTourErrors::unprocessable('tour_unknown')] as $call) {
            try {
                $call();
                $this->fail('Expected an unknown error key to be rejected.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Unknown tour error', $e->getMessage());
            }
        }
    }

    public function test_errors_render_as_message_and_code_only(): void
    {
        $response = app(ApiErrorResponder::class)->render(LabTourErrors::public('runsPaused'), Request::create('/api/iguide-lab/tour-analyses/x/runs', 'POST'));
        $this->assertSame(409, $response->getStatusCode());
        $body = $response->getData(true);
        $this->assertSame(['message', 'code', 'request_id'], array_keys($body));
        $this->assertSame(['Runs are paused right now. Your selection is saved.', 'tour_runs_paused'], [$body['message'], $body['code']]);
        $response = app(ApiErrorResponder::class)->render(LabTourErrors::unprocessable('tour_body_too_large'), Request::create('/api/iguide-lab/tour-analyses', 'POST'));
        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame('The request is too large.', $response->getData(true)['message']);
    }

    public function test_uuid_rule_equals_the_reader_uuid(): void
    {
        $valid = [(string) Str::uuid(), (string) Str::uuid7(), (string) Str::orderedUuid(), '00000000-0000-0000-0000-000000000000',
            'ffffffff-ffff-ffff-ffff-ffffffffffff', 'FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF', '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
            '886313e1-3b8a-5372-9b90-0c9aee199e5d', 'A0EEBC99-9C0B-4EF8-BB6D-6BB9BD380A11', '01890a5d-ac96-774b-bcce-b302099a8057',
            '12345678-1234-8234-a234-123456789abc'];
        $invalid = ['12345678-1234-0234-8234-123456789abc', '12345678-1234-9234-8234-123456789abc', '12345678-1234-4234-c234-123456789abc',
            '12345678-1234-4234-7234-123456789abc', '12345678123442348234123456789abc', '{12345678-1234-4234-8234-123456789abc}',
            "12345678-1234-4234-8234-123456789abc\n", ' 12345678-1234-4234-8234-123456789abc', '12345678-1234-4234-8234-123456789abg',
            '00000000-0000-0000-0000-000000000001', 'ffffffff-ffff-ffff-ffff-fffffffffffe', ''];
        foreach ($valid as $uuid) $this->assertSame(1, preg_match(LabTourErrors::UUID_RULE, $uuid), $uuid);
        foreach ($invalid as $uuid) $this->assertSame(0, preg_match(LabTourErrors::UUID_RULE, $uuid), json_encode($uuid));
        // Laravel's bare uuid rule is looser; the tour rule is used as an array-form regex rule.
        $this->assertTrue(Validator::make(['id' => '12345678-1234-0234-8234-123456789abc'], ['id' => 'uuid'])->passes());
        $rule = ['required', 'string', 'regex:'.LabTourErrors::UUID_RULE];
        $this->assertTrue(Validator::make(['id' => $valid[0]], ['id' => $rule])->passes());
        $this->assertTrue(Validator::make(['id' => '12345678-1234-0234-8234-123456789abc'], ['id' => $rule])->fails());
    }
}
