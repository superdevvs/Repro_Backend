<?php
namespace App\Services\IguideLab;

use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourCapture;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;

/**
 * Per-preset selections of a tour analysis (spec 7): stored per-capture deltas against the defaults, the effective
 * selection, and its canonical text and sha256 (identical to selectionCanonical, tourContract.ts:418-424).
 */
class LabTourSelections
{
    public const PRESETS = ['golden-hour', 'twilight', 'grass', 'declutter'];
    public const CANONICAL_VERSION = 'tour-selection/1';

    /**
     * Stored state of a new analysis (7.1): every preset at revision 0 with an empty changes object. The array cast
     * decodes {} as [], so the empty map is written as an object explicitly.
     */
    public static function initialState(): array
    {
        return array_fill_keys(self::PRESETS, ['revision' => 0, 'changes' => new \stdClass]);
    }

    /**
     * Port of effectiveSelection (tourContract.ts:381-401) over contract-shaped captures: for each surveyed capture in
     * the given order, the executable default candidates of the preset, then the stored entry (remove, then add).
     * Captures left without a tick are omitted. Keys are lowercase caseIds in capture order; IDs follow candidate order.
     *
     * @param iterable<array{caseId:string,status:string,candidates:?array}> $captures
     * @param iterable<array{caseId:string,add:array,remove:array}>|array<string, array{add:array,remove:array}> $changes
     *        a contract list of changes, or the stored map keyed by caseId
     * @return array<string, list<string>>
     */
    public static function effectiveOf(iterable $captures, string $preset, iterable $changes): array
    {
        $entries = [];
        foreach ($changes as $key => $change) {
            $caseId = strtolower((string) (is_string($key) ? $key : ($change['caseId'] ?? '')));
            $entries[$caseId] = ['add' => array_values($change['add'] ?? []), 'remove' => array_values($change['remove'] ?? [])];
        }
        $out = [];
        foreach ($captures as $capture) {
            if (($capture['status'] ?? null) !== 'surveyed') continue;
            $caseId = strtolower((string) $capture['caseId']);
            $own = array_values(array_filter($capture['candidates'] ?? [], fn ($c) => ($c['preset'] ?? null) === $preset));
            $ticked = [];
            foreach ($own as $candidate) if (($candidate['executable'] ?? false) === true && ($candidate['defaultSelected'] ?? false) === true) $ticked[$candidate['id']] = true;
            if (isset($entries[$caseId])) {
                foreach ($entries[$caseId]['remove'] as $id) unset($ticked[(string) $id]);
                foreach ($entries[$caseId]['add'] as $id) $ticked[(string) $id] = true;
            }
            if (! $ticked) continue;
            $ids = [];
            foreach ($own as $candidate) if (isset($ticked[$candidate['id']])) { $ids[] = (string) $candidate['id']; unset($ticked[$candidate['id']]); }
            // IDs that are not (or no longer) candidates of this capture still count, as in the contract's Set.
            $rest = array_map('strval', array_keys($ticked));
            sort($rest, SORT_STRING);
            $out[$caseId] = array_values(array_merge($ids, $rest));
        }

        return $out;
    }

    /** Canonical text hashed into selectionSha256 (spec 7.4). Captures with no tick are omitted; no trailing newline. */
    public static function canonical(string $preset, array $effective): string
    {
        $rows = [];
        foreach ($effective as $caseId => $ids) {
            if (! $ids) continue;
            $ids = array_map('strval', array_values($ids));
            sort($ids, SORT_STRING);               // never plain sort(): SORT_REGULAR orders all-digit IDs numerically
            $rows[strtolower((string) $caseId)] = implode(',', $ids);
        }
        ksort($rows, SORT_STRING);                 // byte order = JS code-unit order for ASCII
        $lines = [self::CANONICAL_VERSION, 'preset='.$preset];
        foreach ($rows as $caseId => $ids) $lines[] = $caseId.'='.$ids;

        return implode("\n", $lines);
    }

    public static function sha256(string $preset, array $effective): string
    {
        return hash('sha256', self::canonical($preset, $effective));
    }

