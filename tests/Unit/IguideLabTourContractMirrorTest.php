<?php

namespace Tests\Unit;

use Tests\Concerns\AssertsTourContract;
use Tests\TestCase;

/**
 * The PHP mirror of the tour-analysis/1 readers (spec 13.1) must accept what tourContract.ts accepts and reject
 * what it rejects. The fixtures are copies of tour-contract/fixture-*.json (amended contract, spec 12.3); the
 * negatives repeat check-contract.ts part (b).
 */
class IguideLabTourContractMirrorTest extends TestCase
{
    use AssertsTourContract;

    private const DIR = 'tests/Fixtures/iguide-lab/tour-contract-mirror/';

    public function test_contract_fixtures_pass_the_mirror(): void
    {
        foreach (['fixture-grass.json', 'fixture-declutter.json', 'fixture-lighting.json'] as $file) {
            // The contract fixtures illustrate Phase 6 outlines, which D1 never emits, so they are read in contract mode.
            $this->assertTourContract('analysis', $this->fixture($file), d1: false, message: $file);
        }
        $this->assertTourContract('progress', $this->fixture('fixture-progress.json'));
        $endpoints = $this->fixture('fixture-endpoints.json');
        $id = $this->fixture('fixture-lighting.json')->id;
        $samples = [
            ['capabilitiesResponse', 'GET', 'tour-analyses', 'response'], ['createRequest', 'POST', 'tour-analyses', 'request'],
            ['createResponse', 'POST', 'tour-analyses', 'response'], ['progressAnalysing', 'GET', "tour-analyses/$id/progress", 'response'],
            ['selectionRequest', 'POST', "tour-analyses/$id/selection", 'request'], ['selectionResetRequest', 'POST', "tour-analyses/$id/selection", 'request'],
            ['selectionResponse', 'POST', "tour-analyses/$id/selection", 'response'], ['runRequest', 'POST', "tour-analyses/$id/runs", 'request'],
            ['runResponse', 'POST', "tour-analyses/$id/runs", 'response'], ['acceptRequest', 'POST', "tour-analyses/$id/accept", 'request'],
            ['undoRequest', 'POST', "tour-analyses/$id/accept", 'request'], ['acceptResponse', 'POST', "tour-analyses/$id/accept", 'response'],
            ['cancelRequest', 'POST', "tour-analyses/$id/cancel", 'request'],
        ];
        foreach ($samples as [$key, $method, $path, $kind]) {
            $schema = $kind === 'request' ? $this->tourRequestSchemaFor($path) : $this->tourResponseSchemaFor($path, $method);
            $this->assertNotNull($schema, "$method $path");
            $this->assertTourContract($schema, $endpoints->{$key}, d1: false, message: "fixture-endpoints.$key");
        }
        $this->assertSame(['capabilities', 'analysis', 'analysis', 'progress', 'selectionWrite', 'run', 'run', 'analysis'], [
            $this->tourResponseSchemaFor('tour-analyses', 'GET'), $this->tourResponseSchemaFor('tour-analyses', 'POST'),
            $this->tourResponseSchemaFor("tour-analyses/$id", 'GET'), $this->tourResponseSchemaFor("tour-analyses/$id/progress", 'GET'),
            $this->tourResponseSchemaFor("tour-analyses/$id/selection", 'POST'), $this->tourResponseSchemaFor("tour-analyses/$id/runs", 'POST'),
            $this->tourResponseSchemaFor("tour-analyses/$id/accept", 'POST'), $this->tourResponseSchemaFor("tour-analyses/$id/cancel", 'POST')]);
        $this->assertNull($this->tourResponseSchemaFor("tour-analyses/$id/progress", 'POST'));
        $this->assertNull($this->tourResponseSchemaFor('tour-analyses/abc', 'GET'));
    }

