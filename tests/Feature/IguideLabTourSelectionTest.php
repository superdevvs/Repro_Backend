<?php

namespace Tests\Feature;

use App\Exceptions\PublicApiException;
use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourCapture;
use App\Models\IguideLabTourRun;
use App\Models\IguideLabTourRunTarget;
use App\Models\IguideLabTourSurvey;
use App\Services\IguideLab\LabTourDefaults;
use App\Services\IguideLab\LabTourSelections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\TourAnalysis\SeedsTourAnalyses;
use Tests\TestCase;

/** Selections (spec 7, 10.3 E5): per-capture delta writes, revision, sha, semantic 422s, reconciliation. */
class IguideLabTourSelectionTest extends TestCase
{
    use RefreshDatabase, SeedsTourAnalyses;

    private const ON = ['grassRunsEnabled' => false, 'declutterRunsEnabled' => true];
    private const FACTS = ['policy' => [], 'runnable' => true, 'schedulerEnabled' => false, 'tourBudgetEnabled' => false];

    private IguideLabProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
        $this->installTourDoubles();
        $this->project = $this->tourProject();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_write_replaces_per_capture_and_empty_entry_resets(): void
    {
        [$analysis, $cases] = $this->readyAnalysis(3);
        $this->declutterCapture($analysis, 0);
        $c0 = $cases[0];

        $response = $this->write($analysis, 'declutter', 0, [['caseId' => $c0, 'add' => ['d0h', 'd0g', 'd0b'], 'remove' => ['d0a']]]);
        $this->assertSame(['analysisId', 'preset', 'selection', 'counts', 'estimate'], array_keys($response));
        $this->assertSame([$analysis->id, 'declutter'], [$response['analysisId'], $response['preset']]);
        $this->assertSame(1, $response['selection']['revision']);
        $this->assertSame([['caseId' => $c0, 'add' => ['d0b', 'd0g', 'd0h'], 'remove' => ['d0a']]], $response['selection']['changes'], 'stored sorted');
        $this->assertSame(['rooms' => 1, 'items' => 6], $response['counts']); // d0d, d0e, d0f (defaults) + d0b, d0g, d0h

        // A second write replaces the capture's entry; it does not merge.
        $response = $this->write($analysis, 'declutter', 1, [['caseId' => $c0, 'add' => [], 'remove' => ['d0d']]]);
        $this->assertSame([['caseId' => $c0, 'add' => [], 'remove' => ['d0d']]], $response['selection']['changes']);
        $this->assertSame(['rooms' => 1, 'items' => 3], $response['counts']);
        $this->assertSame(['d0a', 'd0e', 'd0f'], app(LabTourSelections::class)->effective($this->tourView($analysis), 'declutter')[$c0]);

        // Empty add and remove reset the capture to its defaults; the stored map is an empty object again.
        $response = $this->write($analysis, 'declutter', 2, [['caseId' => $c0, 'add' => [], 'remove' => []]]);
        $this->assertSame([3, []], [$response['selection']['revision'], $response['selection']['changes']]);
        $this->assertSame(['rooms' => 1, 'items' => 4], $response['counts']);
        $raw = json_decode(DB::table('iguide_lab_tour_analyses')->where('id', $analysis->id)->value('selections'));
        $this->assertInstanceOf(\stdClass::class, $raw->declutter->changes);
        $this->assertSame(3, $raw->declutter->revision);
        $this->assertSame(0, $raw->{'golden-hour'}->revision, 'presets are independent');

        // Several captures in one body; untouched captures keep their entries.
        $this->write($analysis, 'golden-hour', 0, [['caseId' => $cases[1], 'add' => [], 'remove' => ['g1']]]);
        $response = $this->write($analysis, 'golden-hour', 1, [['caseId' => $cases[2], 'add' => [], 'remove' => ['g2']]]);
        $this->assertSame([$cases[1], $cases[2]], array_column($response['selection']['changes'], 'caseId'));
        $this->assertSame(['rooms' => 1, 'items' => 1], $response['counts']);
        $this->assertRecordParts($analysis);
    }

