<?php

namespace App\Services\Shoots;

use App\Models\ShootFile;

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
 */
class DeliveryFilenameFormatter
{
    public const MIN_WIDTH = 3;

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
        return $this->format($position, $total, $this->baseNameFor($file, $fallback));
    }

    /**
     * The master name a delivered copy should be derived from.
     */
    public function baseNameFor(ShootFile $file, ?string $fallback = null): string
    {
        foreach ([$file->original_name ?? null, $file->filename, $file->stored_filename, $fallback] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $this->sanitizeBaseName($candidate);
            }
        }

        return 'file-' . (int) $file->id;
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