    public function test_the_mirror_rejects_what_the_reader_rejects(): void
    {
        $lighting = fn () => $this->fixture('fixture-lighting.json');
        $kitchen = fn ($rec) => collect($rec->captures)->firstWhere('order', 6);
        $item = fn ($rec) => collect($kitchen($rec)->candidates)->firstWhere('id', 'd6a');
        $cases = [
            'unknown key on the record' => [fn ($v) => $v->debug = true, 'unrecognized'],
            'unknown key on a candidate' => [fn ($v) => $item($v)->note = 'x', 'candidates'],
            '61-character label' => [fn ($v) => $item($v)->label = str_repeat('x', 61), 'label'],
            '161-character description' => [fn ($v) => $item($v)->description = str_repeat('x', 161), 'description'],
            'URL in model text' => [fn ($v) => $item($v)->description = 'see https://example.com', 'description'],
            'reason code with capitals' => [fn ($v) => collect($kitchen($v)->candidates)->firstWhere('id', 'd6f')->reasons = ['Glass'], 'reasons'],
            '9-character candidate id' => [fn ($v) => $item($v)->id = 'abcdefghi', '.id'],
            '33-point outline' => [fn ($v) => $item($v)->outline = array_fill(0, 33, [-52, -21]), 'outline'],
            'outline point outside its region' => [fn ($v) => $item($v)->outline[0] = [170, 80], 'outline'],
            'group ticked apart' => [function ($v) use ($kitchen) {
                collect($v->presets->declutter->selection->changes)->firstWhere('caseId', $kitchen($v)->caseId)->remove = ['d6b', 'd6d'];
            }, 'presets.declutter.selection'],
            'non-executable item ticked' => [function ($v) use ($kitchen) {
                collect($v->presets->declutter->selection->changes)->firstWhere('caseId', $kitchen($v)->caseId)->add = ['d6f'];
            }, 'presets.declutter.selection'],
            'applicability contradicting candidates' => [fn ($v) => collect($v->captures)->firstWhere('order', 14)->applicability->{'golden-hour'} = (object) ['status' => 'possible', 'reasons' => []], 'applicability'],
            'accepting a Ready room' => [function ($v) {
                $run = collect($v->runs)->firstWhere('status', 'completed');
                collect($run->targets)->firstWhere('state', 'ready')->acceptance = (object) ['acceptedAt' => $run->updatedAt];
                $run->counts->accepted = 1;
            }, 'acceptance'],
            'run counts that do not match targets' => [fn ($v) => $v->runs[0]->counts->ready += 1, 'counts'],
            'estimate for a different selection' => [fn ($v) => $v->presets->grass->estimate->itemsSelected += 1, 'estimate'],
            'admitted above the frozen estimate' => [function ($v) {
                $run = collect($v->runs)->firstWhere('status', 'running');
                $run->estimate->roomsThatFit = $run->counts->admitted - 1;
                $run->estimate->worstCaseImages = 3 * $run->estimate->roomsThatFit;
            }, 'estimate'],
            'selection sha256 that is not the canonical hash' => [fn ($v) => $v->presets->twilight->selection->sha256 = str_repeat('0', 64), 'sha256'],
            'runs out of newest-first order' => [fn ($v) => $v->runs = array_reverse($v->runs), 'runs'],
            'activeRunId naming no active run' => [fn ($v) => $v->presets->{'golden-hour'}->activeRunId = $v->runs[1]->id, 'activeRunId'],
            'run failed without an error' => [function ($v) {
                $run = collect($v->runs)->firstWhere('status', 'completed');
                $run->status = 'failed';
            }, 'error'],
            'surveyed capture without applicability' => [fn ($v) => $kitchen($v)->applicability = null, 'captures'],
            'failed capture without an error' => [fn ($v) => collect($v->captures)->firstWhere('status', 'failed')->error = null, 'error'],
            'analysing with nothing pending' => [fn ($v) => $v->status = 'analysing', 'analysing means captures are pending'],
            'capture order out of range' => [fn ($v) => $v->captures[0]->order = 80, 'order'],
            'yaw of 180' => [fn ($v) => $item($v)->region->yaw = 180, 'region.yaw'],
            'region past a pole' => [function ($v) use ($item) {
                $item($v)->region->pitch = -85;
                $item($v)->region->vfov = 20;
            }, 'past a pole'],
            'lighting target with two candidates' => [fn ($v) => $v->runs[0]->targets[0]->candidateIds[] = 'x1', 'targets'],
            'non-boolean executable' => [fn ($v) => $item($v)->executable = 1, 'executable'],
            'float order' => [fn ($v) => $v->captures[0]->order = 0.5, 'order'],
            'another contract version' => [fn ($v) => $v->version = 'tour-analysis/2', 'version'],
            'date that does not parse' => [fn ($v) => $v->createdAt = 'yesterday', 'createdAt'],
        ];
        foreach ($cases as $name => [$mutate, $needle]) {
            $value = $lighting();
            $mutate($value);
            $this->assertTourContractRejects('analysis', $value, $needle, d1: false, message: $name);
        }
        // Boundaries the reader accepts: a 60-character label (UTF-16 units, an emoji counts 2) and a 200-character room name.
        $value = $lighting();
        $item($value)->label = str_repeat('x', 58)."\u{1F600}";
        $value->captures[0]->roomName = str_repeat('R', 200);
        $this->assertTourContract('analysis', $value, d1: false);
        $item($value)->label = str_repeat('x', 59)."\u{1F600}";
        $this->assertTourContractRejects('analysis', $value, 'label', d1: false);

        $endpoints = $this->fixture('fixture-endpoints.json');
        $selection = clone $endpoints->selectionRequest;
        $selection->debounceMs = 1500;
        $this->assertTourContractRejects('selectionRequest', $selection, 'unrecognized');
        $progress = $this->fixture('fixture-progress.json');
        $progress->debug = 1;
        $this->assertTourContractRejects('progress', $progress, 'unrecognized');
        $run = clone $endpoints->runRequest;
        $run->requestId = 'tour-run-1';
        $this->assertTourContractRejects('runRequest', $run, 'requestId');
        $caps = $this->fixture('fixture-endpoints.json')->capabilitiesResponse;
        $caps->capabilities->limits->workflowChunk = 21;
        $this->assertTourContractRejects('capabilities', $caps, 'workflowChunk');
        $caps->capabilities->limits->workflowChunk = 0;
        $this->assertTourContractRejects('capabilities', $caps, 'workflowChunk');
        $caps->capabilities->presets[2] = (object) ['preset' => 'grass', 'ready' => false, 'reason' => null];
        $caps->capabilities->limits->workflowChunk = 8;
        $this->assertTourContractRejects('capabilities', $caps, 'presets');
        $progress = $this->fixture('fixture-progress.json');
        $progress->etaMinutes = (object) ['min' => 1, 'max' => 2];
        $this->assertTourContractRejects('progress', $progress, 'etaMinutes');
    }