    public function test_revision_counts_accepted_writes(): void
    {
        [$analysis, $cases] = $this->readyAnalysis(2);
        $record = $analysis->fresh()->record_revision;
        $change = [['caseId' => $cases[0], 'add' => [], 'remove' => ['g0']]];
        foreach ([0, 1, 2] as $base) {
            $this->travel(1)->seconds();
            $this->assertSame($base + 1, $this->write($analysis, 'golden-hour', $base, $change)['selection']['revision'], 'no-op writes count too');
        }
        $this->assertSame($record + 3, $analysis->fresh()->record_revision);
        $this->assertSame(now()->toIso8601String(), $analysis->fresh()->updated_at->toIso8601String());
        $this->expectRejected(fn () => $this->write($analysis, 'golden-hour', 3, [['caseId' => $cases[0], 'add' => ['g9'], 'remove' => []]]), 422, 'tour_candidate_not_selectable');
        $this->assertSame([3, $record + 3], [$analysis->fresh()->selections['golden-hour']['revision'], $analysis->fresh()->record_revision]);
    }

    public function test_stale_revision_is_409_with_exact_text_and_code(): void
    {
        [$analysis, $cases] = $this->readyAnalysis(1);
        $change = [['caseId' => $cases[0], 'add' => [], 'remove' => ['g0']]];
        foreach ([1, 5] as $base) {
            $e = $this->expectRejected(fn () => $this->write($analysis, 'golden-hour', $base, $change), 409, 'tour_selection_stale');
            $this->assertSame('The selection changed since it was loaded. Reload and try again.', $e->getMessage());
        }
        $this->write($analysis, 'golden-hour', 0, $change);
        $this->expectRejected(fn () => $this->write($analysis, 'golden-hour', 0, $change), 409, 'tour_selection_stale');

        // A revision at the contract maximum cannot advance.
        $selections = $analysis->fresh()->selections;
        $selections['twilight']['revision'] = 2147483647;
        $selections['twilight']['changes'] = new \stdClass;
        $selections['golden-hour']['changes'] = (object) $selections['golden-hour']['changes'];
        foreach (['grass', 'declutter'] as $p) $selections[$p]['changes'] = new \stdClass;
        IguideLabTourAnalysis::whereKey($analysis->id)->toBase()->update(['selections' => json_encode($selections)]);
        $this->expectRejected(fn () => $this->write($analysis, 'twilight', 2147483647, [['caseId' => $cases[0], 'add' => [], 'remove' => ['t0']]]), 409, 'tour_selection_stale');
    }

