<?php

namespace App\Console\Commands;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\Shoots\Actions\AutoStackRawFilesAction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class RestackShootRawFiles extends Command
{
    protected $signature = 'shoots:restack-raw
        {shoot : Shoot ID to re-stack pending raw files for}
        {--bracket= : Force a fixed bracket size (skips auto-detection)}
        {--no-update-bracket-mode : Do not write the detected bracket size back to the shoot}
        {--dry-run : Preview the new bracket_group/sequence assignments without writing}';

    protected $description = 'Re-cluster pending raw files into bracket groups using EXIF captured_at proximity. Use --bracket=N to fall back to fixed-size grouping when EXIF data is missing.';

    public function handle(AutoStackRawFilesAction $autoStack): int
    {
        $shootId = (int) $this->argument('shoot');
        $shoot = Shoot::find($shootId);
        if (!$shoot) {
            $this->error("Shoot {$shootId} not found.");
            return self::FAILURE;
        }

        $forcedBracket = $this->option('bracket') !== null ? (int) $this->option('bracket') : null;
        $useAuto = $forcedBracket === null;
        $dryRun = (bool) $this->option('dry-run');
        $updateBracketMode = !$this->option('no-update-bracket-mode');

        if ($useAuto && !$dryRun) {
            $result = $autoStack->execute($shoot, $updateBracketMode);
            $this->info(sprintf(
                'Auto-stacked shoot #%d: %d files → %d groups (detected bracket=%s, %d files updated).',
                $shootId,
                $result['files'] ?? 0,
                $result['groups'] ?? 0,
                $result['detected_bracket_mode'] ?? 'n/a',
                $result['updated_files'] ?? 0,
            ));
            return self::SUCCESS;
        }

        // Fixed-bracket fallback (or dry-run preview using fixed bracket mode).
        $bracket = $forcedBracket !== null
            ? $forcedBracket
            : (int) ($shoot->bracket_mode ?? 0);
        if ($bracket < 2) {
            $this->error('Auto-detect requires --dry-run to be combined with --bracket=N because preview cannot mutate state. Either drop --dry-run or pass --bracket=N.');
            return self::INVALID;
        }

        /** @var Collection<int, ShootFile> $files */
        $files = $shoot->files()
            ->where('workflow_stage', ShootFile::STAGE_TODO)
            ->where('media_type', 'raw')
            ->get();

        if ($files->isEmpty()) {
            $this->warn('No pending raw files found for this shoot.');
            return self::SUCCESS;
        }

        $ordered = $files->sortBy([
            fn (ShootFile $a, ShootFile $b) => $this->compareCapturedAt($a, $b),
            fn (ShootFile $a, ShootFile $b) => $this->compareFilename($a, $b),
            fn (ShootFile $a, ShootFile $b) => (int) $a->id - (int) $b->id,
        ])->values();

        $dryRun = (bool) $this->option('dry-run');
        $this->info(sprintf(
            '%s %d raw files for shoot #%d (bracket_mode=%d) → %d stacks.',
            $dryRun ? 'Would re-stack' : 'Re-stacking',
            $ordered->count(),
            $shootId,
            $bracket,
            (int) ceil($ordered->count() / $bracket),
        ));

        $rows = [];
        $changes = 0;
        foreach ($ordered as $index => $file) {
            $bracketGroup = intdiv($index, $bracket) + 1;
            $sequence = ($index % $bracket) + 1;
            $changed = ((int) $file->bracket_group !== $bracketGroup)
                || ((int) $file->sequence !== $sequence);

            if ($changed) {
                $changes++;
                if (!$dryRun) {
                    $file->forceFill([
                        'bracket_group' => $bracketGroup,
                        'sequence' => $sequence,
                    ])->save();
                }
            }

            $rows[] = [
                $file->id,
                $file->filename,
                optional($file->captured_at)->format('Y-m-d H:i:s') ?? '-',
                $file->bracket_group ?? '-',
                $file->sequence ?? '-',
                $bracketGroup,
                $sequence,
                $changed ? ($dryRun ? 'WOULD UPDATE' : 'UPDATED') : '—',
            ];
        }

        $this->table(
            ['ID', 'Filename', 'Captured At', 'Was Group', 'Was Seq', 'New Group', 'New Seq', 'Status'],
            $rows,
        );

        $this->info(sprintf(
            '%s %d/%d files.',
            $dryRun ? 'Would update' : 'Updated',
            $changes,
            $ordered->count(),
        ));

        return self::SUCCESS;
    }

    private function compareCapturedAt(ShootFile $a, ShootFile $b): int
    {
        $aTime = $a->captured_at?->getTimestamp();
        $bTime = $b->captured_at?->getTimestamp();
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

    private function compareFilename(ShootFile $a, ShootFile $b): int
    {
        return strnatcmp((string) $a->filename, (string) $b->filename);
    }
}