    public function test_amended_rules_accept_what_d3_allows(): void
    {
        // A room that no longer fits at a later chunk's re-check becomes not_admitted without joining a workflow (D3).
        $v = $this->fixture('fixture-lighting.json');
        $run = collect($v->runs)->firstWhere('status', 'running');
        $target = collect($run->targets)->where('state', 'queued')->last();
        $run->workflows[0]->caseIds = array_values(array_filter($run->workflows[0]->caseIds, fn ($id) => $id !== $target->caseId));
        foreach (['state' => 'not_admitted', 'workflowId' => null, 'workflowItemId' => null, 'reasons' => ['daily_image_limit']] as $key => $value) $target->{$key} = $value;
        $run->counts->admitted -= 1;
        $run->counts->pending -= 1;
        $this->assertLessThan($run->estimate->roomsThatFit, $run->counts->admitted);
        $this->assertTourContract('analysis', $v, d1: false);

        foreach ([1, 8, 20] as $chunk) {
            $caps = $this->fixture('fixture-endpoints.json')->capabilitiesResponse;
            $caps->capabilities->limits->workflowChunk = $chunk;
            $this->assertTourContract('capabilities', $caps);
        }
    }

    public function test_uuid_rule_needs_version_and_variant_nibbles(): void
    {
        foreach (['8f3c1a2e-5b6d-4e7f-8a9b-0c1d2e3f4a5b', '8F3C1A2E-5B6D-4E7F-AA9B-0C1D2E3F4A5B', '0197a3b2-7c4d-7e5f-9a6b-1c2d3e4f5a6b',
            '00000000-0000-0000-0000-000000000000', 'ffffffff-ffff-ffff-ffff-ffffffffffff', '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
            '1b4e28ba-2fa1-81d2-883f-0016d3cca427'] as $uuid) {
            $this->assertTrue($this->isTourUuid($uuid), $uuid);
        }
        foreach (['8f3c1a2e-5b6d-0e7f-8a9b-0c1d2e3f4a5b', '8f3c1a2e-5b6d-9e7f-8a9b-0c1d2e3f4a5b', '8f3c1a2e-5b6d-4e7f-ca9b-0c1d2e3f4a5b',
            '8f3c1a2e-5b6d-4e7f-7a9b-0c1d2e3f4a5b', 'FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF', '8f3c1a2e5b6d4e7f8a9b0c1d2e3f4a5b',
            "8f3c1a2e-5b6d-4e7f-8a9b-0c1d2e3f4a5b\n", 'g f3c1a2e-5b6d-4e7f-8a9b-0c1d2e3f4a5'] as $uuid) {
            $this->assertFalse($this->isTourUuid($uuid), $uuid);
        }
        $request = $this->fixture('fixture-endpoints.json')->createRequest;
        $request->importId = '8f3c1a2e-5b6d-4e7f-ca9b-0c1d2e3f4a5b';
        $this->assertTourContractRejects('createRequest', $request, 'importId');
    }