    public function test_semantic_422s(): void
    {
        [$analysis, $cases] = $this->readyAnalysis(3);
        $this->declutterCapture($analysis, 0);
        // Capture 1 stays pending (not surveyed); capture 2 failed.
        IguideLabTourCapture::where('analysis_id', $analysis->id)->where('position', 1)->update(['status' => 'pending', 'survey' => null, 'applicability' => null, 'candidates' => null]);
        IguideLabTourCapture::where('analysis_id', $analysis->id)->where('position', 2)->update(['status' => 'failed', 'error_code' => 'survey_rejected',
            'survey' => null, 'applicability' => null, 'candidates' => null]);
        IguideLabTourAnalysis::whereKey($analysis->id)->toBase()->update(['status' => 'analysing', 'completed_at' => null]);
        $before = DB::table('iguide_lab_tour_analyses')->where('id', $analysis->id)->first();
        $c0 = $cases[0];
        $cases422 = [
            'unknown id' => [[['caseId' => $c0, 'add' => ['d0z'], 'remove' => []]], 'tour_candidate_not_selectable'],
            'non-executable add (glass)' => [[['caseId' => $c0, 'add' => ['d0c'], 'remove' => []]], 'tour_candidate_not_selectable'],
            'add of a default' => [[['caseId' => $c0, 'add' => ['d0a'], 'remove' => []]], 'tour_candidate_not_selectable'],
            'add of another preset' => [[['caseId' => $c0, 'add' => ['g0'], 'remove' => []]], 'tour_candidate_not_selectable'],
            'remove of a non-default' => [[['caseId' => $c0, 'add' => [], 'remove' => ['d0b']]], 'tour_candidate_not_default'],
            'remove of an unknown id' => [[['caseId' => $c0, 'add' => [], 'remove' => ['d0z']]], 'tour_candidate_not_default'],
            'split default group' => [[['caseId' => $c0, 'add' => [], 'remove' => ['d0e']]], 'tour_group_split'],
            'split non-default group' => [[['caseId' => $c0, 'add' => ['d0g'], 'remove' => []]], 'tour_group_split'],
            'pending capture' => [[['caseId' => $cases[1], 'add' => [], 'remove' => ['g1']]], 'tour_capture_not_surveyed'],
            'failed capture' => [[['caseId' => $cases[2], 'add' => [], 'remove' => []]], 'tour_capture_not_surveyed'],
            'capture of another analysis' => [[['caseId' => (string) Str::uuid(), 'add' => [], 'remove' => []]], 'tour_capture_not_surveyed'],
            'duplicate caseId' => [[['caseId' => $c0, 'add' => [], 'remove' => []], ['caseId' => strtoupper($c0), 'add' => [], 'remove' => []]], 'tour_duplicate_change'],
            'added and removed' => [[['caseId' => $c0, 'add' => ['d0b'], 'remove' => ['d0b']]], 'tour_duplicate_change'],
            'a later change fails the whole body' => [[['caseId' => $c0, 'add' => ['d0b'], 'remove' => []], ['caseId' => $cases[1], 'add' => [], 'remove' => []]],
                'tour_capture_not_surveyed'],
        ];
        $texts = ['tour_candidate_not_selectable' => 'A selected item is not available for this preset.',
            'tour_candidate_not_default' => 'Only items that are selected by default can be removed.', 'tour_group_split' => 'Items that touch must be selected together.',
            'tour_capture_not_surveyed' => 'A selected room has not been analysed yet.',
            'tour_duplicate_change' => 'Each room can appear only once, and an item cannot be both added and removed.'];
        foreach ($cases422 as $name => [$changes, $code]) {
            $e = $this->expectRejected(fn () => $this->write($analysis, 'declutter', 0, $changes), 422, $code, $name);
            $this->assertSame($texts[$code], $e->getMessage(), $name);
        }
        $this->assertEquals($before, DB::table('iguide_lab_tour_analyses')->where('id', $analysis->id)->first(), 'a rejected body writes nothing');

        // Whole groups are fine, and a write is allowed while the analysis is still analysing.
        $response = $this->write($analysis, 'declutter', 0, [['caseId' => $c0, 'add' => ['d0g', 'd0h'], 'remove' => ['d0e', 'd0f']]]);
        $this->assertSame([['caseId' => $c0, 'add' => ['d0g', 'd0h'], 'remove' => ['d0e', 'd0f']]], $response['selection']['changes']);
        $this->assertNull($response['estimate']);
    }

    public function test_sha_equals_canonical_after_writes_and_after_defaults_grow(): void
    {
        config(['iguide-lab-tour.worker_count' => 8]);
        $import = $this->tourImport($this->project, 3);
        $cases = $this->caseIds($import);
        $this->seedSurvey($this->project, $cases[0]);
        $this->seedSurvey($this->project, $cases[1]);
        [$analysis] = $this->analyses()->create($this->project, $this->input($import));
        $this->analyses()->tick($analysis->id);
        $this->assertSame('analysing', $analysis->fresh()->status);

        $response = $this->write($analysis, 'golden-hour', 0, [['caseId' => $cases[0], 'add' => [], 'remove' => ['g0']]]);
        $canonical = "tour-selection/1\npreset=golden-hour\n".$cases[1].'=g1';
        $this->assertSame(hash('sha256', $canonical), $response['selection']['sha256']);
        $this->assertSame($response['selection'], app(LabTourSelections::class)->stateDto($this->tourView($analysis), 'golden-hour'));

        // The third capture finishes: defaults grow, the sha changes, the revision does not.
        $this->completeDispatchedSurveys();
        $state = app(LabTourSelections::class)->stateDto($this->tourView($analysis), 'golden-hour');
        $rows = [$cases[1] => 'g1', $cases[2] => 'g2'];
        ksort($rows, SORT_STRING);
        $expected = "tour-selection/1\npreset=golden-hour";
        foreach ($rows as $caseId => $ids) $expected .= "\n".$caseId.'='.$ids;
        $this->assertSame([1, hash('sha256', $expected)], [$state['revision'], $state['sha256']]);
        $this->assertNotSame($response['selection']['sha256'], $state['sha256']);
        foreach (['twilight', 'grass', 'declutter'] as $preset) {
            $effective = app(LabTourSelections::class)->effective($this->tourView($analysis), $preset);
            $this->assertSame(LabTourSelections::sha256($preset, $effective), app(LabTourSelections::class)->stateDto($this->tourView($analysis), $preset)['sha256']);
        }
        $this->assertSame([], app(LabTourSelections::class)->effective($this->tourView($analysis), 'grass'));
    }