    /**
     * POST selection (7.2): replace the stored delta of each listed capture, in one lab write transaction, and return
     * the tourSelectionWriteResponse built from the rows just written. $facts (LabTourCapabilities::policyFacts and
     * runnable, read before the transaction) are passed to the live estimate unchanged.
     */
    public function write(IguideLabTourAnalysis $analysis, array $input, array $facts): array
    {
        $preset = $input['preset'] ?? null;
        $base = $input['baseRevision'] ?? null;
        if (! in_array($preset, self::PRESETS, true) || ! is_int($base)) throw LabTourErrors::unprocessable('tour_invalid_type');
        $changes = $this->requestedChanges($input['changes'] ?? null);

        return LockedWrite::run(fn () => DB::transaction(function () use ($analysis, $preset, $base, $changes, $facts) {
            IguideLabProject::whereKey($analysis->project_id)->update(['updated_at' => now()]);
            $fresh = IguideLabTourAnalysis::where('project_id', $analysis->project_id)->findOrFail($analysis->id);
            $selections = $this->stored($fresh);
            $revision = (int) $selections[$preset]['revision'];
            if ($base !== $revision) throw LabTourErrors::public('staleSelection');
            if ($revision >= LabTourRevisions::MAX_REVISION) throw LabTourErrors::public('staleSelection'); // cannot advance (tourContract.ts:48)
            $captures = IguideLabTourCapture::where('project_id', $fresh->project_id)->where('analysis_id', $fresh->id)->get()->keyBy('case_id');
            $entries = $selections[$preset]['changes'];
            foreach ($changes as $change) {
                $capture = $captures->get($change['caseId']);
                if (! $capture || $capture->status !== 'surveyed') throw LabTourErrors::unprocessable('tour_capture_not_surveyed');
                $own = $this->own($capture, $preset);
                foreach ($change['add'] as $id) {
                    $candidate = $own[$id] ?? null;
                    if (! $candidate || ($candidate['executable'] ?? false) !== true || ($candidate['defaultSelected'] ?? false) === true) {
                        throw LabTourErrors::unprocessable('tour_candidate_not_selectable');
                    }
                }
                foreach ($change['remove'] as $id) {
                    if (($own[$id]['defaultSelected'] ?? false) !== true) throw LabTourErrors::unprocessable('tour_candidate_not_default');
                }
                if ($this->splitsGroup($own, $change['add'], $change['remove'])) throw LabTourErrors::unprocessable('tour_group_split');
                // Writes replace per capture (README decision 1); an empty entry resets the capture to its defaults.
                if ($change['add'] === [] && $change['remove'] === []) unset($entries[$change['caseId']]);
                else $entries[$change['caseId']] = ['add' => $change['add'], 'remove' => $change['remove']];
            }
            $selections[$preset] = ['revision' => $revision + 1, 'changes' => $entries]; // every accepted write counts
            $this->save($fresh->id, $selections);
            LabTourRevisions::bump($fresh->id);

            return $this->responseDto(LabTourView::load($fresh->project_id, $fresh->id), $preset, $facts);
        }), 'iguide-lab.tour-selection');
    }

    /**
     * Effective selection of a loaded snapshot (7.3): lowercase caseId => ticked candidate IDs, in capture order.
     *
     * @return array<string, list<string>>
     */
    public function effective(LabTourView $view, string $preset): array
    {
        $captures = $view->captures->sortBy('position')->map(fn ($c) => ['caseId' => $c->case_id, 'status' => $c->status, 'candidates' => $c->candidates ?? []]);
        $changes = $view->analysis->selections[$preset]['changes'] ?? [];

        return self::effectiveOf($captures, $preset, is_array($changes) ? $changes : []);
    }

    /** {revision, sha256, changes} (7.6); changes are the stored entries in capture order. The sha is computed now. */
    public function stateDto(LabTourView $view, string $preset): array
    {
        $state = $view->analysis->selections[$preset] ?? ['revision' => 0, 'changes' => []];
        $stored = is_array($state['changes'] ?? null) ? $state['changes'] : [];
        $changes = [];
        foreach ($view->captures->sortBy('position') as $capture) {
            $entry = $stored[strtolower($capture->case_id)] ?? null;
            if (! is_array($entry)) continue;
            $add = array_values($entry['add'] ?? []);
            $remove = array_values($entry['remove'] ?? []);
            if ($add === [] && $remove === []) continue; // never emitted empty (tourContract.ts:243)
            $changes[] = ['caseId' => $capture->case_id, 'add' => $add, 'remove' => $remove];
        }

        return ['revision' => (int) ($state['revision'] ?? 0), 'sha256' => self::sha256($preset, $this->effective($view, $preset)), 'changes' => array_values($changes)];
    }

    /**
     * tourSelectionWriteResponse (7.6, tourContract.ts:566-574). The estimate (exactly the 8 estimateSchema keys) is
     * computed from the same snapshot, only once the analysis is ready or cancelled.
     */
    public function responseDto(LabTourView $view, string $preset, array $facts): array
    {
        $effective = $this->effective($view, $preset);
        $estimate = in_array($view->analysis->status, ['ready', 'cancelled'], true)
            ? app(LabTourRunCapacity::class)->estimate($view, $preset, $facts)
            : null;

        return ['analysisId' => $view->analysis->id, 'preset' => $preset, 'selection' => $this->stateDto($view, $preset),
            'counts' => ['rooms' => count($effective), 'items' => array_sum(array_map('count', $effective))], 'estimate' => $estimate];
    }