    public function test_d1_mode_rejects_outlines_and_php_arrays_that_are_not_wire_lists(): void
    {
        $this->assertTourContractRejects('analysis', $this->fixture('fixture-lighting.json'), 'outline', d1: true);

        // A PHP DTO (associative arrays) is validated as the JSON it encodes to.
        $endpoints = json_decode(json_encode($this->fixture('fixture-endpoints.json')), true);
        $this->assertTourContract('run', $endpoints['runResponse']);
        $gap = $endpoints['runResponse'];
        unset($gap['targets'][1]);                                   // {"0":..,"2":..} on the wire
        $this->assertTourContractRejects('run', $gap, 'targets');
        $empty = $endpoints['capabilitiesResponse'];
        $empty['capabilities']['timing'] = [];                       // [] where the reader wants an object
        $this->assertTourContractRejects('capabilities', $empty, 'timing');
        $request = $endpoints['selectionRequest'];
        $request['changes'][0]['add'] = (object) [];                 // {} where the reader wants a list
        $this->assertTourContractRejects('selectionRequest', $request, 'add');
    }

    public function test_error_bodies_follow_the_bridge_rules(): void
    {
        $body = ['message' => 'No rooms fit within the current limits.', 'code' => 'tour_nothing_fits', 'request_id' => 'req-1'];
        $this->assertSame([], $this->tourErrorIssues($body, 409));
        $this->assertNotSame([], $this->tourErrorIssues($body + ['trace' => 'x'], 409));
        $this->assertNotSame([], $this->tourErrorIssues($body + ['errors' => ['preset' => ['x']]], 409));
        $this->assertSame([], $this->tourErrorIssues($body + ['errors' => ['preset' => ['x']]], 422));
        // The bridge rule allows ':' and '/', but not '<', '>', '@', '#' or '&'.
        $this->assertSame([], $this->tourErrorIssues(['message' => 'See: https://x'] + $body, 409));
        $this->assertNotSame([], $this->tourErrorIssues(['message' => 'Failed: <unknown>'] + $body, 409));
        $this->assertNotSame([], $this->tourErrorIssues(['message' => ''] + $body, 409));
        $this->assertNotSame([], $this->tourErrorIssues(['message' => str_repeat('a', 201)] + $body, 409));
        $this->assertNotSame([], $this->tourErrorIssues($body, 500));
        $this->assertNotSame([], $this->tourErrorIssues(['data' => []], 409));
    }

    private function fixture(string $file): object
    {
        return json_decode(file_get_contents(base_path(self::DIR.$file)), false, 512, JSON_THROW_ON_ERROR);
    }
}