    public function test_uppercase_case_ids_are_normalised(): void
    {
        [$analysis, $cases] = $this->readyAnalysis(2);
        $response = $this->write($analysis, 'twilight', 0, [['caseId' => strtoupper($cases[1]), 'add' => [], 'remove' => ['t1']]]);
        $this->assertSame($cases[1], $response['selection']['changes'][0]['caseId']);
        $this->assertSame([$cases[1]], array_keys($analysis->fresh()->selections['twilight']['changes']));
        $this->assertSame([$cases[0] => ['t0']], app(LabTourSelections::class)->effective($this->tourView($analysis), 'twilight'));
        $response = $this->write($analysis, 'twilight', 1, [['caseId' => strtoupper($cases[1]), 'add' => [], 'remove' => []]]);
        $this->assertSame([], $response['selection']['changes']);
        $this->assertRecordParts($analysis);
    }

    public function test_worst_case_split_bodies_apply_in_sequence(): void
    {
        [$analysis, $cases] = $this->readyAnalysis(80);
        $surfaces = [];
        for ($s = 0; $s < 4; $s++) {
            $items = [];
            for ($i = 0; $i < 6; $i++) $items[] = ['Item '.$s.$i, $i % 2 ? 0.6 : 0.9, false, 0]; // half default, half executable non-default
            $surfaces[] = $this->surface('Surface '.$s, $items);
        }
        foreach (range(0, 79) as $position) $this->declutterCapture($analysis, $position, $this->kitchenResult($surfaces));

        // Desired: every default unticked and every other item ticked (80 x 24 changes), built like selectionChangesFor.
        $view = $this->tourView($analysis);
        $desired = $changes = [];
        foreach ($view->captures as $capture) {
            $own = array_values(array_filter($capture->candidates, fn ($c) => $c['preset'] === 'declutter'));
            $defaults = array_column(array_filter($own, fn ($c) => $c['executable'] && $c['defaultSelected']), 'id');
            $want = array_column(array_filter($own, fn ($c) => $c['executable'] && ! $c['defaultSelected']), 'id');
            $desired[$capture->case_id] = $want;
            $add = $want; sort($add, SORT_STRING);
            $remove = $defaults; sort($remove, SORT_STRING);
            $changes[] = ['caseId' => $capture->case_id, 'add' => $add, 'remove' => $remove];
            $this->assertSame(24, count($add) + count($remove));
        }
        $bodies = $this->split('declutter', $changes, 12 * 1024);
        $this->assertGreaterThanOrEqual(2, count($bodies));
        $revision = 0;
        foreach ($bodies as $body) {
            $json = json_encode(['preset' => 'declutter', 'baseRevision' => $revision, 'changes' => $body]);
            $this->assertLessThanOrEqual(12 * 1024, strlen($json));
            $response = $this->write($analysis, 'declutter', $revision, $body);
            $this->assertSame($revision + 1, $response['selection']['revision']);
            $revision = $response['selection']['revision'];
        }
        $this->assertSame(['rooms' => 80, 'items' => 80 * 12], $response['counts']);
        $this->assertSame(LabTourSelections::sha256('declutter', $desired), $response['selection']['sha256']);
        $this->assertCount(80, $response['selection']['changes']);
        $this->assertSame($cases, array_column($response['selection']['changes'], 'caseId'), 'changes in capture order');
    }