    /**
     * 7.5, inside the caller's lab write transaction after a defaults rebuild of one capture: drop stored IDs that are no
     * longer valid, drop the entry when it becomes empty or would split a group. Selection revisions do not change.
     */
    public function reconcile(IguideLabTourAnalysis $analysis, string $caseId): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Selections are reconciled inside the lab write transaction of the rebuild.');
        $caseId = strtolower($caseId);
        $fresh = IguideLabTourAnalysis::where('project_id', $analysis->project_id)->findOrFail($analysis->id);
        $capture = IguideLabTourCapture::where('project_id', $fresh->project_id)->where('analysis_id', $fresh->id)->where('case_id', $caseId)->first();
        $selections = $this->stored($fresh);
        $changed = false;
        foreach (self::PRESETS as $preset) {
            $entry = $selections[$preset]['changes'][$caseId] ?? null;
            if ($entry === null) continue;
            $own = $capture?->status === 'surveyed' ? $this->own($capture, $preset) : [];
            $add = array_values(array_filter($entry['add'], fn ($id) => ($own[$id]['executable'] ?? false) === true && ($own[$id]['defaultSelected'] ?? false) !== true));
            $remove = array_values(array_filter($entry['remove'], fn ($id) => ($own[$id]['defaultSelected'] ?? false) === true));
            if (($add === [] && $remove === []) || $this->splitsGroup($own, $add, $remove)) {
                unset($selections[$preset]['changes'][$caseId]);
            } else {
                $selections[$preset]['changes'][$caseId] = ['add' => $add, 'remove' => $remove];
            }
            if (($selections[$preset]['changes'][$caseId] ?? null) !== $entry) $changed = true;
        }
        if ($changed) $this->save($fresh->id, $selections);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Request changes with lowercase caseIds and distinct, string-sorted IDs. A caseId listed twice (case-insensitively)
     * or an ID both added and removed is 422 tour_duplicate_change (also checked by the controller, 10.3 E5).
     *
     * @return list<array{caseId:string,add:list<string>,remove:list<string>}>
     */
    private function requestedChanges(mixed $changes): array
    {
        if (! is_array($changes) || $changes === [] || ! array_is_list($changes)) throw LabTourErrors::unprocessable('tour_invalid_type');
        $out = [];
        foreach ($changes as $change) {
            if (! is_array($change) || ! is_string($change['caseId'] ?? null) || ! is_array($change['add'] ?? null) || ! is_array($change['remove'] ?? null)) {
                throw LabTourErrors::unprocessable('tour_invalid_type');
            }
            $caseId = strtolower($change['caseId']);
            $add = $this->ids($change['add']);
            $remove = $this->ids($change['remove']);
            if (isset($out[$caseId]) || array_intersect($add, $remove) !== []) throw LabTourErrors::unprocessable('tour_duplicate_change');
            $out[$caseId] = ['caseId' => $caseId, 'add' => $add, 'remove' => $remove];
        }

        return array_values($out);
    }

    /** @return list<string> */
    private function ids(array $ids): array
    {
        foreach ($ids as $id) if (! is_string($id)) throw LabTourErrors::unprocessable('tour_invalid_type');
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);

        return array_values($ids);
    }

    /** The capture's candidates of one preset, keyed by ID. */
    private function own(IguideLabTourCapture $capture, string $preset): array
    {
        $own = [];
        foreach (is_array($capture->candidates) ? $capture->candidates : [] as $candidate) {
            if (($candidate['preset'] ?? null) === $preset && is_string($candidate['id'] ?? null)) $own[$candidate['id']] = $candidate;
        }

        return $own;
    }

    /** True when ticked = (defaults - remove) + add holds some but not all members of a group (tourContract.ts:484-486). */
    private function splitsGroup(array $own, array $add, array $remove): bool
    {
        $ticked = [];
        foreach ($own as $id => $c) if (($c['executable'] ?? false) === true && ($c['defaultSelected'] ?? false) === true) $ticked[(string) $id] = true;
        foreach ($remove as $id) unset($ticked[$id]);
        foreach ($add as $id) $ticked[$id] = true;
        $groups = [];
        foreach ($own as $id => $c) if (is_string($c['groupId'] ?? null)) $groups[$c['groupId']][] = isset($ticked[(string) $id]);
        foreach ($groups as $flags) if (in_array(true, $flags, true) && in_array(false, $flags, true)) return true;

        return false;
    }

    /** Stored selections with all four presets present and changes as caseId-keyed arrays. */
    private function stored(IguideLabTourAnalysis $analysis): array
    {
        $selections = is_array($analysis->selections) ? $analysis->selections : [];
        $out = [];
        foreach (self::PRESETS as $preset) {
            $state = is_array($selections[$preset] ?? null) ? $selections[$preset] : [];
            $changes = [];
            foreach (is_array($state['changes'] ?? null) ? $state['changes'] : [] as $caseId => $entry) {
                if (is_string($caseId) && is_array($entry)) {
                    $changes[strtolower($caseId)] = ['add' => array_values($entry['add'] ?? []), 'remove' => array_values($entry['remove'] ?? [])];
                }
            }
            $out[$preset] = ['revision' => (int) ($state['revision'] ?? 0), 'changes' => $changes];
        }

        return $out;
    }

    /** Content write of the selections column; the caller bumps the record revision (10.6). */
    private function save(string $analysisId, array $selections): void
    {
        foreach ($selections as $preset => $state) {
            if ($state['changes'] === []) $selections[$preset]['changes'] = new \stdClass; // {} in storage, never []
        }
        IguideLabTourAnalysis::whereKey($analysisId)->toBase()->update(['selections' => json_encode($selections, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
    }
}
