<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use Illuminate\Support\Str;

/**
 * Builds the position-prefixed names used for delivered copies of shoot media.
 *
 * Why prefixing is necessary: a ZIP stores its entries in whatever order we add
 * them, but nothing downstream honors that order. Windows Explorer, macOS
 * Finder and effectively every unzip tool re-sort the extracted files by name,
 * so an archive built in a carefully curated sequence still lands on the
 * client's desktop alphabetically. Encoding the position in the entry name is
 * the only way the delivered order survives extraction.
 *
 * Scope is deliberately narrow: this only ever renames the *copy* handed out in
 * a ZIP entry / download response. `ShootFile::$filename`, `$stored_filename`,
 * `$path` and `$storage_path` are never touched, so previews, reprocessing,
 * Dropbox sync and filename-based upload replacement all keep working against
 * the original master names.
 *
 * Padding matters: unpadded positions sort as 1, 10, 11, 2 — worse than no
 * prefix at all. Width is the digit count of the set size with a floor of 3, so
 * a typical shoot reads 001_, 002_ … and a 1200-image set still pads to 0001_.
 * AI-edited address_counter names (including the older _edited suffix) already
 * carry their sequence and are returned as-is, without a second prefix.
 */
class DeliveryFilenameFormatter
{
    public const MIN_WIDTH = 3;

    /** Longest delivered floor plan name, including a JPG page's "-page-N" suffix. */
    public const FLOORPLAN_MAX_LENGTH = 60;

    private const PAGE_SUFFIX_RESERVE = 8;

    /** @var array<int, Shoot|null> */
    private array $shoots = [];

    /**
     * Zero-padding width for a set of $total files.
     */
    public function width(int $total): int
    {
        return max(self::MIN_WIDTH, strlen((string) max($total, 1)));
    }

    /**
     * @param  int  $position  1-based position within the delivered set.
     */
    public function format(int $position, int $total, string $baseName): string
    {
        $baseName = $this->sanitizeBaseName($baseName);

        return sprintf('%0' . $this->width($total) . 'd_%s', max($position, 1), $baseName);
    }

    /**
     * @param  int  $position  1-based position within the delivered set.
     */
    public function formatForFile(ShootFile $file, int $position, int $total, ?string $fallback = null): string
    {
        $name = $this->baseNameFor($file, $fallback);
        if ($this->hasEditedName($file)) return $name;
        $unit = $file->shoot_service_id ? $file->serviceItem?->unit : null;
        $formatted = $this->format($position, $total, $unit ? 'unit-'.$unit->id.'_'.$this->safeSegment($unit->label).'_'.$name : $name);

        if ($file->media_type === 'floorplan' && strlen($formatted) > self::FLOORPLAN_MAX_LENGTH - self::PAGE_SUFFIX_RESERVE) {
            return $this->shortFloorplanName($file, $name, $unit?->label, $position, $total);
        }

        return $formatted;
    }

    /**
     * MLS uploads reject long names, and CubiCasa names its files after the full
     * geocoded address (county, country, zip), so a delivered copy reached ~115
     * characters. Rebuild it from the shoot's street address instead, leaving
     * room for the "-page-N" suffix added to JPG pages of a PDF floor plan.
     */
    private function shortFloorplanName(ShootFile $file, string $name, ?string $unitLabel, int $position, int $total): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $hint = match (true) {
            stripos($name, 'metric') !== false => '-metric',
            stripos($name, 'imperial') !== false => '-imperial',
            default => '',
        };
        $suffix = ($unitLabel ? '_'.substr(Str::slug($unitLabel), 0, 15) : '')
            .'_floorplan'.$hint.($extension !== '' ? '.'.$extension : '');
        $prefix = sprintf('%0'.$this->width($total).'d_', max($position, 1));
        $budget = max(8, self::FLOORPLAN_MAX_LENGTH - self::PAGE_SUFFIX_RESERVE - strlen($prefix) - strlen($suffix));