    public function test_estimate_null_while_analysing_and_present_when_ready(): void
    {
        [$analysis, $cases] = $this->readyAnalysis(2);
        $change = [['caseId' => $cases[0], 'add' => [], 'remove' => ['g0']]];
        $response = $this->write($analysis, 'golden-hour', 0, $change);
        $estimate = $response['estimate'];
        $this->assertSame(['selectionRevision', 'roomsSelected', 'itemsSelected', 'roomsThatFit', 'worstCaseImages', 'limitingReason', 'estimatedMinutes',
            'computedAt'], array_keys($estimate));
        $this->assertArrayNotHasKey('admittedCaseIds', $estimate);
        $this->assertSame([1, 1, 1], [$estimate['selectionRevision'], $estimate['roomsSelected'], $estimate['itemsSelected']]);
        $this->assertSame([$estimate['roomsSelected'], $estimate['itemsSelected']], [$response['counts']['rooms'], $response['counts']['items']]);
        $this->assertSame([['preset' => 'golden-hour', 'revision' => 1]], $this->capacity->calls, 'computed from the rows just written');

        IguideLabTourAnalysis::whereKey($analysis->id)->toBase()->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->assertNotNull($this->write($analysis, 'golden-hour', 1, $change)['estimate'], 'a cancelled analysis has an estimate');
        IguideLabTourAnalysis::whereKey($analysis->id)->toBase()->update(['status' => 'analysing', 'completed_at' => null, 'cancelled_at' => null]);
        IguideLabTourCapture::where('analysis_id', $analysis->id)->where('position', 1)->update(['status' => 'surveying', 'survey' => null, 'applicability' => null, 'candidates' => null]);
        $this->assertNull($this->write($analysis, 'golden-hour', 2, $change)['estimate']);
        $this->assertCount(2, $this->capacity->calls);
    }

