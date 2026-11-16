<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * PHP mirror of the tour-analysis/1 strict readers (tour-contract/tourContract.ts as amended in spec 12; spec 13.1),
 * for CI, which cannot run Node.
 *
 * Every value is validated as the JSON it puts on the wire: a PHP DTO is json_encode()d and decoded with objects
 * kept as objects, so an associative array that is not a list (`{"0":..,"2":..}`) or an empty array where the reader
 * wants an object fails exactly as it would in zod. Each schema mirrors the zod object (all objects are strict), its
 * field types, regexes and UTF-16 length bounds, and its refinements. Refinements run only when the object's own
 * fields are valid (zod's default). Two rules go beyond the reader, as 13.1 asks:
 * - the record's `presets[p].selection.sha256` must equal sha256(selectionCanonical(effectiveSelection(...)));
 * - in D1 mode (the default) a candidate never carries `outline` (D1 has no inventory outlines). The contract
 *   fixtures, which illustrate Phase 6 outlines, are read with $d1 = false.
 *
 * Schema names: capabilities, analysis, summary, progress, selectionWrite, run, estimate, capture, candidate, survey,
 * createRequest, selectionRequest, runRequest, acceptRequest, cancelRequest.
 */
trait AssertsTourContract
{
    /** zod 4.6.7 z.string().uuid() (regexes.js:27): version 1-8, variant 8/9/a/b, or the nil or (lower-case) max UUID. */
    private const TC_UUID = '/\A(?:[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}|00000000-0000-0000-0000-000000000000|ffffffff-ffff-ffff-ffff-ffffffffffff)\z/D';
    private const TC_SHA256 = '/\A[a-f0-9]{64}\z/D';
    private const TC_SHORT_ID = '/\A[a-z0-9]{1,8}\z/D';
    private const TC_REASON = '/\A[a-z0-9_]{1,64}\z/D';
    /** tourContract.ts UNSAFE_TEXT, copied independently of LabTourText so a backend regression cannot hide here. */
    private const TC_UNSAFE_TEXT = '/bearer|accessToken|api[_ -]?key|secret|https?:\/\/|[\x00-\x1f\x7f]/i';
    /** The bridge's safeMessage rule (README "Bridge facts" 4), at most 200 characters. */
    private const TC_SAFE_MESSAGE = '#\A[\w\s.,:;\'"()!?%/+-]+\z#D';
    private const TC_BRIDGE_ERROR_STATUSES = [400, 401, 403, 404, 409, 413, 422, 429];
    private const TC_PRESETS = ['golden-hour', 'twilight', 'grass', 'declutter'];
    private const TC_KIND_FOR = ['golden-hour' => 'whole_room', 'twilight' => 'whole_room', 'grass' => 'lawn_area', 'declutter' => 'item'];
    private const TC_ANALYSIS_STATES = ['queued', 'analysing', 'ready', 'cancelled', 'failed'];
    private const TC_CAPTURE_STATES = ['pending', 'surveying', 'surveyed', 'failed', 'skipped'];
    private const TC_RUN_STATES = ['creating', 'running', 'completed', 'cancelled', 'failed'];
    private const TC_ACTIVE_RUN_STATES = ['creating', 'running'];
    private const TC_TARGET_STATES = ['not_admitted', 'waiting', 'queued', 'processing', 'ready', 'held', 'failed', 'cancelled'];
    /** TOUR_LIMITS as amended (spec 12.1). */
    private const TC_LIMITS = ['maxCaptures' => 80, 'maxLawnAreas' => 3, 'maxSurfaces' => 4, 'maxItemsPerSurface' => 12, 'maxItemsPerCapture' => 24,
        'maxCandidatesPerCapture' => 29, 'maxOutlinePoints' => 32, 'maxReasons' => 8, 'maxNotes' => 6, 'maxSelectionChanges' => 80,
        'maxRunsPerAnalysis' => 40, 'workflowChunk' => 20, 'maxWorkflowsPerRun' => 10, 'maxSummaries' => 10, 'maxRevision' => 2147483647];
    private const TC_ANGLE_TOLERANCE = 0.5;

    /** @var list<string> */
    private array $tcIssues = [];
    private bool $tcD1 = true;

    protected function assertTourContract(string $schema, mixed $value, bool $d1 = true, string $message = ''): void
    {
        $issues = $this->tourContractIssues($schema, $value, $d1);
        $this->assertSame([], $issues, trim($message.' '.$schema.' must pass the tour-analysis/1 mirror'));
    }

    protected function assertTourContractRejects(string $schema, mixed $value, string $needle, bool $d1 = true, string $message = ''): void
    {
        $issues = $this->tourContractIssues($schema, $value, $d1);
        $this->assertNotEmpty(array_filter($issues, fn (string $issue) => str_contains($issue, $needle)),
            trim($message.": expected an issue containing '$needle' for $schema, got ".json_encode($issues)));
    }

    /** A tour HTTP response: a success body is exactly {data} and passes the route's reader; an error body follows the bridge rules. */
    protected function assertTourResponse(TestResponse $response, string $method, string $path, bool $d1 = true): void
    {
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        if ($status >= 400) {
            $this->assertSame([], $this->tourErrorIssues($body, $status), "$method $path $status error body");

            return;
        }
        $schema = $this->tourResponseSchemaFor($path, $method);
        $this->assertNotNull($schema, "$method $path is not a tour route");
        $this->assertTrue($body instanceof \stdClass && array_keys(get_object_vars($body)) === ['data'], "$method $path: a success body is exactly {data}");
        $this->assertSame([], $this->tourContractIssues($schema, $body->data, $d1), "$method $path $status must pass $schema");
    }

    /** @return list<string> issues of an error body ({message, code, request_id}, plus errors for a 422) */
    protected function tourErrorIssues(mixed $body, int $status): array
    {
        $this->tcIssues = [];
        $body = $this->tcWire($body);
        if (! in_array($status, self::TC_BRIDGE_ERROR_STATUSES, true)) $this->tcIssue('', "status $status does not reach the client as {message}");
        $this->tcObject($body, '', ['message', 'code', 'request_id'], $status === 422 ? ['errors'] : []);
        if ($body instanceof \stdClass) {
            $message = $body->message ?? null;
            if (! is_string($message) || $this->tcUtf16($message) > 200 || preg_match(self::TC_SAFE_MESSAGE, $message) !== 1) $this->tcIssue('message', 'fails the bridge safeMessage rule');
            if (isset($body->code)) $this->tcString($body->code, 'code', 1);
            if (isset($body->request_id)) $this->tcString($body->request_id, 'request_id', 1);
            if (isset($body->errors) && ! $body->errors instanceof \stdClass) $this->tcIssue('errors', 'expected object');
        }

        return $this->tcIssues;
    }

    /** @return list<string> "path: message" for every reader violation; [] when the value passes */
    protected function tourContractIssues(string $schema, mixed $value, bool $d1 = true): array
    {
        $this->tcIssues = [];
        $this->tcD1 = $d1;
        $v = $this->tcWire($value);
        match ($schema) {
            'capabilities' => $this->tcCapabilities($v, ''),
            'analysis' => $this->tcAnalysis($v, ''),
            'summary' => $this->tcSummary($v, ''),
            'progress' => $this->tcProgress($v, ''),
            'selectionWrite' => $this->tcSelectionWrite($v, ''),
            'run' => $this->tcRun($v, ''),
            'estimate' => $this->tcEstimate($v, ''),
            'capture' => $this->tcCapture($v, ''),
            'candidate' => $this->tcCandidate($v, ''),
            'survey' => $this->tcSurvey($v, ''),
            'createRequest' => $this->tcObject($v, '', ['requestId', 'importId', 'preset'])
                && [$this->tcUuid($v->requestId, 'requestId'), $this->tcUuid($v->importId, 'importId'), $this->tcEnum($v->preset, 'preset', self::TC_PRESETS)],
            'selectionRequest' => $this->tcSelectionRequest($v, ''),
            'runRequest' => $this->tcObject($v, '', ['requestId', 'preset', 'selectionRevision', 'selectionSha256'])
                && [$this->tcUuid($v->requestId, 'requestId'), $this->tcEnum($v->preset, 'preset', self::TC_PRESETS),
                    $this->tcInt($v->selectionRevision, 'selectionRevision', 0, self::TC_LIMITS['maxRevision']), $this->tcRegex($v->selectionSha256, 'selectionSha256', self::TC_SHA256)],
            'acceptRequest' => $this->tcObject($v, '', ['runId', 'caseId', 'accepted'])
                && [$this->tcUuid($v->runId, 'runId'), $this->tcUuid($v->caseId, 'caseId'), $this->tcBool($v->accepted, 'accepted')],
            'cancelRequest' => $this->tcObject($v, '', []),
            default => throw new \InvalidArgumentException("Unknown tour contract schema $schema."),
        };

        return $this->tcIssues;
    }

    /** tourResponseSchemaFor (tourContract.ts) as a schema name, or null. A leading /api/iguide-lab/ is ignored. */
    protected function tourResponseSchemaFor(string $path, string $method): ?string
    {
        return match ($this->tourAction($path, $method)) {
            'capabilities' => 'capabilities',
            'create', 'record', 'cancel' => 'analysis',
            'progress' => 'progress',
            'selection' => 'selectionWrite',
            'runs', 'accept' => 'run',
            default => null,
        };
    }

    /** tourRequestSchemaFor (tourContract.ts) as a schema name, or null. */
    protected function tourRequestSchemaFor(string $path): ?string
    {
        return match ($this->tourAction($path, 'POST')) {
            'create' => 'createRequest', 'selection' => 'selectionRequest', 'runs' => 'runRequest', 'accept' => 'acceptRequest', 'cancel' => 'cancelRequest',
            default => null,
        };
    }

    protected function tourAction(string $path, string $method): ?string
    {
        $path = (string) preg_replace('#\A/?(?:api/iguide-lab/)?#', '', $path);
        $uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
        if (! preg_match('#\Atour-analyses(?:/('.$uuid.')(?:/(progress|selection|runs|accept|cancel))?)?\z#D', $path, $m)) return null;
        $id = $m[1] ?? '';
        $sub = $m[2] ?? '';
        if ($method === 'GET') return $id === '' ? 'capabilities' : ($sub === '' ? 'record' : ($sub === 'progress' ? 'progress' : null));
        if ($method === 'POST') return $id === '' ? 'create' : ($sub !== '' && $sub !== 'progress' ? $sub : null);

        return null;
    }

    protected function isTourUuid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::TC_UUID, $value) === 1;
    }

    /** selectionCanonical(preset, effectiveSelection(captures, preset, changes)) of tourContract.ts:381-424. */
    protected function tourSelectionCanonical(string $preset, array $effective): string
    {
        $rows = [];
        foreach ($effective as $caseId => $ids) {
            if (! $ids) continue;
            $ids = array_values($ids);
            sort($ids, SORT_STRING);
            $rows[] = [strtolower((string) $caseId), implode(',', $ids)];
        }
        usort($rows, fn ($a, $b) => strcmp($a[0], $b[0]));
        $lines = ['tour-selection/1', 'preset='.$preset];
        foreach ($rows as [$caseId, $ids]) $lines[] = $caseId.'='.$ids;

        return implode("\n", $lines);
    }

    /**
     * effectiveSelection of tourContract.ts:391-401 over wire captures and changes (objects).
     *
     * @return array<string, list<string>> caseId => ticked candidate ids
     */
    protected function tourEffectiveSelection(array $captures, string $preset, array $changes): array
    {
        $defaultsOf = fn (?object $capture) => $capture === null ? [] : array_values(array_unique(array_map(fn ($x) => $x->id,
            array_values(array_filter($capture->candidates, fn ($x) => $x->preset === $preset && $x->executable === true && $x->defaultSelected === true)))));
        $out = [];
        foreach ($captures as $c) {
            if ($c->status !== 'surveyed') continue;
            $ids = $defaultsOf($c);
            if ($ids) $out[$c->caseId] = $ids;
        }
        foreach ($changes as $ch) {
            $capture = null;
            foreach ($captures as $c) if ($c->caseId === $ch->caseId) { $capture = $c; break; }
            $set = array_values(array_diff($defaultsOf($capture), $ch->remove));
            foreach ($ch->add as $id) if (! in_array($id, $set, true)) $set[] = $id;
            if ($set) $out[$ch->caseId] = $set; else unset($out[$ch->caseId]);
        }

        return $out;
    }

    // ------------------------------------------------------------------ responses

    private function tcCapabilities(mixed $v, string $p): bool
    {
        if (! $this->tcObject($v, $p, ['capabilities', 'analyses'])) return false;
        $c = $v->capabilities;
        $cp = $this->tcPath($p, 'capabilities');
        if ($this->tcObject($c, $cp, ['version', 'enabled', 'runsPaused', 'surveyVersion', 'defaultsVersion', 'presets', 'limits', 'timing'])) {
            $this->tcLiteral($c->version, "$cp.version", 'tour-analysis/1');
            $this->tcBool($c->enabled, "$cp.enabled");
            $this->tcBool($c->runsPaused, "$cp.runsPaused");
            $this->tcLiteral($c->surveyVersion, "$cp.surveyVersion", 'tour-survey/1');
            $this->tcLiteral($c->defaultsVersion, "$cp.defaultsVersion", 'tour-defaults/1');
            if ($this->tcList($c->presets, "$cp.presets", 4, 4)) {
                $presetsOk = true;
                foreach ($c->presets as $i => $preset) {
                    $pp = "$cp.presets.$i";
                    if (! $this->tcObject($preset, $pp, ['preset', 'ready', 'reason'])) { $presetsOk = false; continue; }
                    $ok = $this->tcEnum($preset->preset, "$pp.preset", self::TC_PRESETS) & $this->tcBool($preset->ready, "$pp.ready")
                        & $this->tcNullable($preset->reason, "$pp.reason", fn ($r, $rp) => $this->tcRegex($r, $rp, self::TC_REASON));
                    if ($ok && ! $preset->ready && $preset->reason === null) $this->tcIssue($pp, 'A preset that is not ready needs a reason');
                    $presetsOk = $presetsOk && (bool) $ok;
                }
                if ($presetsOk && ! $this->tcUnique(array_map(fn ($x) => $x->preset, $c->presets))) $this->tcIssue("$cp.presets", 'Duplicate preset');
            }
            if ($this->tcObject($c->limits, "$cp.limits", ['maxCaptures', 'workflowChunk', 'surveyCallsLeftProject', 'surveyCallsLeftToday'])) {
                $this->tcInt($c->limits->maxCaptures, "$cp.limits.maxCaptures", 1, self::TC_LIMITS['maxCaptures']);
                $this->tcInt($c->limits->workflowChunk, "$cp.limits.workflowChunk", 1, self::TC_LIMITS['workflowChunk']);
                $this->tcInt($c->limits->surveyCallsLeftProject, "$cp.limits.surveyCallsLeftProject", 0, 240);
                $this->tcInt($c->limits->surveyCallsLeftToday, "$cp.limits.surveyCallsLeftToday", 0, 160);
            }
            if ($this->tcObject($c->timing, "$cp.timing", ['surveyWorkers', 'surveyMinutesPerCapture', 'editMinutesPerRoom'])) {
                $this->tcInt($c->timing->surveyWorkers, "$cp.timing.surveyWorkers", 1, 8);
                $this->tcMinutes($c->timing->surveyMinutesPerCapture, "$cp.timing.surveyMinutesPerCapture");
                $this->tcMinutes($c->timing->editMinutesPerRoom, "$cp.timing.editMinutesPerRoom");
            }
        }
        if ($this->tcList($v->analyses, $this->tcPath($p, 'analyses'), 0, self::TC_LIMITS['maxSummaries'])) {
            foreach ($v->analyses as $i => $summary) $this->tcSummary($summary, $this->tcPath($p, "analyses.$i"));
        }

        return true;
    }

    private function tcSummary(mixed $v, string $p): bool
    {
        $n = count($this->tcIssues);
        if (! $this->tcObject($v, $p, ['id', 'importId', 'status', 'recordRevision', 'counts', 'createdAt', 'updatedAt'])) return false;
        $this->tcUuid($v->id, $this->tcPath($p, 'id'));
        $this->tcUuid($v->importId, $this->tcPath($p, 'importId'));
        $this->tcEnum($v->status, $this->tcPath($p, 'status'), self::TC_ANALYSIS_STATES);
        $this->tcInt($v->recordRevision, $this->tcPath($p, 'recordRevision'), 0, self::TC_LIMITS['maxRevision']);
        $this->tcAnalysisCounts($v->counts, $this->tcPath($p, 'counts'));
        $this->tcDate($v->createdAt, $this->tcPath($p, 'createdAt'));
        $this->tcDate($v->updatedAt, $this->tcPath($p, 'updatedAt'));

        return count($this->tcIssues) === $n;
    }

    private function tcProgress(mixed $v, string $p): bool
    {
        $n = count($this->tcIssues);
        if (! $this->tcObject($v, $p, ['id', 'status', 'recordRevision', 'counts', 'etaMinutes', 'runs', 'updatedAt'])) return false;
        $this->tcUuid($v->id, $this->tcPath($p, 'id'));
        $this->tcEnum($v->status, $this->tcPath($p, 'status'), self::TC_ANALYSIS_STATES);
        $this->tcInt($v->recordRevision, $this->tcPath($p, 'recordRevision'), 0, self::TC_LIMITS['maxRevision']);
        $this->tcAnalysisCounts($v->counts, $this->tcPath($p, 'counts'));
        $this->tcNullable($v->etaMinutes, $this->tcPath($p, 'etaMinutes'), fn ($m, $mp) => $this->tcMinutes($m, $mp));
        if ($this->tcList($v->runs, $this->tcPath($p, 'runs'), 0, count(self::TC_PRESETS))) {
            foreach ($v->runs as $i => $run) {
                $rp = $this->tcPath($p, "runs.$i");
                if (! $this->tcObject($run, $rp, ['id', 'preset', 'status', 'rooms', 'done', 'ready', 'held', 'failed', 'etaMinutes'])) continue;
                $ok = $this->tcUuid($run->id, "$rp.id") & $this->tcEnum($run->preset, "$rp.preset", self::TC_PRESETS)
                    & $this->tcEnum($run->status, "$rp.status", self::TC_ACTIVE_RUN_STATES) & $this->tcMinutes($run->etaMinutes, "$rp.etaMinutes");
                foreach (['rooms', 'done', 'ready', 'held', 'failed'] as $key) $ok &= $this->tcInt($run->{$key}, "$rp.$key", 0, self::TC_LIMITS['maxCaptures']);
                if ($ok && ($run->done > $run->rooms || $run->ready + $run->held + $run->failed > $run->done)) $this->tcIssue($rp, 'Run progress does not add up');
            }
        }
        $this->tcDate($v->updatedAt, $this->tcPath($p, 'updatedAt'));
        if (count($this->tcIssues) > $n) return false;
        $active = in_array($v->status, ['queued', 'analysing'], true);
        if ($issue = $this->tcStatusIssue($v->status, $v->counts, $active ? null : $v->updatedAt)) $this->tcIssue($this->tcPath($p, 'status'), $issue);
        if (($v->etaMinutes === null) === $active) $this->tcIssue($this->tcPath($p, 'etaMinutes'), 'etaMinutes is set exactly while analysing');
        if (! $this->tcUnique(array_map(fn ($r) => $r->preset, $v->runs))) $this->tcIssue($this->tcPath($p, 'runs'), 'One active run per preset');

        return count($this->tcIssues) === $n;
    }

    private function tcSelectionWrite(mixed $v, string $p): bool
    {
        $n = count($this->tcIssues);
        if (! $this->tcObject($v, $p, ['analysisId', 'preset', 'selection', 'counts', 'estimate'])) return false;
        $this->tcUuid($v->analysisId, $this->tcPath($p, 'analysisId'));
        $this->tcEnum($v->preset, $this->tcPath($p, 'preset'), self::TC_PRESETS);
        $this->tcSelectionState($v->selection, $this->tcPath($p, 'selection'));
        if ($this->tcObject($v->counts, $this->tcPath($p, 'counts'), ['rooms', 'items'])) {
            $this->tcInt($v->counts->rooms, $this->tcPath($p, 'counts.rooms'), 0, self::TC_LIMITS['maxCaptures']);
            $this->tcInt($v->counts->items, $this->tcPath($p, 'counts.items'), 0, self::TC_LIMITS['maxCaptures'] * self::TC_LIMITS['maxItemsPerCapture']);
        }
        $this->tcNullable($v->estimate, $this->tcPath($p, 'estimate'), fn ($e, $ep) => $this->tcEstimate($e, $ep));
        if (count($this->tcIssues) > $n) return false;
        if ($v->estimate !== null && ($v->estimate->selectionRevision !== $v->selection->revision || $v->estimate->roomsSelected !== $v->counts->rooms
            || $v->estimate->itemsSelected !== $v->counts->items)) $this->tcIssue($this->tcPath($p, 'estimate'), 'Estimate does not match this selection');

        return count($this->tcIssues) === $n;
    }

    private function tcSelectionRequest(mixed $v, string $p): bool
    {
        $n = count($this->tcIssues);
        if (! $this->tcObject($v, $p, ['preset', 'baseRevision', 'changes'])) return false;
        $this->tcEnum($v->preset, $this->tcPath($p, 'preset'), self::TC_PRESETS);
        $this->tcInt($v->baseRevision, $this->tcPath($p, 'baseRevision'), 0, self::TC_LIMITS['maxRevision']);
        if ($this->tcList($v->changes, $this->tcPath($p, 'changes'), 1, self::TC_LIMITS['maxSelectionChanges'])) {
            $ok = true;
            foreach ($v->changes as $i => $change) $ok = $this->tcSelectionChange($change, $this->tcPath($p, "changes.$i")) && $ok;
            if ($ok && ! $this->tcUnique(array_map(fn ($c) => strtolower($c->caseId), $v->changes))) $this->tcIssue($this->tcPath($p, 'changes'), 'Duplicate caseId');
        }

        return count($this->tcIssues) === $n;
    }

    // ------------------------------------------------------------------ analysis record

    private function tcAnalysis(mixed $a, string $p): bool
    {
        $n = count($this->tcIssues);
        if (! $this->tcObject($a, $p, ['version', 'id', 'requestId', 'importId', 'status', 'recordRevision', 'surveyVersion', 'defaultsVersion', 'counts',
            'captures', 'presets', 'runs', 'createdAt', 'updatedAt', 'completedAt'])) return false;
        $this->tcLiteral($a->version, $this->tcPath($p, 'version'), 'tour-analysis/1');
        foreach (['id', 'requestId', 'importId'] as $key) $this->tcUuid($a->{$key}, $this->tcPath($p, $key));
        $this->tcEnum($a->status, $this->tcPath($p, 'status'), self::TC_ANALYSIS_STATES);
        $this->tcInt($a->recordRevision, $this->tcPath($p, 'recordRevision'), 0, self::TC_LIMITS['maxRevision']);
        $this->tcLiteral($a->surveyVersion, $this->tcPath($p, 'surveyVersion'), 'tour-survey/1');
        $this->tcLiteral($a->defaultsVersion, $this->tcPath($p, 'defaultsVersion'), 'tour-defaults/1');
        $this->tcAnalysisCounts($a->counts, $this->tcPath($p, 'counts'));
        if ($this->tcList($a->captures, $this->tcPath($p, 'captures'), 1, self::TC_LIMITS['maxCaptures'])) {
            foreach ($a->captures as $i => $capture) $this->tcCapture($capture, $this->tcPath($p, "captures.$i"));
        }
        $this->tcPerPreset($a->presets, $this->tcPath($p, 'presets'), function ($state, $sp) {
            if (! $this->tcObject($state, $sp, ['selection', 'estimate', 'activeRunId'])) return false;

            return $this->tcSelectionState($state->selection, "$sp.selection")
                & $this->tcNullable($state->estimate, "$sp.estimate", fn ($e, $ep) => $this->tcEstimate($e, $ep))
                & $this->tcNullable($state->activeRunId, "$sp.activeRunId", fn ($id, $ip) => $this->tcUuid($id, $ip));
        });
        if ($this->tcList($a->runs, $this->tcPath($p, 'runs'), 0, self::TC_LIMITS['maxRunsPerAnalysis'])) {
            foreach ($a->runs as $i => $run) $this->tcRun($run, $this->tcPath($p, "runs.$i"));
        }
        $this->tcDate($a->createdAt, $this->tcPath($p, 'createdAt'));
        $this->tcDate($a->updatedAt, $this->tcPath($p, 'updatedAt'));
        $this->tcNullable($a->completedAt, $this->tcPath($p, 'completedAt'), fn ($d, $dp) => $this->tcDate($d, $dp));
        if (count($this->tcIssues) > $n) return false;

        $by = fn (string $s) => count(array_filter($a->captures, fn ($c) => $c->status === $s));
        $k = $a->counts;
        if ($k->total !== count($a->captures) || $k->surveyed !== $by('surveyed') || $k->failed !== $by('failed') || $k->skipped !== $by('skipped')
            || $k->pending !== $by('pending') + $by('surveying')) $this->tcIssue($this->tcPath($p, 'counts'), 'Counts do not match captures');
        if ($issue = $this->tcStatusIssue($a->status, $k, $a->completedAt)) $this->tcIssue($this->tcPath($p, 'status'), $issue);
        if (! $this->tcUnique(array_map(fn ($c) => strtolower($c->caseId), $a->captures)) || ! $this->tcUnique(array_map(fn ($c) => $c->order, $a->captures))) {
            $this->tcIssue($this->tcPath($p, 'captures'), 'Duplicate capture caseId or order');
        }
        $allIds = [];
        foreach ($a->captures as $c) foreach ($c->candidates as $x) $allIds[] = $x->id;
        if (! $this->tcUnique($allIds)) $this->tcIssue($this->tcPath($p, 'captures'), 'Candidate IDs are unique per analysis');
        $settled = in_array($a->status, ['ready', 'cancelled'], true);
        foreach (self::TC_PRESETS as $preset) {
            $state = $a->presets->{$preset};
            $sp = $this->tcPath($p, "presets.$preset");
            foreach ($state->selection->changes as $i => $ch) {
                $capture = null;
                foreach ($a->captures as $c) if ($c->caseId === $ch->caseId && $c->status === 'surveyed') { $capture = $c; break; }
                if ($capture === null) { $this->tcIssue("$sp.selection.changes.$i", 'Selection change for an unknown or unsurveyed capture'); continue; }
                $own = array_values(array_filter($capture->candidates, fn ($x) => $x->preset === $preset));
                foreach ($ch->add as $id) {
                    if (! $this->tcAny($own, fn ($x) => $x->id === $id && $x->executable && ! $x->defaultSelected)) {
                        $this->tcIssue("$sp.selection.changes.$i.add", 'add lists only executable, non-default candidates of this preset');
                        break;
                    }
                }
                foreach ($ch->remove as $id) {
                    if (! $this->tcAny($own, fn ($x) => $x->id === $id && $x->defaultSelected)) {
                        $this->tcIssue("$sp.selection.changes.$i.remove", 'remove lists only default candidates of this preset');
                        break;
                    }
                }
            }
            $effective = $this->tourEffectiveSelection($a->captures, $preset, $state->selection->changes);
            foreach ($a->captures as $c) {
                $ticked = $effective[$c->caseId] ?? [];
                $groups = [];
                foreach ($c->candidates as $x) if ($x->preset === $preset && $x->groupId !== null) $groups[$x->groupId][] = in_array($x->id, $ticked, true);
                foreach ($groups as $flags) if (in_array(true, $flags, true) && in_array(false, $flags, true)) $this->tcIssue("$sp.selection", 'A group is ticked together or not at all');
            }
            if ($state->estimate !== null) {
                [$rooms, $items] = $this->tcSelectionCounts($effective);
                if (! $settled) $this->tcIssue("$sp.estimate", 'Estimates exist only once the analysis has finished');
                if ($state->estimate->selectionRevision !== $state->selection->revision || $state->estimate->roomsSelected !== $rooms || $state->estimate->itemsSelected !== $items) {
                    $this->tcIssue("$sp.estimate", 'Estimate does not match the current selection');
                }
            }
            $active = array_values(array_filter($a->runs, fn ($r) => $r->preset === $preset && in_array($r->status, self::TC_ACTIVE_RUN_STATES, true)));
            if (count($active) > 1 || ($active[0]->id ?? null) !== $state->activeRunId) $this->tcIssue("$sp.activeRunId", 'activeRunId must name the single active run of this preset');
            // Beyond the reader (spec 13.1, check-contract part (g)): the stored sha is the canonical hash of the effective selection.
            if ($state->selection->sha256 !== hash('sha256', $this->tourSelectionCanonical($preset, $effective))) {
                $this->tcIssue("$sp.selection.sha256", 'sha256 is not sha256(selectionCanonical(effectiveSelection))');
            }
        }
        if (! $this->tcUnique(array_map(fn ($r) => $r->id, $a->runs)) || ! $this->tcUnique(array_map(fn ($r) => $r->requestId, $a->runs))) {
            $this->tcIssue($this->tcPath($p, 'runs'), 'Duplicate run id or requestId');
        }
        foreach ($a->runs as $i => $r) {
            if ($r->analysisId !== $a->id) $this->tcIssue($this->tcPath($p, "runs.$i.analysisId"), 'Run belongs to another analysis');
            if ($i > 0 && $this->tcTime($r->createdAt) > $this->tcTime($a->runs[$i - 1]->createdAt)) $this->tcIssue($this->tcPath($p, "runs.$i"), 'Runs are newest first');
            foreach ($r->targets as $j => $t) {
                $capture = null;
                foreach ($a->captures as $c) if ($c->caseId === $t->caseId) { $capture = $c; break; }
                $known = $capture !== null;
                foreach ($t->candidateIds as $id) {
                    if ($known && ! $this->tcAny($capture->candidates, fn ($x) => $x->id === $id && $x->preset === $r->preset && $x->executable)) $known = false;
                }
                if (! $known) $this->tcIssue($this->tcPath($p, "runs.$i.targets.$j"), 'Run target names an unknown room or candidate');
            }
        }

        return count($this->tcIssues) === $n;
    }

    private function tcAnalysisCounts(mixed $k, string $p): bool
    {
        if (! $this->tcObject($k, $p, ['total', 'surveyed', 'failed', 'skipped', 'pending'])) return false;
        $ok = true;
        foreach (['total', 'surveyed', 'failed', 'skipped', 'pending'] as $key) $ok = $this->tcInt($k->{$key}, "$p.$key", 0, self::TC_LIMITS['maxCaptures']) && $ok;
        if ($ok && ($k->total < 1 || $k->total !== $k->surveyed + $k->failed + $k->skipped + $k->pending)) { $this->tcIssue($p, 'Counts do not add up'); $ok = false; }

        return $ok;
    }

    private function tcStatusIssue(string $status, object $counts, ?string $completedAt): ?string
    {
        if ($status === 'queued' && $counts->pending !== $counts->total) return 'queued means no capture has finished';
        if ($status === 'analysing' && $counts->pending === 0) return 'analysing means captures are pending';
        if ($status === 'ready' && ($counts->pending > 0 || $counts->surveyed === 0)) return 'ready means all captures finished and at least one was surveyed';
        if ($status === 'failed' && ($counts->pending > 0 || $counts->surveyed > 0)) return 'failed means no capture was surveyed';
        if ($status === 'cancelled' && $counts->pending > 0) return 'cancelled leaves no pending capture';
        if (in_array($status, ['queued', 'analysing'], true) !== ($completedAt === null)) return 'completedAt is null exactly while analysing';

        return null;
    }

    // ------------------------------------------------------------------ captures, candidates, survey

    private function tcCapture(mixed $c, string $p): bool
    {
        $n = count($this->tcIssues);
        if (! $this->tcObject($c, $p, ['caseId', 'order', 'roomName', 'floorName', 'status', 'error', 'survey', 'applicability', 'candidates'])) return false;
        $this->tcUuid($c->caseId, "$p.caseId");
        $this->tcInt($c->order, "$p.order", 0, self::TC_LIMITS['maxCaptures'] - 1);
        $this->tcNullable($c->roomName, "$p.roomName", fn ($s, $sp) => $this->tcString($s, $sp, 0, 200));
        $this->tcNullable($c->floorName, "$p.floorName", fn ($s, $sp) => $this->tcString($s, $sp, 0, 200));
        $this->tcEnum($c->status, "$p.status", self::TC_CAPTURE_STATES);
        $this->tcNullable($c->error, "$p.error", fn ($e, $ep) => $this->tcRegex($e, $ep, self::TC_REASON));
        $this->tcNullable($c->survey, "$p.survey", fn ($s, $sp) => $this->tcSurvey($s, $sp));
        $this->tcNullable($c->applicability, "$p.applicability", fn ($a, $ap) => $this->tcPerPreset($a, $ap, fn ($x, $xp) => $this->tcApplicability($x, $xp)));
        if ($this->tcList($c->candidates, "$p.candidates", 0, self::TC_LIMITS['maxCandidatesPerCapture'])) {
            foreach ($c->candidates as $i => $candidate) $this->tcCandidate($candidate, "$p.candidates.$i");
        }
        if (count($this->tcIssues) > $n) return false;

        $surveyed = $c->status === 'surveyed';
        if ($surveyed !== ($c->survey !== null) || $surveyed !== ($c->applicability !== null)) $this->tcIssue($p, 'survey and applicability are present exactly when surveyed');
        if (! $surveyed && count($c->candidates) > 0) $this->tcIssue("$p.candidates", 'Only surveyed captures have candidates');
        if (in_array($c->status, ['failed', 'skipped'], true) !== ($c->error !== null)) $this->tcIssue("$p.error", 'error is set exactly for failed or skipped captures');
        if (! $surveyed || $c->survey === null || $c->applicability === null) return count($this->tcIssues) === $n;
        if (! $this->tcUnique(array_map(fn ($x) => $x->id, $c->candidates))) $this->tcIssue("$p.candidates", 'Duplicate candidate id');
        foreach (self::TC_PRESETS as $preset) {
            $list = array_values(array_filter($c->candidates, fn ($x) => $x->preset === $preset));
            if (in_array($preset, ['golden-hour', 'twilight'], true) && count($list) !== 1) $this->tcIssue("$p.candidates", "$preset has exactly one whole-room candidate per capture");
            $expected = $this->tcAny($list, fn ($x) => $x->defaultSelected) ? 'recommended' : ($this->tcAny($list, fn ($x) => $x->executable) ? 'possible' : 'not_applicable');
            if ($c->applicability->{$preset}->status !== $expected) $this->tcIssue("$p.applicability.$preset", "$preset applicability must be $expected for these candidates");
        }
        $grass = array_map(fn ($x) => $x->id, array_values(array_filter($c->candidates, fn ($x) => $x->preset === 'grass')));
        if (! $this->tcSameSet(array_map(fn ($area) => $area->id, $c->survey->lawn->areas), $grass)) $this->tcIssue("$p.survey.lawn.areas", 'Each lawn area is exactly one grass candidate');
        $surfaces = $c->survey->clutter->surfaces;
        if (! $this->tcUnique(array_map(fn ($s) => $s->id, $surfaces))) $this->tcIssue("$p.survey.clutter.surfaces", 'Duplicate surface id');
        $declutter = array_map(fn ($x) => $x->id, array_values(array_filter($c->candidates, fn ($x) => $x->preset === 'declutter')));
        if (count($declutter) > self::TC_LIMITS['maxItemsPerCapture']) $this->tcIssue("$p.candidates", 'Too many declutter items');
        $itemIds = [];
        foreach ($surfaces as $s) foreach ($s->itemIds as $id) $itemIds[] = $id;
        if (! $this->tcSameSet($itemIds, $declutter)) $this->tcIssue("$p.survey.clutter.surfaces", 'Each declutter item sits on exactly one surface');
        $groups = [];
        foreach ($c->candidates as $x) if ($x->groupId !== null) $groups[$x->groupId][] = $x;
        foreach ($groups as $members) {
            $first = $members[0];
            if (count($members) < 2) $this->tcIssue("$p.candidates", 'A group has at least two items');
            if ($this->tcAny($members, fn ($m) => $m->preset !== $first->preset || $m->defaultSelected !== $first->defaultSelected || $m->executable !== $first->executable)) {
                $this->tcIssue("$p.candidates", 'Grouped items share preset, default and executability');
            }
        }

        return count($this->tcIssues) === $n;
    }

    private function tcCandidate(mixed $c, string $p): bool
    {
        $n = count($this->tcIssues);
        if (! $this->tcObject($c, $p, ['id', 'groupId', 'preset', 'kind', 'label', 'description', 'origin', 'region', 'confidence', 'executable',
            'defaultSelected', 'reasons'], ['outline'])) return false;
        $this->tcRegex($c->id, $this->tcPath($p, 'id'), self::TC_SHORT_ID);
        $this->tcNullable($c->groupId, $this->tcPath($p, 'groupId'), fn ($g, $gp) => $this->tcRegex($g, $gp, self::TC_SHORT_ID));
        $this->tcEnum($c->preset, $this->tcPath($p, 'preset'), self::TC_PRESETS);
        $this->tcEnum($c->kind, $this->tcPath($p, 'kind'), ['whole_room', 'lawn_area', 'item']);
        $this->tcText($c->label, $this->tcPath($p, 'label'), 1, 60);
        $this->tcText($c->description, $this->tcPath($p, 'description'), 0, 160);
        $this->tcEnum($c->origin, $this->tcPath($p, 'origin'), ['rule', 'survey', 'inventory']);
        $this->tcRegion($c->region, $this->tcPath($p, 'region'));
        if (property_exists($c, 'outline')) {
            if ($this->tcD1) $this->tcIssue($this->tcPath($p, 'outline'), 'D1 never emits an outline');
            if ($this->tcList($c->outline, $this->tcPath($p, 'outline'), 3, self::TC_LIMITS['maxOutlinePoints'])) {
                foreach ($c->outline as $i => $point) {
                    $pp = $this->tcPath($p, "outline.$i");
                    if ($this->tcList($point, $pp, 2, 2)) {
                        $this->tcNumber($point[0], "$pp.0", gte: -180, lt: 180);
                        $this->tcNumber($point[1], "$pp.1", gte: -90, lte: 90);
                    }
                }
            }
        }
        $this->tcNumber($c->confidence, $this->tcPath($p, 'confidence'), gte: 0, lte: 1);
        $this->tcBool($c->executable, $this->tcPath($p, 'executable'));
        $this->tcBool($c->defaultSelected, $this->tcPath($p, 'defaultSelected'));
        $this->tcReasons($c->reasons, $this->tcPath($p, 'reasons'));
        if (count($this->tcIssues) > $n) return false;

        if ($c->kind !== self::TC_KIND_FOR[$c->preset]) $this->tcIssue($this->tcPath($p, 'kind'), 'kind does not match preset');
        $full = (float) $c->region->yaw === 0.0 && (float) $c->region->pitch === 0.0 && (float) $c->region->hfov === 360.0 && (float) $c->region->vfov === 180.0;
        if ($c->kind === 'whole_room' && ($c->origin !== 'rule' || property_exists($c, 'outline') || ! $full)) $this->tcIssue($this->tcPath($p, 'region'), 'A whole-room candidate is a rule over the full sphere without an outline');
        if ($c->kind === 'lawn_area' && $c->origin !== 'survey') $this->tcIssue($this->tcPath($p, 'origin'), 'Lawn areas come from the survey');
        if ($c->kind === 'item' && $c->origin === 'rule') $this->tcIssue($this->tcPath($p, 'origin'), 'Items come from the survey or the inventory');
        if ($c->groupId !== null && $c->kind !== 'item') $this->tcIssue($this->tcPath($p, 'groupId'), 'Only declutter items can be grouped');
        if ($c->defaultSelected && ! $c->executable) $this->tcIssue($this->tcPath($p, 'defaultSelected'), 'A non-executable candidate cannot be ticked by default');
        if (! $c->executable && count($c->reasons) === 0) $this->tcIssue($this->tcPath($p, 'reasons'), 'A non-executable candidate needs a reason');
        foreach (property_exists($c, 'outline') ? $c->outline : [] as $i => $point) {
            $delta = fmod(fmod($point[0] - $c->region->yaw, 360) + 540, 360) - 180;
            if (abs($delta) > $c->region->hfov / 2 + self::TC_ANGLE_TOLERANCE || abs($point[1] - $c->region->pitch) > $c->region->vfov / 2 + self::TC_ANGLE_TOLERANCE) {
                $this->tcIssue($this->tcPath($p, "outline.$i"), 'Outline point outside its region');
            }
        }

        return count($this->tcIssues) === $n;
    }

    private function tcRegion(mixed $r, string $p): bool
    {
        if (! $this->tcObject($r, $p, ['yaw', 'pitch', 'hfov', 'vfov'])) return false;
        $ok = $this->tcNumber($r->yaw, "$p.yaw", gte: -180, lt: 180) & $this->tcNumber($r->pitch, "$p.pitch", gte: -90, lte: 90)
            & $this->tcNumber($r->hfov, "$p.hfov", gt: 0, lte: 360) & $this->tcNumber($r->vfov, "$p.vfov", gt: 0, lte: 180);
        if ($ok && abs($r->pitch) + $r->vfov / 2 > 90 + self::TC_ANGLE_TOLERANCE) { $this->tcIssue($p, 'Region extends past a pole'); return false; }

        return (bool) $ok;
    }

    private function tcSurvey(mixed $s, string $p): bool
    {
        $n = count($this->tcIssues);
        if (! $this->tcObject($s, $p, ['space', 'skyShare', 'light', 'lawn', 'clutter', 'notes'])) return false;
        $pp = fn (string $key) => $this->tcPath($p, $key);
        if ($this->tcObject($s->space, $pp('space'), ['setting', 'label'])) {
            $this->tcEnum($s->space->setting, $pp('space.setting'), ['interior', 'exterior']);
            $this->tcText($s->space->label, $pp('space.label'), 1, 60);
        }
        $this->tcNumber($s->skyShare, $pp('skyShare'), gte: 0, lte: 1);
        if ($this->tcObject($s->light, $pp('light'), ['windows', 'daylight', 'lamps', 'hardShadows', 'mirrorsOrGlass'])) {
            foreach (['windows', 'daylight', 'lamps', 'hardShadows', 'mirrorsOrGlass'] as $key) $this->tcBool($s->light->{$key}, $pp("light.$key"));
        }
        if ($this->tcObject($s->lawn, $pp('lawn'), ['status', 'condition', 'areas'])) {
            $lawn = $s->lawn;
            $ok = $this->tcEnum($lawn->status, $pp('lawn.status'), ['none', 'found', 'uncertain'])
                & $this->tcNullable($lawn->condition, $pp('lawn.condition'), fn ($c, $cp) => $this->tcRegex($c, $cp, self::TC_REASON));
            if ($this->tcList($lawn->areas, $pp('lawn.areas'), 0, self::TC_LIMITS['maxLawnAreas'])) {
                foreach ($lawn->areas as $i => $area) {
                    $ap = $pp("lawn.areas.$i");
                    if (! $this->tcObject($area, $ap, ['id', 'label', 'position', 'region'])) { $ok = 0; continue; }
                    $ok &= $this->tcRegex($area->id, "$ap.id", self::TC_SHORT_ID) & $this->tcText($area->label, "$ap.label", 1, 60)
                        & $this->tcEnum($area->position, "$ap.position", ['front', 'back', 'side', 'unknown']) & $this->tcRegion($area->region, "$ap.region");
                }
            } else {
                $ok = 0;
            }
            if ($ok) {
                if ($lawn->status === 'none' && (count($lawn->areas) > 0 || $lawn->condition !== null)) $this->tcIssue($pp('lawn'), 'No lawn means no areas and no condition');
                if ($lawn->status === 'found' && count($lawn->areas) === 0) $this->tcIssue($pp('lawn.areas'), 'A found lawn has at least one area');
                if (! $this->tcUnique(array_map(fn ($a) => $a->id, $lawn->areas))) $this->tcIssue($pp('lawn.areas'), 'Duplicate lawn area id');
            }
        }
        if ($this->tcObject($s->clutter, $pp('clutter'), ['surfaces']) && $this->tcList($s->clutter->surfaces, $pp('clutter.surfaces'), 0, self::TC_LIMITS['maxSurfaces'])) {
            foreach ($s->clutter->surfaces as $i => $surface) {
                $sp = $pp("clutter.surfaces.$i");
                if (! $this->tcObject($surface, $sp, ['id', 'label', 'region', 'itemIds'])) continue;
                $this->tcRegex($surface->id, "$sp.id", self::TC_SHORT_ID);
                $this->tcText($surface->label, "$sp.label", 1, 60);
                $this->tcRegion($surface->region, "$sp.region");
                if ($this->tcList($surface->itemIds, "$sp.itemIds", 1, self::TC_LIMITS['maxItemsPerSurface'])) {
                    foreach ($surface->itemIds as $j => $id) $this->tcRegex($id, "$sp.itemIds.$j", self::TC_SHORT_ID);
                }
            }
        }
        if ($this->tcList($s->notes, $pp('notes'), 0, self::TC_LIMITS['maxNotes'])) {
            foreach ($s->notes as $i => $note) {
                if (! $this->tcObject($note, $pp("notes.$i"), ['topic', 'text'])) continue;
                $this->tcEnum($note->topic, $pp("notes.$i.topic"), ['space', 'light', 'lawn', 'clutter', 'general']);
                $this->tcText($note->text, $pp("notes.$i.text"), 1, 200);
            }
        }

        return count($this->tcIssues) === $n;
    }

    private function tcApplicability(mixed $a, string $p): bool
    {
        if (! $this->tcObject($a, $p, ['status', 'reasons'])) return false;
        $ok = $this->tcEnum($a->status, "$p.status", ['recommended', 'possible', 'not_applicable']) & $this->tcReasons($a->reasons, "$p.reasons");
        if ($ok && $a->status === 'not_applicable' && count($a->reasons) === 0) { $this->tcIssue($p, 'not_applicable needs a reason'); return false; }

        return (bool) $ok;
    }

    // ------------------------------------------------------------------ selection, estimate, runs

    private function tcSelectionChange(mixed $c, string $p): bool
    {
        if (! $this->tcObject($c, $p, ['caseId', 'add', 'remove'])) return false;
        $ok = $this->tcUuid($c->caseId, "$p.caseId");
        foreach (['add', 'remove'] as $key) {
            if (! $this->tcList($c->{$key}, "$p.$key", 0, self::TC_LIMITS['maxItemsPerCapture'])) { $ok = false; continue; }
            foreach ($c->{$key} as $i => $id) $ok = $this->tcRegex($id, "$p.$key.$i", self::TC_SHORT_ID) && $ok;
        }
        if ($ok && (! $this->tcUnique($c->add) || ! $this->tcUnique($c->remove) || array_intersect($c->add, $c->remove))) { $this->tcIssue($p, 'add/remove must be distinct'); return false; }

        return $ok;
    }

    private function tcSelectionState(mixed $s, string $p): bool
    {
        if (! $this->tcObject($s, $p, ['revision', 'sha256', 'changes'])) return false;
        $ok = $this->tcInt($s->revision, "$p.revision", 0, self::TC_LIMITS['maxRevision']) & $this->tcRegex($s->sha256, "$p.sha256", self::TC_SHA256);
        if (! $this->tcList($s->changes, "$p.changes", 0, self::TC_LIMITS['maxSelectionChanges'])) return false;
        $changesOk = true;
        foreach ($s->changes as $i => $change) $changesOk = $this->tcSelectionChange($change, "$p.changes.$i") && $changesOk;
        if ($changesOk) {
            if (! $this->tcUnique(array_map(fn ($c) => strtolower($c->caseId), $s->changes))) { $this->tcIssue("$p.changes", 'Duplicate caseId'); $changesOk = false; }
            if ($this->tcAny($s->changes, fn ($c) => count($c->add) + count($c->remove) === 0)) { $this->tcIssue("$p.changes", 'Stored changes are never empty'); $changesOk = false; }
        }

        return $ok && $changesOk;
    }

    private function tcEstimate(mixed $e, string $p): bool
    {
        $n = count($this->tcIssues);
        if (! $this->tcObject($e, $p, ['selectionRevision', 'roomsSelected', 'itemsSelected', 'roomsThatFit', 'worstCaseImages', 'limitingReason', 'estimatedMinutes', 'computedAt'])) return false;
        $this->tcInt($e->selectionRevision, "$p.selectionRevision", 0, self::TC_LIMITS['maxRevision']);
        $this->tcInt($e->roomsSelected, "$p.roomsSelected", 0, self::TC_LIMITS['maxCaptures']);
        $this->tcInt($e->itemsSelected, "$p.itemsSelected", 0, self::TC_LIMITS['maxCaptures'] * self::TC_LIMITS['maxItemsPerCapture']);
        $this->tcInt($e->roomsThatFit, "$p.roomsThatFit", 0, self::TC_LIMITS['maxCaptures']);
        $this->tcInt($e->worstCaseImages, "$p.worstCaseImages", 0, 1000);
        $this->tcNullable($e->limitingReason, "$p.limitingReason", fn ($r, $rp) => $this->tcRegex($r, $rp, self::TC_REASON));
        $this->tcMinutes($e->estimatedMinutes, "$p.estimatedMinutes");
        $this->tcDate($e->computedAt, "$p.computedAt");
        if (count($this->tcIssues) > $n) return false;
        if ($e->roomsThatFit > $e->roomsSelected) $this->tcIssue("$p.roomsThatFit", 'More rooms fit than were selected');
        if (($e->limitingReason === null) !== ($e->roomsThatFit === $e->roomsSelected)) $this->tcIssue("$p.limitingReason", 'limitingReason is null exactly when every room fits');
        if ($e->itemsSelected < $e->roomsSelected) $this->tcIssue("$p.itemsSelected", 'Every selected room has an item');

        return count($this->tcIssues) === $n;
    }

    private function tcRun(mixed $r, string $p): bool
    {
        $n = count($this->tcIssues);
        $pp = fn (string $key) => $this->tcPath($p, $key);
        if (! $this->tcObject($r, $p, ['id', 'requestId', 'analysisId', 'preset', 'status', 'selectionRevision', 'selectionSha256', 'estimate', 'counts',
            'workflows', 'targets', 'error', 'createdAt', 'updatedAt', 'completedAt'])) return false;
        foreach (['id', 'requestId', 'analysisId'] as $key) $this->tcUuid($r->{$key}, $pp($key));
        $this->tcEnum($r->preset, $pp('preset'), self::TC_PRESETS);
        $this->tcEnum($r->status, $pp('status'), self::TC_RUN_STATES);
        $this->tcInt($r->selectionRevision, $pp('selectionRevision'), 0, self::TC_LIMITS['maxRevision']);
        $this->tcRegex($r->selectionSha256, $pp('selectionSha256'), self::TC_SHA256);
        $this->tcEstimate($r->estimate, $pp('estimate'));
        $countKeys = ['selected', 'admitted', 'pending', 'ready', 'held', 'failed', 'cancelled', 'accepted'];
        if ($this->tcObject($r->counts, $pp('counts'), $countKeys)) {
            foreach ($countKeys as $key) $this->tcInt($r->counts->{$key}, $pp("counts.$key"), 0, self::TC_LIMITS['maxCaptures']);
        }
        if ($this->tcList($r->workflows, $pp('workflows'), 0, self::TC_LIMITS['maxWorkflowsPerRun'])) {
            foreach ($r->workflows as $i => $w) {
                $wp = $pp("workflows.$i");
                if (! $this->tcObject($w, $wp, ['id', 'requestId', 'caseIds'])) continue;
                $this->tcUuid($w->id, "$wp.id");
                $this->tcUuid($w->requestId, "$wp.requestId");
                if ($this->tcList($w->caseIds, "$wp.caseIds", 1, self::TC_LIMITS['workflowChunk'])) {
                    $ok = true;
                    foreach ($w->caseIds as $j => $id) $ok = $this->tcUuid($id, "$wp.caseIds.$j") && $ok;
                    if ($ok && ! $this->tcUnique($w->caseIds)) $this->tcIssue("$wp.caseIds", 'Duplicate caseId');
                }
            }
        }
        if ($this->tcList($r->targets, $pp('targets'), 1, self::TC_LIMITS['maxCaptures'])) {
            foreach ($r->targets as $i => $t) $this->tcRunTarget($t, $pp("targets.$i"));
        }
        $this->tcNullable($r->error, $pp('error'), fn ($e, $ep) => $this->tcRegex($e, $ep, self::TC_REASON));
        $this->tcDate($r->createdAt, $pp('createdAt'));
        $this->tcDate($r->updatedAt, $pp('updatedAt'));
        $this->tcNullable($r->completedAt, $pp('completedAt'), fn ($d, $dp) => $this->tcDate($d, $dp));
        if (count($this->tcIssues) > $n) return false;

        $count = fn (string ...$states) => count(array_filter($r->targets, fn ($t) => in_array($t->state, $states, true)));
        $c = $r->counts;
        if ($c->selected !== count($r->targets) || $c->admitted !== count($r->targets) - $count('not_admitted') || $c->pending !== $count('waiting', 'queued', 'processing')
            || $c->ready !== $count('ready') || $c->held !== $count('held') || $c->failed !== $count('failed') || $c->cancelled !== $count('cancelled')
            || $c->accepted !== count(array_filter($r->targets, fn ($t) => $t->acceptance !== null))) $this->tcIssue($pp('counts'), 'Run counts do not match targets');
        // Amended (spec 12.1, D3): admitted may only shrink below the frozen roomsThatFit.
        if ($r->estimate->selectionRevision !== $r->selectionRevision || $r->estimate->roomsSelected !== $c->selected || $c->admitted > $r->estimate->roomsThatFit) {
            $this->tcIssue($pp('estimate'), 'Frozen estimate does not match the run');
        }
        if (! $this->tcUnique(array_map(fn ($t) => strtolower($t->caseId), $r->targets))) $this->tcIssue($pp('targets'), 'Duplicate target caseId');
        if (in_array($r->preset, ['golden-hour', 'twilight'], true) && $this->tcAny($r->targets, fn ($t) => count($t->candidateIds) !== 1)) $this->tcIssue($pp('targets'), 'Lighting targets bind one whole-room candidate');
        $requestIds = array_merge([$r->requestId], array_map(fn ($w) => $w->requestId, $r->workflows));
        if (! $this->tcUnique(array_map(fn ($w) => $w->id, $r->workflows)) || ! $this->tcUnique($requestIds)) $this->tcIssue($pp('workflows'), 'Workflow IDs and request IDs are unique');
        foreach ($r->targets as $i => $t) {
            if ($t->workflowId === null) continue;
            $w = null;
            foreach ($r->workflows as $x) if ($x->id === $t->workflowId) { $w = $x; break; }
            if ($w === null || ! in_array($t->caseId, $w->caseIds, true)) $this->tcIssue($pp("targets.$i.workflowId"), 'Target workflow does not contain this room');
        }
        foreach ($r->workflows as $i => $w) {
            foreach ($w->caseIds as $id) {
                if (! $this->tcAny($r->targets, fn ($t) => $t->caseId === $id && $t->workflowId === $w->id)) { $this->tcIssue($pp("workflows.$i"), 'Workflow room has no bound target'); break; }
            }
        }
        $active = in_array($r->status, self::TC_ACTIVE_RUN_STATES, true);
        if ($active !== ($r->completedAt === null)) $this->tcIssue($pp('completedAt'), 'completedAt is null exactly while the run is active');
        if ($r->status === 'completed' && $c->pending > 0) $this->tcIssue($pp('status'), 'A completed run has no pending rooms');
        if ($r->status === 'running' && count($r->workflows) === 0) $this->tcIssue($pp('workflows'), 'A running run has at least one workflow');
        if (($r->status === 'failed') !== ($r->error !== null)) $this->tcIssue($pp('error'), 'error is set exactly for failed runs');

        return count($this->tcIssues) === $n;
    }

    private function tcRunTarget(mixed $t, string $p): bool
    {
        $n = count($this->tcIssues);
        if (! $this->tcObject($t, $p, ['caseId', 'candidateIds', 'state', 'workflowId', 'workflowItemId', 'hasAttempt', 'reasons', 'acceptance'])) return false;
        $this->tcUuid($t->caseId, "$p.caseId");
        if ($this->tcList($t->candidateIds, "$p.candidateIds", 1, self::TC_LIMITS['maxItemsPerCapture'])) {
            $ok = true;
            foreach ($t->candidateIds as $i => $id) $ok = $this->tcRegex($id, "$p.candidateIds.$i", self::TC_SHORT_ID) && $ok;
            if ($ok && ! $this->tcUnique($t->candidateIds)) $this->tcIssue("$p.candidateIds", 'Duplicate candidate id');
        }
        $this->tcEnum($t->state, "$p.state", self::TC_TARGET_STATES);
        $this->tcNullable($t->workflowId, "$p.workflowId", fn ($id, $ip) => $this->tcUuid($id, $ip));
        $this->tcNullable($t->workflowItemId, "$p.workflowItemId", fn ($id, $ip) => $this->tcUuid($id, $ip));
        $this->tcBool($t->hasAttempt, "$p.hasAttempt");
        $this->tcReasons($t->reasons, "$p.reasons");
        $this->tcNullable($t->acceptance, "$p.acceptance", fn ($a, $ap) => $this->tcObject($a, $ap, ['acceptedAt']) && $this->tcDate($a->acceptedAt, "$ap.acceptedAt"));
        if (count($this->tcIssues) > $n) return false;

        $unbound = in_array($t->state, ['not_admitted', 'waiting'], true);
        if ($unbound && ($t->workflowId !== null || $t->workflowItemId !== null)) $this->tcIssue("$p.workflowId", 'Unbound targets have no workflow');
        if (! $unbound && $t->state !== 'cancelled' && ($t->workflowId === null || $t->workflowItemId === null)) $this->tcIssue("$p.workflowId", 'Bound targets name their workflow and item');
        if (($t->workflowId === null) !== ($t->workflowItemId === null)) $this->tcIssue("$p.workflowItemId", 'workflowId and workflowItemId go together');
        if ($t->state === 'ready' && ! $t->hasAttempt) $this->tcIssue("$p.hasAttempt", 'Ready has a result');
        if ($t->hasAttempt && ! in_array($t->state, ['ready', 'held'], true)) $this->tcIssue("$p.hasAttempt", 'Only ready or held targets have an attempt');
        if ($t->acceptance !== null && ! ($t->state === 'held' && $t->hasAttempt)) $this->tcIssue("$p.acceptance", 'Only a held attempt can be accepted');
        if ($t->state === 'not_admitted' && count($t->reasons) === 0) $this->tcIssue("$p.reasons", 'A room that did not fit needs the limiting reason');

        return count($this->tcIssues) === $n;
    }

    // ------------------------------------------------------------------ primitives

    /** The value as JSON on the wire: objects stay objects (stdClass), lists stay arrays. */
    private function tcWire(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    private function tcIssue(string $path, string $message): void
    {
        $this->tcIssues[] = ($path === '' ? '(root)' : $path).': '.$message;
    }

    private function tcPath(string $path, string $key): string
    {
        return $path === '' ? $key : "$path.$key";
    }

    private function tcType(mixed $v): string
    {
        return match (true) { $v instanceof \stdClass => 'object', is_array($v) => 'array', default => get_debug_type($v) };
    }

    /** A strict zod object: exactly $required (all present) plus any of $optional. */
    private function tcObject(mixed $v, string $p, array $required, array $optional = []): bool
    {
        if (! $v instanceof \stdClass) { $this->tcIssue($p, 'expected object, received '.$this->tcType($v)); return false; }
        $ok = true;
        $keys = array_map('strval', array_keys(get_object_vars($v)));
        foreach (array_diff($keys, $required, $optional) as $extra) { $this->tcIssue($p, "unrecognized key $extra"); $ok = false; }
        foreach ($required as $key) if (! property_exists($v, $key)) { $this->tcIssue($this->tcPath($p, $key), 'required'); $ok = false; }

        return $ok;
    }

    private function tcList(mixed $v, string $p, int $min = 0, ?int $max = null): bool
    {
        if (! is_array($v) || ! array_is_list($v)) { $this->tcIssue($p, 'expected array, received '.$this->tcType($v)); return false; }
        if (count($v) < $min) { $this->tcIssue($p, "too_small: at least $min items"); return false; }
        if ($max !== null && count($v) > $max) { $this->tcIssue($p, "too_big: at most $max items"); return false; }

        return true;
    }

    private function tcPerPreset(mixed $v, string $p, callable $each): bool
    {
        if (! $this->tcObject($v, $p, self::TC_PRESETS)) return false;
        $ok = true;
        foreach (self::TC_PRESETS as $preset) $ok = (bool) $each($v->{$preset}, "$p.$preset") && $ok;

        return $ok;
    }

    private function tcNullable(mixed $v, string $p, callable $check): bool
    {
        return $v === null || (bool) $check($v, $p);
    }

    private function tcString(mixed $v, string $p, int $min = 0, ?int $max = null): bool
    {
        if (! is_string($v)) { $this->tcIssue($p, 'expected string, received '.$this->tcType($v)); return false; }
        $length = $this->tcUtf16($v);
        if ($length < $min) { $this->tcIssue($p, "too_small: at least $min characters"); return false; }
        if ($max !== null && $length > $max) { $this->tcIssue($p, "too_big: at most $max characters"); return false; }

        return true;
    }

    /** text(min, max) of tourContract.ts: UTF-16 bounds and no unsafe word, URL or control character. */
    private function tcText(mixed $v, string $p, int $min, int $max): bool
    {
        if (! $this->tcString($v, $p, $min, $max)) return false;
        if (preg_match(self::TC_UNSAFE_TEXT, $v) !== 0) { $this->tcIssue($p, 'Unsafe text'); return false; }

        return true;
    }

    private function tcUtf16(string $v): int
    {
        return intdiv(strlen(mb_convert_encoding($v, 'UTF-16LE', 'UTF-8')), 2);
    }

    private function tcRegex(mixed $v, string $p, string $pattern): bool
    {
        if (! is_string($v) || preg_match($pattern, $v) !== 1) { $this->tcIssue($p, 'invalid_format'); return false; }

        return true;
    }

    private function tcUuid(mixed $v, string $p): bool
    {
        if (! $this->isTourUuid($v)) { $this->tcIssue($p, 'invalid_format: not a UUID'); return false; }

        return true;
    }

    private function tcEnum(mixed $v, string $p, array $values): bool
    {
        if (! in_array($v, $values, true)) { $this->tcIssue($p, 'invalid_value: expected one of '.implode('|', $values)); return false; }

        return true;
    }

    private function tcLiteral(mixed $v, string $p, string $literal): bool
    {
        if ($v !== $literal) { $this->tcIssue($p, "invalid_value: expected $literal"); return false; }

        return true;
    }

    private function tcBool(mixed $v, string $p): bool
    {
        if (! is_bool($v)) { $this->tcIssue($p, 'expected boolean, received '.$this->tcType($v)); return false; }

        return true;
    }

    /** z.number() with optional bounds (JSON numbers are finite). */
    private function tcNumber(mixed $v, string $p, ?float $gte = null, ?float $gt = null, ?float $lte = null, ?float $lt = null): bool
    {
        if (! is_int($v) && ! is_float($v)) { $this->tcIssue($p, 'expected number, received '.$this->tcType($v)); return false; }
        if (($gte !== null && $v < $gte) || ($gt !== null && $v <= $gt)) { $this->tcIssue($p, 'too_small'); return false; }
        if (($lte !== null && $v > $lte) || ($lt !== null && $v >= $lt)) { $this->tcIssue($p, 'too_big'); return false; }

        return true;
    }

    /** z.number().int().min(min).max(max): an integral JSON number. */
    private function tcInt(mixed $v, string $p, int $min, int $max): bool
    {
        if (! $this->tcNumber($v, $p)) return false;
        if (is_float($v) && floor($v) !== $v) { $this->tcIssue($p, 'expected int, received number'); return false; }

        return $this->tcNumber($v, $p, gte: $min, lte: $max);
    }

    private function tcMinutes(mixed $m, string $p): bool
    {
        if (! $this->tcObject($m, $p, ['min', 'max'])) return false;
        $ok = $this->tcInt($m->min, "$p.min", 0, 10000) & $this->tcInt($m->max, "$p.max", 0, 10000);
        if ($ok && $m->min > $m->max) { $this->tcIssue($p, 'min exceeds max'); return false; }

        return (bool) $ok;
    }

    /** DATE: a string of at most 64 characters that Date.parse() reads; the mirror accepts ISO 8601 only (what the backend writes). */
    private function tcDate(mixed $v, string $p): bool
    {
        if (! $this->tcString($v, $p, 0, 64)) return false;
        $parsed = date_parse($v);
        if (preg_match('/\A\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,9})?)?(?:Z|[+-]\d{2}:\d{2})?)?\z/D', $v) !== 1
            || $parsed['error_count'] > 0 || $parsed['warning_count'] > 0) { $this->tcIssue($p, 'Invalid date'); return false; }

        return true;
    }

    private function tcTime(string $date): float
    {
        return (float) (new \DateTimeImmutable($date))->format('U.u');
    }

    private function tcReasons(mixed $v, string $p): bool
    {
        if (! $this->tcList($v, $p, 0, self::TC_LIMITS['maxReasons'])) return false;
        $ok = true;
        foreach ($v as $i => $code) $ok = $this->tcRegex($code, "$p.$i", self::TC_REASON) && $ok;
        if ($ok && ! $this->tcUnique($v)) { $this->tcIssue($p, 'Duplicate reason code'); return false; }

        return $ok;
    }

    private function tcUnique(array $values): bool
    {
        return count($values) === count(array_unique(array_map(fn ($v) => json_encode($v), $values)));
    }

    private function tcSameSet(array $a, array $b): bool
    {
        return count($a) === count($b) && $this->tcUnique($a) && $this->tcUnique($b) && ! array_diff($a, $b);
    }

    private function tcAny(array $values, callable $test): bool
    {
        foreach ($values as $value) if ($test($value)) return true;

        return false;
    }

    /** @return array{0:int,1:int} selectionCounts: [rooms, items] */
    private function tcSelectionCounts(array $effective): array
    {
        $rooms = 0;
        $items = 0;
        foreach ($effective as $ids) {
            if ($ids) $rooms++;
            $items += count($ids);
        }

        return [$rooms, $items];
    }
}
