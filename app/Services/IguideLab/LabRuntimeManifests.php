<?php

namespace App\Services\IguideLab;

use App\Models\IguideLabRun;
use App\Models\IguideLabTourRun;
use App\Models\IguideLabWorkflow;
use App\Models\IguideLabWorkflowItem;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * iguide-lab:runtime-manifests --write / --restore (D2 tooling; --check stays in LabRuntimeManifestCheck).
 *
 * --write rebuilds each manifest from its current file: only the code pins its owner's validator compares with the
 * owner's own snapshot()/implementation() are replaced. Python, library and executable pins, versions and every other
 * field are kept, so a manifest whose runtime pins are wrong stays invalid. A manifest whose rebuilt content equals the
 * current content is not touched. Every changed candidate is staged beside its manifest with the manifest's owner, group
 * and mode, then all owners are checked together with the candidates in place (the same probes as --check). Nothing is
 * backed up or swapped unless every changed manifest passes and no manifest that passed before fails. Every in-scope
 * manifest is then copied to "<manifest>.bak-<set>" and each candidate is renamed over its manifest (atomic per file).
 *
 * --restore=<set> puts the "<manifest>.bak-<set>" files back the same way (staged, then renamed), keeping what it
 * replaced as a new set so the restore itself can be undone. Both refuse while any lab workflow, item, run or tour run is
 * in flight: reserved work pins the manifest hash it started with. Run this as the manifests' owner (the worker user).
 */
class LabRuntimeManifests
{
    /** A backup set id: UTC time plus a random suffix, e.g. 20261117T013000Z-abc123. */
    public const SET = '/\A\d{8}T\d{6}Z-[a-z0-9]{6}\z/D';

    private const MAX_BYTES = 65536;

    /** The code pins LabWorkflowArtifacts::runtime() compares, as snapshot() keys (manifest key = "validated" + Key). */
    private const LIGHTING = ['workerSha256', 'lightingSha256', 'globalLightingSha256', 'geometrySha256', 'sourceAdapterSha256', 'preparerSha256'];

    /** Owners whose ready() compares every code hash of snapshot() as "validated" + Key. */
    private const VALIDATED = ['staging_assessment', 'staging_support', 'grass_assessment', 'grass_source_admission', 'declutter_removal',
        'declutter_support', 'lifestyle_assessment', 'lifestyle_support'];

    /** Owners whose validator compares the manifest's "implementation" with implementation(). */
    private const IMPLEMENTATION = ['staging_framing', 'lifestyle_framing', 'interior_design', 'interior_room', 'interior_room_support', 'lifestyle_optical'];

    /** @var list<string> staged files to remove unless they were swapped into place or kept as a backup */
    private array $staged = [];

    public function __construct(private LabRuntimeManifestCheck $check) {}