    public function test_reparse_reconciles_stored_deltas(): void
    {
        [$analysis, $cases] = $this->readyAnalysis(3);
        $old = $this->kitchenResult([$this->surface('Counter', [['Keys', 0.9, false, 0], ['Mail', 0.6, false, 0], ['Phone', 0.9, false, 0], ['Remote', 0.9, false, 0]])]);
        $survey = $this->declutterCapture($analysis, 0, $old);
        $frozen = $this->declutterCapture($analysis, 2, $old);
        // A run target freezes capture 2: it is never rebuilt.
        $run = IguideLabTourRun::create(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'analysis_id' => $analysis->id,
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64), 'preset' => 'golden-hour', 'selection_revision' => 0,
            'selection_sha256' => str_repeat('d', 64), 'estimate' => [], 'chunk_size' => 8, 'status' => 'completed', 'completed_at' => now()]);
        IguideLabTourRunTarget::create(['id' => (string) Str::uuid(), 'run_id' => $run->id, 'project_id' => $this->project->id, 'case_id' => $cases[2],
            'position' => 2, 'candidate_ids' => ['g2'], 'state' => 'ready', 'reasons' => []]);
        $c0 = $cases[0];
        $this->write($analysis, 'declutter', 0, [['caseId' => $c0, 'add' => ['d0b'], 'remove' => ['d0a', 'd0c']]]);
        $this->write($analysis, 'golden-hour', 0, [['caseId' => $c0, 'add' => [], 'remove' => ['g0']]]);
        $this->write($analysis, 'twilight', 0, [['caseId' => $c0, 'add' => [], 'remove' => ['t0']]]);
        $this->write($analysis, 'declutter', 1, [['caseId' => $cases[2], 'add' => ['d2b'], 'remove' => []]]);
        $record = $analysis->fresh()->record_revision;

        // Re-parsed result: Mail is now glass (not executable), Keys is no longer default, Phone and Remote touch (a group),
        // and daylight is gone (golden hour weak_daylight: no longer default). Twilight stays executable and default.
        $new = $this->kitchenResult([$this->surface('Counter', [['Keys', 0.6, false, 0], ['Mail', 0.9, true, 0], ['Phone', 0.9, false, 1], ['Remote', 0.9, false, 1]])]);
        $new['light']['daylight'] = false;
        IguideLabTourSurvey::whereKey($survey->id)->toBase()->update(['result' => json_encode($new)]);
        $this->assertSame(1, $this->analyses()->surveyReparsed($survey->fresh(), true));

        $fresh = $analysis->fresh();
        $this->assertSame($record + 1, $fresh->record_revision, 'one bump for the rebuild');
        $this->assertSame([2, 1, 1], [$fresh->selections['declutter']['revision'], $fresh->selections['golden-hour']['revision'],
            $fresh->selections['twilight']['revision']], 'selection revisions are unchanged');
        $view = $this->tourView($analysis);
        $selections = app(LabTourSelections::class);
        // declutter: add d0b dropped (glass), remove d0a dropped (no longer default), remove d0c alone would split p0a: entry dropped.
        $this->assertSame([$cases[2]], array_column($selections->stateDto($view, 'declutter')['changes'], 'caseId'));
        $this->assertSame([], $selections->stateDto($view, 'golden-hour')['changes'], 'remove of a no longer default candidate is dropped');
        $this->assertSame([['caseId' => $c0, 'add' => [], 'remove' => ['t0']]], $selections->stateDto($view, 'twilight')['changes'], 'still valid: kept');
        $capture0 = $view->captures->firstWhere('case_id', $c0);
        $this->assertSame(['p0a', 'p0a'], [$capture0->candidates[4]['groupId'], $capture0->candidates[5]['groupId']]);
        $this->assertSame([false, true], [$capture0->candidates[0]['defaultSelected'], $capture0->candidates[1]['defaultSelected']]);
        $this->assertRecordParts($analysis);
        $this->assertSame(0, $this->analyses()->surveyReparsed($survey->fresh(), true), 'idempotent');

        // A capture referenced by a run target keeps its frozen candidates and its stored delta.
        $before = $view->captures->firstWhere('case_id', $cases[2])->candidates;
        IguideLabTourSurvey::whereKey($frozen->id)->toBase()->update(['result' => json_encode($new)]);
        $this->assertSame(0, $this->analyses()->surveyReparsed($frozen->fresh(), true));
        $this->assertSame($before, IguideLabTourCapture::where('analysis_id', $analysis->id)->where('position', 2)->firstOrFail()->candidates);
        $this->assertSame($record + 1, $analysis->fresh()->record_revision);
    }

    public function test_reparse_turns_parse_failures_surveyed_and_a_failed_analysis_ready(): void
    {
        $import = $this->tourImport($this->project, 2);
        [$analysis] = $this->analyses()->create($this->project, $this->input($import));
        $survey = $this->seedSurvey($this->project, $this->caseIds($import)[0], 'failed', 'survey_too_large', null, 1, ['received']);
        IguideLabTourCapture::where('analysis_id', $analysis->id)->update(['status' => 'failed', 'error_code' => 'survey_too_large', 'survey_id' => $survey->id]);
        IguideLabTourCapture::where('analysis_id', $analysis->id)->where('position', 1)->update(['error_code' => 'survey_rejected', 'survey_id' => null]);
        $completedAt = now()->subMinute();
        IguideLabTourAnalysis::whereKey($analysis->id)->toBase()->update(['status' => 'failed', 'completed_at' => $completedAt]);
        $record = $analysis->fresh()->record_revision;

        $this->travel(5)->seconds();
        IguideLabTourSurvey::whereKey($survey->id)->toBase()->update(['status' => 'completed', 'error_code' => null, 'result' => json_encode($this->kitchenResult())]);
        $this->assertSame(1, $this->analyses()->surveyReparsed($survey->fresh(), false));
        $fresh = $analysis->fresh();
        $this->assertSame(['ready', $completedAt->toIso8601String(), $record + 1], [$fresh->status, $fresh->completed_at->toIso8601String(), $fresh->record_revision]);
        $this->assertSame(['surveyed', 'failed'], array_values($this->captureStatuses($analysis)));
        $capture = IguideLabTourCapture::where('analysis_id', $analysis->id)->where('position', 0)->firstOrFail();
        $this->assertSame([null, ['g0', 't0']], [$capture->error_code, array_column($capture->candidates, 'id')]);
        $this->assertNotNull($capture->surveyed_at);
        $this->assertRecordParts($analysis);
        Http::assertNothingSent();

        // A cancelled analysis is skipped; a later resume reuses the completed survey.
        IguideLabTourAnalysis::whereKey($analysis->id)->toBase()->update(['status' => 'cancelled']);
        $this->assertSame(0, $this->analyses()->surveyReparsed($survey->fresh(), true));
    }

    // ------------------------------------------------------------------ helpers

    private function write(IguideLabTourAnalysis $analysis, string $preset, int $base, array $changes): array
    {
        return app(LabTourSelections::class)->write($analysis->fresh(), ['preset' => $preset, 'baseRevision' => $base, 'changes' => $changes], self::FACTS);
    }

    /** @return array{0: IguideLabTourAnalysis, 1: list<string>} a ready analysis whose captures were all cached */
    private function readyAnalysis(int $captures): array
    {
        $import = $this->tourImport($this->project, $captures);
        foreach ($this->caseIds($import) as $caseId) $this->seedSurvey($this->project, $caseId);
        [$analysis] = $this->analyses()->create($this->project, $this->input($import));
        $this->assertSame('ready', $analysis->fresh()->status);

        return [$analysis->fresh(), $this->caseIds($import)];
    }

    /**
     * Surveyed capture with declutter executable (a provisional flag-on build, spec 6.4), frozen in its defaults_flags.
     * Default clutter: Keys d0a (default), Mail d0b (executable), Glass d0c, Remote d0d (default),
     * Soap+Sponge p0a (d0e, d0f, default), Toy+Ball p0b (d0g, d0h, executable).
     */
    private function declutterCapture(IguideLabTourAnalysis $analysis, int $position, ?array $result = null, ?IguideLabTourSurvey $survey = null): IguideLabTourSurvey
    {
        $result ??= $this->kitchenResult([
            $this->surface('Counter', [['Keys', 0.9, false, 0], ['Mail', 0.6, false, 0], ['Glass', 0.9, true, 0], ['Remote', 0.9, false, 0]]),
            $this->surface('Sink', [['Soap', 0.9, false, 1], ['Sponge', 0.9, false, 1], ['Toy', 0.6, false, 2], ['Ball', 0.6, false, 2]]),
        ]);
        $capture = IguideLabTourCapture::where('analysis_id', $analysis->id)->where('position', $position)->firstOrFail();
        $survey ??= IguideLabTourSurvey::findOrFail($capture->survey_id);
        IguideLabTourSurvey::whereKey($survey->id)->toBase()->update(['result' => json_encode($result)]);
        $built = app(LabTourDefaults::class)->build($result, $position, self::ON);
        app(LabTourDefaults::class)->assertCapture($built);
        IguideLabTourCapture::whereKey($capture->id)->update(['status' => 'surveyed', 'survey_id' => $survey->id, 'survey' => json_encode($built['survey']),
            'applicability' => json_encode($built['applicability']), 'candidates' => json_encode($built['candidates']), 'defaults_flags' => json_encode(self::ON)]);

        return $survey;
    }

    /** Port of splitSelectionChanges (tourContract.ts:428-439), sized with the largest baseRevision. */
    private function split(string $preset, array $changes, int $maxBytes): array
    {
        $size = fn (array $chunk) => strlen(json_encode(['preset' => $preset, 'baseRevision' => 2147483647, 'changes' => $chunk]));
        $chunks = [];
        $current = [];
        foreach ($changes as $change) {
            $this->assertLessThanOrEqual($maxBytes, $size([$change]));
            if (count($current) >= 80 || $size([...$current, $change]) > $maxBytes) { $chunks[] = $current; $current = []; }
            $current[] = $change;
        }
        if ($current) $chunks[] = $current;

        return $chunks;
    }

    private function expectRejected(callable $call, int $status, string $code, string $name = ''): PublicApiException
    {
        try {
            $call();
        } catch (PublicApiException $e) {
            $this->assertSame([$status, $code], [$e->getStatusCode(), $e->errorCode], $name);

            return $e;
        }
        $this->fail('Expected '.$code.' '.$name);
    }
}
