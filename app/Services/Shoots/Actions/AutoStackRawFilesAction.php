<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\ShootFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
 *   - Optionally update Shoot.bracket_mode to the modal group size when the shoot
 *     currently has no bracket_mode (or 1).
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

    /** Allowed bracket sizes used when inferring shoot.bracket_mode from group sizes. */
    public const BRACKET_SIZE_CANDIDATES = [3, 5, 7];

    /**
     * Re-bracket all pending raw files for a shoot.
     *
     * @return array{groups: int, files: int, detected_bracket_mode: ?int, updated_files: int}
     */
    public function execute(Shoot $shoot, bool $updateShootBracketMode = true): array
    {
        return DB::transaction(function () use ($shoot, $updateShootBracketMode) {
            /** @var Collection<int, ShootFile> $files */
            $files = $shoot->files()
                ->where('workflow_stage', ShootFile::STAGE_TODO)
                ->where('media_type', 'raw')
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

            foreach ($partitions as $partitionFiles) {
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
            }

            // Legacy shoot-wide bracket_mode inference still looks at every stack on the
            // shoot; it is only a fallback hint, not per-service capture state.
            $detectedMode = $this->detectBracketMode($allGroups);

            if ($updateShootBracketMode && $detectedMode !== null) {
                $current = (int) ($shoot->bracket_mode ?? 0);
                if ($current <= 1 && $detectedMode > 1) {
                    $shoot->bracket_mode = $detectedMode;
                    $shoot->save();
                }
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