    /**
     * @param  list<string>  $keys  manifest keys in scope, in LabRuntimeManifestCheck::OWNERS order
     * @return array{0: bool, 1: list<string>} ok, output lines
     */
    public function write(array $keys, bool $dryRun): array
    {
        if ($refusal = $this->busy()) return [false, [$refusal]];
        $set = $this->newSet();
        $plan = $lines = [];
        try {
            foreach ($keys as $key) {
                $path = $this->path($key);
                if ($path === null) {
                    $lines[$key] = ['manifest' => $key, 'action' => 'not_configured'];
                    continue;
                }
                if ($key === 'lighting_d2' && ! LabLightingD2::enabled()) {
                    // A disabled D2 is never validated (--check reports it not applicable), so it is not rebuilt either.
                    $lines[$key] = ['manifest' => $key, 'action' => 'skipped', 'reason' => 'd2_disabled', 'path' => $path];
                    continue;
                }
                $refuse = fn (string $reason) => [false, [...$this->json($lines), "Refused: {$key} cannot be rebuilt: {$reason}. Nothing was written."]];
                try {
                    [$current, $raw, $reference] = $this->read($path);
                } catch (RuntimeException $error) {
                    return $refuse($error->getMessage());
                }
                try {
                    $pins = $this->pins($key);
                    $candidate = array_replace($current, $pins);
                    $bytes = $candidate === $current ? null : $this->encode($raw, $pins);
                } catch (\Throwable $error) {
                    // Owner code failed: report the class only, as --check does (a message may hold paths or provider text).
                    return $refuse('computing its code pins failed ('.$error::class.')');
                }
                if ($bytes !== null && strlen($bytes) > self::MAX_BYTES) return $refuse('the rebuilt manifest would be larger than '.self::MAX_BYTES.' bytes');
                if ($bytes === null) {
                    $lines[$key] = ['manifest' => $key, 'action' => 'unchanged', 'path' => $path, 'sha256' => hash('sha256', $raw)];
                    continue;
                }
                $plan[$key] = ['path' => $path, 'bytes' => $bytes, 'reference' => $reference];
                $lines[$key] = ['manifest' => $key, 'action' => 'write', 'path' => $path, 'fromSha256' => hash('sha256', $raw), 'toSha256' => hash('sha256', $bytes),
                    'changedKeys' => array_keys(array_filter($candidate, fn ($value, $name) => ! array_key_exists($name, $current) || $current[$name] !== $value, ARRAY_FILTER_USE_BOTH)),
                    'check' => null, 'error' => null];
            }
            if ($plan === []) return [true, [...$this->json($lines), 'Nothing to write: every manifest in scope is current.']];

            try {
                foreach ($plan as $key => $entry) $plan[$key]['temp'] = $this->stage($this->temp($entry['path'], $set), $entry['bytes'], $entry['reference'], $key);
            } catch (RuntimeException $error) {
                return [false, [...$this->json($lines), 'Refused: '.$error->getMessage().' Nothing was written.']];
            }
            $before = $this->check->checks();
            $after = $this->withPaths(array_map(fn (array $entry) => $entry['temp'], $plan), fn () => $this->check->checks());
            foreach ($plan as $key => $entry) $lines[$key] = array_merge($lines[$key], $after[$key]);
            $failed = array_keys(array_filter($plan, fn (array $entry, string $key) => $after[$key]['check'] !== 'pass', ARRAY_FILTER_USE_BOTH));
            $regressed = array_keys(array_filter($before, fn (array $check, string $key) => $check['check'] === 'pass' && $after[$key]['check'] !== 'pass', ARRAY_FILTER_USE_BOTH));
            $out = $this->json($lines);
            if ($failed !== []) return [false, [...$out, 'Refused: a rebuilt manifest does not pass its owner\'s check ('.implode(', ', $failed).'); nothing was written.']];
            if ($regressed !== []) return [false, [...$out, 'Refused: the rebuilt manifests would make another manifest fail its check ('.implode(', ', $regressed).'); nothing was written.']];
            if ($dryRun) return [true, [...$out, 'Dry run: nothing was written.']];

            $ok = $this->apply($plan, $keys, $set, $out);
            if ($ok) {
                $final = $this->check->checks();
                $bad = array_keys(array_filter($plan, fn (array $entry, string $key) => $final[$key]['check'] !== 'pass', ARRAY_FILTER_USE_BOTH));
                $ok = $bad === [];
                $out[] = $ok ? 'Wrote '.count($plan).' manifest(s); each passes its check.'
                    : 'Wrote '.count($plan).' manifest(s), but after the swap these fail their check: '.implode(', ', $bad).'. Use the restore lines above.';
            }

            return [$ok, $out];
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * @param  list<string>  $keys  manifest keys in scope, in LabRuntimeManifestCheck::OWNERS order
     * @return array{0: bool, 1: list<string>} ok, output lines
     */
    public function restore(string $set, array $keys): array
    {
        if (preg_match(self::SET, $set) !== 1) return [false, ['Refused: a backup set looks like 20261117T013000Z-abc123 (printed by --write as "Backup set: ...").']];
        if ($refusal = $this->busy()) return [false, [$refusal]];
        $undo = $this->newSet();
        $plan = $lines = [];
        try {
            foreach ($keys as $key) {
                $path = $this->path($key);
                $backup = $path === null ? null : $path.'.bak-'.$set;
                if ($backup === null || ! $this->regular($backup)) continue;
                try {
                    $raw = $this->bytes($backup);
                    $current = $this->regular($path) ? $this->bytes($path) : null;
                    if ($current === null && (is_link($path) || file_exists($path))) throw new RuntimeException('the manifest is not a regular file');
                    $reference = $this->reference($current === null ? $backup : $path);
                } catch (RuntimeException $error) {
                    return [false, [...$this->json($lines), "Refused: {$key} cannot be restored: {$error->getMessage()}. Nothing was written."]];
                }
                if ($current === $raw) {
                    $lines[$key] = ['manifest' => $key, 'action' => 'unchanged', 'path' => $path, 'sha256' => hash('sha256', $raw)];
                    continue;
                }
                $plan[$key] = ['path' => $path, 'bytes' => $raw, 'reference' => $reference];
                $lines[$key] = ['manifest' => $key, 'action' => 'restore', 'path' => $path, 'backup' => $backup,
                    'fromSha256' => $current === null ? null : hash('sha256', $current), 'toSha256' => hash('sha256', $raw)];
            }
            if ($lines === []) return [false, ["Refused: no backup set {$set} beside the manifests in scope."]];
            $out = $this->json($lines);
            if ($plan === []) return [true, [...$out, "Nothing to restore: the manifests already match backup set {$set}."]];
            try {
                foreach ($plan as $key => $entry) $plan[$key]['temp'] = $this->stage($this->temp($entry['path'], $undo), $entry['bytes'], $entry['reference'], $key);
            } catch (RuntimeException $error) {
                return [false, [...$out, 'Refused: '.$error->getMessage().' Nothing was written.']];
            }
            $ok = $this->apply($plan, $keys, $undo, $out);
            if ($ok) {
                $checks = $this->check->checks();
                foreach (array_keys($plan) as $key) $out[] = "Restored {$key} from backup set {$set}; its check is now {$checks[$key]['check']}.";
            }

            return [$ok, $out];
        } finally {
            $this->cleanUp();
        }
    }

    /** @return array<string, int> lab work that pinned the current manifests when it started */
    public function inFlight(): array
    {
        return ['workflows' => IguideLabWorkflow::whereNotIn('status', LabWorkflows::TERMINAL)->count(),
            'workflowItems' => IguideLabWorkflowItem::whereNotIn('status', LabWorkflows::TERMINAL)->count(),
            'runs' => IguideLabRun::whereNotIn('status', ['completed', 'failed'])->count(),
            'tourRuns' => IguideLabTourRun::whereIn('status', LabTourRuns::ACTIVE)->count()];
    }

    /**
     * The code pins the owner's validator expects for the current code, as manifest key => value. Never a Python,
     * library or executable pin.
     *
     * @return array<string, mixed>
     */
    public function pins(string $key): array
    {
        $owner = LabRuntimeManifestCheck::OWNERS[$key] ?? throw new RuntimeException('unknown manifest '.$key);
        if (in_array($key, self::VALIDATED, true)) return $this->validated(app($owner)->snapshot());
        if (in_array($key, self::IMPLEMENTATION, true)) return ['implementation' => app($owner)->implementation()];

        return match ($key) {
            'workflow' => $this->validated(app(LabWorkflowArtifacts::class)->snapshot(), self::LIGHTING),
            'grass' => (function () {
                $snapshot = app(LabGrassWorkflowArtifacts::class)->snapshot();

                return $this->validated($snapshot, [...self::LIGHTING, 'grassCoreSha256', 'grassWorkerSha256'])
                    + ['validatedArtifactAdapterSha256' => $snapshot['adapterSha256']];
            })(),
            'lighting_d2' => (fn (array $snapshot) => ['authoritySha256' => $snapshot['authoritySha256'], 'files' => $snapshot['files']])(LabLightingD2::snapshot()),
            // LabHarmonization::snapshot() needs a valid manifest first, so its two pinned files are hashed as runtime() does.
            'harmonization' => ['validatedSolverSha256' => hash_file('sha256', resource_path('iguide-lab/section_harmonization.py')),
                'validatedAdapterSha256' => hash_file('sha256', resource_path('iguide-lab/harmonize_section.py'))],
            'staging_layout' => ['files' => app(LabStagingLayouts::class)->implementation()],
            'interior_material' => ['files' => app(LabInteriorDesignMaterials::class)->snapshot()['files']],
        };
    }

    /** @param list<string>|null $only snapshot keys to pin; null = every code hash except the manifest's own */
    private function validated(array $snapshot, ?array $only = null): array
    {
        $pins = [];
        foreach ($snapshot as $name => $value) {
            if (! str_ends_with($name, 'Sha256') || $name === 'runtimeManifestSha256' || ($only !== null && ! in_array($name, $only, true))) continue;
            $pins['validated'.ucfirst($name)] = $value;
        }

        return $pins;
    }

    /**
     * Back up every in-scope manifest, then rename each staged candidate over its manifest. Rolls back on a failed swap.
     *
     * @param  array<string, array{path: string, bytes: string, reference: array, temp: string}>  $plan
     * @param  list<string>  $out
     */
    private function apply(array $plan, array $keys, string $set, array &$out): bool
    {
        // Work may have started while the candidates were validated.
        if ($refusal = $this->busy()) {
            $out[] = $refusal;

            return false;
        }
        $backups = [];
        try {
            foreach ($keys as $key) {
                $path = $this->path($key);
                if ($path === null || ! $this->regular($path)) continue;
                $backups[$key] = $this->stage($path.'.bak-'.$set, $this->bytes($path), $this->reference($path), $key);
            }
        } catch (RuntimeException $error) {
            $out[] = 'Refused: could not back up the manifests: '.$error->getMessage().' Nothing was written.';

            return false;
        }
        $this->staged = array_values(array_diff($this->staged, $backups));
        $restoreLines = ['Backup set: '.$set, 'Restore with: php artisan iguide-lab:runtime-manifests --restore='.$set];
        foreach ($backups as $key => $backup) $restoreLines[] = "restore {$key}: cp -p ".escapeshellarg($backup).' '.escapeshellarg($this->path($key));

        $swapped = [];
        foreach ($plan as $key => $entry) {
            if (@rename($entry['temp'], $entry['path'])) {
                $swapped[] = $key;
                continue;
            }
            $out[] = "Failed: could not move the new {$key} manifest into place.";
            foreach ($swapped as $done) {
                if (! isset($backups[$done])) {
                    $out[] = "Could not put {$done} back: it had no file before this run.";
                    continue;
                }
                try {
                    $temp = $this->stage($this->temp($plan[$done]['path'], $set.'-undo'), $this->bytes($backups[$done]), $plan[$done]['reference'], $done);
                    $out[] = @rename($temp, $plan[$done]['path']) ? "Put {$done} back from its backup." : "Could not put {$done} back; use its restore line below.";
                } catch (RuntimeException) {
                    $out[] = "Could not put {$done} back; use its restore line below.";
                }
            }
            array_push($out, ...$restoreLines);

            return false;
        }
        array_push($out, ...$restoreLines);

        return true;
    }

    /** Create $target (it must not exist) with $bytes, the reference's mode and group; refuse if owner, group or mode differ. */
    private function stage(string $target, string $bytes, array $reference, string $key): string
    {
        $mask = umask(0077);
        try {
            $handle = @fopen($target, 'xb');
        } finally {
            umask($mask);
        }
        if ($handle === false) throw new RuntimeException("{$key}: could not create ".basename($target).' beside the manifest.');
        $this->staged[] = $target;
        try {
            $written = @fwrite($handle, $bytes) === strlen($bytes) && fflush($handle) && @fsync($handle);
        } finally {
            fclose($handle);
        }
        @chmod($target, $reference['mode']);
        clearstatcache(true, $target);
        if (@filegroup($target) !== $reference['gid']) {
            @chgrp($target, $reference['gid']);
            clearstatcache(true, $target);
        }
        $actual = $this->reference($target);
        if ($actual !== $reference) {
            throw new RuntimeException(sprintf('%s would be written as uid %d gid %d mode %04o, but the manifest is uid %d gid %d mode %04o; run this as the manifest owner.',
                $key, $actual['uid'], $actual['gid'], $actual['mode'], $reference['uid'], $reference['gid'], $reference['mode']));
        }
        if (! $written || ! hash_equals(hash('sha256', $bytes), (string) @hash_file('sha256', $target))) throw new RuntimeException("{$key}: could not write ".basename($target).' completely.');

        return $target;
    }

    /** @return array{0: array<string, mixed>, 1: string, 2: array{uid: int, gid: int, mode: int}} decoded, raw bytes, owner/group/mode */
    private function read(string $path): array
    {
        if (! $this->regular($path)) throw new RuntimeException('the manifest is missing or not a regular file');
        $raw = $this->bytes($path);
        try {
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('the manifest is not valid JSON');
        }
        if (! is_array($value) || ($value !== [] && array_is_list($value))) throw new RuntimeException('the manifest is not a JSON object');

        return [$value, $raw, $this->reference($path)];
    }

    private function bytes(string $path): string
    {
        clearstatcache(true, $path);
        $size = @filesize($path);
        $raw = $size !== false && $size <= self::MAX_BYTES ? @file_get_contents($path) : false;
        if (! is_string($raw)) throw new RuntimeException('it cannot be read or is larger than '.self::MAX_BYTES.' bytes');

        return $raw;
    }

    /**
     * Set the pins on the current manifest decoded as objects (so "{}" stays an object), keeping its layout (pretty or
     * compact, escaped slashes, trailing newline): only the pins change. An existing pin keeps its position.
     */
    private function encode(string $raw, array $pins): string
    {
        $value = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        foreach ($pins as $name => $pin) $value->{$name} = $pin;
        $flags = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;
        if (! str_contains($raw, '\/')) $flags |= JSON_UNESCAPED_SLASHES;
        if (str_contains(rtrim($raw), "\n")) $flags |= JSON_PRETTY_PRINT;
        return json_encode($value, $flags).(str_ends_with($raw, "\n") ? "\n" : '');
    }

    /** @return array{uid: int, gid: int, mode: int} */
    private function reference(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if (! is_array($stat)) throw new RuntimeException('cannot read the owner and mode of '.basename($path));

        return ['uid' => $stat['uid'], 'gid' => $stat['gid'], 'mode' => $stat['mode'] & 07777];
    }

    /** Run $callback with the given manifest keys pointing at other files; the configuration is always put back. */
    private function withPaths(array $paths, callable $callback): mixed
    {
        $saved = [];
        foreach (array_keys($paths) as $key) $saved['iguide-lab.'.$key.'_runtime_manifest'] = config('iguide-lab.'.$key.'_runtime_manifest');
        try {
            foreach ($paths as $key => $path) config(['iguide-lab.'.$key.'_runtime_manifest' => $path]);

            return $callback();
        } finally {
            config($saved);
        }
    }

    private function busy(): ?string
    {
        $counts = $this->inFlight();
        if (array_sum($counts) === 0) return null;
        $parts = [];
        foreach ($counts as $name => $count) $parts[] = $name.'='.$count;

        return 'Refused: lab work is in flight ('.implode(', ', $parts).'). Wait until it finishes, then run this again. Nothing was written.';
    }

    private function path(string $key): ?string
    {
        $path = config('iguide-lab.'.$key.'_runtime_manifest');

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function regular(string $path): bool
    {
        clearstatcache(true, $path);

        return ! is_link($path) && is_file($path);
    }

    private function temp(string $path, string $set): string
    {
        return dirname($path).'/.'.basename($path).'.'.$set.'.tmp';
    }

    private function newSet(): string
    {
        return gmdate('Ymd\THis\Z').'-'.strtolower(Str::random(6));
    }

    /** @return list<string> */
    private function json(array $lines): array
    {
        return array_values(array_map(fn (array $line) => json_encode($line, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $lines));
    }

    private function cleanUp(): void
    {
        foreach ($this->staged as $file) {
            clearstatcache(true, $file);
            if (is_file($file) && ! is_link($file)) @unlink($file);
        }
        $this->staged = [];
    }
}
