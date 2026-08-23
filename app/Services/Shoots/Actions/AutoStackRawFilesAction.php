<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\Shoots\BracketModeResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-detect bracket groups for pending raw files using EXIF captured_at clustering.
 *
 * Strategy:
 *   - Partition pending raw files by shoot_service_id, then run the clustering below
 *     independently inside each partition, restarting bracket_group at 1 each time.
 *   - Sort pending raw files by captured_at (fallback to created_at, then natural filename).
 *   - Walk the list, grouping consecutive files whose captured_at delta is within
 *     {@see self::INTRA_BRACKET_GAP_SECONDS}. Each group becomes one bracket_group with
 *     sequence 1..N.
 *   - Files with no captured_at fall to the end and are grouped by filename only.
 *   - Optionally fill in a service item's own bracket size from the modal group
 *     size detected within that item's partition, and only when the item has no
 *     size recorded. Detection is a guess and never overwrites a decision, and it
 *     is never applied across service boundaries.
 *
 * Partitioning matters because a shoot can book several photo services. Clustering the
 * whole shoot at once let one service absorb another's frames: a photographer moving
 * from Exterior to Interior without a five second pause produced a single stack holding
 * both services' files, so Interior's first frame appeared as Exterior's stack 2 frame 3.
 * Time proximity across a service boundary is not evidence of one bracket.
 */
class AutoStackRawFilesAction
{
    /** Gap (seconds) above which two raw shots are considered in different brackets. */
    public const INTRA_BRACKET_GAP_SECONDS = 5;

    /** Allowed bracket sizes used when inferring a size from observed group sizes. */
    public const BRACKET_SIZE_CANDIDATES = [3, 5, 7];

    /**
     * Re-bracket pending raw files for a shoot, or for one service item of it.
     *
     * `$shootServiceId` narrows the work to a single service so that changing one
     * service's bracket size never touches another photographer's stacks on the
     * same shoot. Passing null processes every partition, which is what a normal
     * upload does.
     *
     * @param  int|null  $shootServiceId  restrict to this service item only
     * @return array{groups: int, files: int, detected_bracket_mode: ?int, updated_files: int}
     */
    public function execute(Shoot $shoot, bool $updateShootBracketMode = true, ?int $shootServiceId = null): array
    {
        return DB::transaction(function () use ($shoot, $updateShootBracketMode, $shootServiceId) {
            /** @var Collection<int, ShootFile> $files */
            $files = $shoot->files()
                ->where('workflow_stage', ShootFile::STAGE_TODO)
                ->where('media_type', 'raw')
                ->when($shootServiceId !== null, fn ($query) => $query->where('shoot_service_id', $shootServiceId))
                ->get();

            if ($files->isEmpty()) {
                return [
                    'groups' => 0,
                    'files' => 0,
                    'detected_bracket_mode' => null,
                    'updated_files' => 0,
                ];
            }

            // Each service item is stacked on its own. Unassigned files (null) form their
            // own partition rather than being folded into any service.
            $partitions = $files->groupBy(fn (ShootFile $file) => (int) ($file->shoot_service_id ?? 0))
                ->sortKeys();

            $allGroups = [];
            $updates = 0;
            /** @var array<int, int|null> $detectedModesByService */
            $detectedModesByService = [];

            foreach ($partitions as $partitionKey => $partitionFiles) {
                $ordered = $partitionFiles->sortBy([
                    fn (ShootFile $a, ShootFile $b) => $this->compareCapturedAt($a, $b),
                    fn (ShootFile $a, ShootFile $b) => strnatcmp((string) $a->filename, (string) $b->filename),
                    fn (ShootFile $a, ShootFile $b) => (int) $a->id - (int) $b->id,
                ])->values();

                $groups = $this->clusterFiles($ordered);

                foreach ($groups as $groupIndex => $groupFiles) {
                    // bracket_group restarts at 1 for every service, so stacks are read
                    // relative to their own service section in the gallery.
                    $bracketGroup = $groupIndex + 1;
                    foreach ($groupFiles as $sequenceIndex => $file) {
                        $sequence = $sequenceIndex + 1;
                        $existingGroup = (int) ($file->bracket_group ?? 0);
                        $existingSequence = (int) ($file->sequence ?? 0);
                        if ($existingGroup === $bracketGroup && $existingSequence === $sequence) {
                            continue;
                        }

                        $file->forceFill([
                            'bracket_group' => $bracketGroup,
                            'sequence' => $sequence,
                        ])->save();
                        $updates++;
                    }
                }

                foreach ($groups as $groupFiles) {
                    $allGroups[] = $groupFiles;
                }

                // Detect within the partition. A size inferred from Exterior's
                // stacks says nothing about how Interior was shot.
                if ((int) $partitionKey > 0) {
                    $detectedModesByService[(int) $partitionKey] = $this->detectBracketMode($groups);
                }
            }

            $detectedMode = $this->detectBracketMode($allGroups);

            // Detection no longer writes shoots.bracket_mode. One photographer's
            // capture pattern must not redefine a shoot-wide divisor that another
            // photographer's service would then be stacked by. Instead each
            // partition's own pattern can fill in that service item's size, and
            // only when it has none: an explicit size is a decision, and detection
            // is a guess, so a guess never overwrites a decision.
            if ($updateShootBracketMode) {
                $this->populateUnsetServiceBracketModes($shoot, $detectedModesByService);
            }

            return [
                'groups' => count($allGroups),
                'files' => $files->count(),
                'detected_bracket_mode' => $detectedMode,
                'updated_files' => $updates,
            ];
        });
    }