        $address = Str::slug((string) $this->shootFor($file)?->address) ?: 'shoot-'.(int) $file->shoot_id;
        if (strlen($address) > $budget) {
            $address = substr($address, 0, $budget);
            $cut = strrpos($address, '-');
            $address = rtrim($cut ? substr($address, 0, $cut) : $address, '-');
        }

        return $prefix.$address.$suffix;
    }

    private function shootFor(ShootFile $file): ?Shoot
    {
        if (! $file->shoot_id) return null;

        return $this->shoots[(int) $file->shoot_id] ??= $file->shoot;
    }

    /** Only archive copies get folders; stored master paths are never moved. */
    public function archivePathForFile(ShootFile $file, int $position, int $total, ?string $fallback = null): string
    {
        $line = $file->shoot_service_id ? $file->serviceItem : null;
        $unit = $line?->unit;
        $name = $this->formatForFile($file, $position, $total, $fallback);
        if (! $unit) return $name;

        return 'unit-'.$unit->id.'-'.$this->safeSegment($unit->label).'/line-'.$line->id.'-'.$this->safeSegment($line->service?->name ?? 'service').'/'.$name;
    }

    private function safeSegment(string $value): string
    {
        return substr(\Illuminate\Support\Str::slug($value), 0, 60) ?: 'unit';
    }

    /**
     * The master name a delivered copy should be derived from.
     */
    public function baseNameFor(ShootFile $file, ?string $fallback = null): string
    {
        if ($this->hasEditedName($file)) return $this->sanitizeBaseName($file->filename);
        foreach ([$file->original_name ?? null, $file->filename, $file->stored_filename, $fallback] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $this->sanitizeBaseName($candidate);
            }
        }

        return 'file-' . (int) $file->id;
    }

    private function hasEditedName(ShootFile $file): bool
    {
        return $file->is_ai_edited && preg_match('/_\d+(?:_edited)?\.jpg$/D', (string) $file->filename) === 1;
    }

    /**
     * Assign unique names to a whole delivered set in one pass.
     *
     * Two masters can legitimately share a filename (same name uploaded against
     * different service items, or a raw/edited pair), and ZipArchive would
     * silently collapse them into one entry. The position prefix already makes
     * collisions rare; the suffix pass closes the remaining case where two files
     * are handed the same position by a partially applied reorder.
     *
     * @param  iterable<int, ShootFile>  $files
     * @return array<int, string> keyed by shoot_file id, in the given order
     */
    public function mapForSet(iterable $files, ?int $total = null): array
    {
        $files = is_array($files) ? $files : iterator_to_array($files);
        $total ??= count($files);

        $names = [];
        $used = [];
        $position = 1;

        foreach ($files as $file) {
            $name = $this->formatForFile($file, $position, $total);
            $names[(int) $file->id] = $this->deduplicate($name, $used);
            $position++;
        }

        return $names;
    }

    /**
     * @param  array<string, true>  $used  running set of taken names (by ref)
     */
    public function deduplicate(string $name, array &$used): string
    {
        $key = strtolower($name);
        if (!isset($used[$key])) {
            $used[$key] = true;

            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $stem = $extension !== '' ? substr($name, 0, -(strlen($extension) + 1)) : $name;

        $suffix = 2;
        do {
            $candidate = $extension !== '' ? "{$stem}-{$suffix}.{$extension}" : "{$stem}-{$suffix}";
            $key = strtolower($candidate);
            $suffix++;
        } while (isset($used[$key]));

        $used[$key] = true;

        return $candidate;
    }

    /**
     * Strip anything that would turn an entry name into a path or break a
     * Content-Disposition header. Never rewrites the stored master name.
     */
    protected function sanitizeBaseName(string $baseName): string
    {
        $baseName = basename(str_replace('\\', '/', trim($baseName)));
        $baseName = preg_replace('/[\r\n"]+/', '', $baseName) ?? '';
        $baseName = ltrim($baseName, '.');

        return $baseName !== '' ? $baseName : 'file';
    }
}