    /**
     * Fill in a service item's bracket size from its own detected stack pattern,
     * but only where the item has no size recorded yet.
     *
     * @param  array<int, int|null>  $detectedModesByService
     */
    private function populateUnsetServiceBracketModes(Shoot $shoot, array $detectedModesByService): void
    {
        if (empty($detectedModesByService) || ! Schema::hasColumn('shoot_service', 'bracket_mode')) {
            return;
        }

        $resolver = app(BracketModeResolver::class);

        $items = $shoot->serviceItems()
            ->with('service')
            ->whereIn('id', array_keys($detectedModesByService))
            ->get();

        foreach ($items as $item) {
            $detected = $resolver->normalize($detectedModesByService[$item->id] ?? null);

            if ($detected === null) {
                continue;
            }

            // Never override a recorded decision, and never give a size to work
            // that does not bracket.
            if ($item->bracket_mode !== null || ! $resolver->serviceUsesBrackets($item)) {
                continue;
            }

            $item->bracket_mode = $detected;
            $item->save();
        }
    }

    /**
     * Group files into clusters based on consecutive captured_at proximity.
     *
     * @param  Collection<int, ShootFile>  $orderedFiles
     * @return array<int, array<int, ShootFile>>
     */
    private function clusterFiles(Collection $orderedFiles): array
    {
        $groups = [];
        $currentGroup = [];
        $previousTimestamp = null;

        foreach ($orderedFiles as $file) {
            $timestamp = $this->capturedAtTimestamp($file);

            $startNewGroup = empty($currentGroup);
            if (!$startNewGroup) {
                if ($previousTimestamp === null || $timestamp === null) {
                    $startNewGroup = true;
                } else {
                    $delta = abs($timestamp - $previousTimestamp);
                    $startNewGroup = $delta > self::INTRA_BRACKET_GAP_SECONDS;
                }
            }

            if ($startNewGroup && !empty($currentGroup)) {
                $groups[] = $currentGroup;
                $currentGroup = [];
            }

            $currentGroup[] = $file;
            $previousTimestamp = $timestamp;
        }

        if (!empty($currentGroup)) {
            $groups[] = $currentGroup;
        }

        return $groups;
    }

    /**
     * Pick the modal group size from {@see self::BRACKET_SIZE_CANDIDATES}, falling back
     * to the most common size if no candidate matches.
     *
     * @param  array<int, array<int, ShootFile>>  $groups
     */
    private function detectBracketMode(array $groups): ?int
    {
        if (empty($groups)) {
            return null;
        }

        $sizeCounts = [];
        foreach ($groups as $group) {
            $size = count($group);
            if ($size <= 1) {
                continue;
            }
            $sizeCounts[$size] = ($sizeCounts[$size] ?? 0) + 1;
        }

        if (empty($sizeCounts)) {
            return null;
        }

        // Prefer the canonical bracket size with the highest count (ties broken
        // toward the larger candidate), then fall back to whatever size dominates.
        $best = null;
        $bestCount = 0;
        foreach (self::BRACKET_SIZE_CANDIDATES as $candidate) {
            $count = $sizeCounts[$candidate] ?? 0;
            if ($count > $bestCount || ($count === $bestCount && $candidate > ($best ?? 0))) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        if ($best !== null && $bestCount > 0) {
            return $best;
        }

        arsort($sizeCounts);
        return (int) array_key_first($sizeCounts);
    }

    private function compareCapturedAt(ShootFile $a, ShootFile $b): int
    {
        $aTime = $this->capturedAtTimestamp($a);
        $bTime = $this->capturedAtTimestamp($b);
        if ($aTime !== null && $bTime !== null) {
            return $aTime <=> $bTime;
        }
        if ($aTime !== null) {
            return -1;
        }
        if ($bTime !== null) {
            return 1;
        }
        return 0;
    }

    private function capturedAtTimestamp(ShootFile $file): ?int
    {
        $metadata = is_array($file->metadata) ? $file->metadata : [];
        $candidate = $metadata['captured_at'] ?? null;

        if (is_string($candidate) && $candidate !== '') {
            $normalized = preg_replace('/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', $candidate, 1);
            try {
                return Carbon::parse($normalized)->getTimestamp();
            } catch (\Throwable $exception) {
                // ignore — fall through
            }
        }

        if ($file->created_at) {
            return $file->created_at->getTimestamp();
        }

        return null;
    }
}
