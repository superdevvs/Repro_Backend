<?php

namespace App\Services\IguideLab;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Read-only runtime manifest and preset readiness report (D15, spec 11.3).
 *
 * It reads each configured manifest's metadata and hash (never its content), calls each owner's own readiness probe
 * and LabWorkflowPolicy::capabilities(), and writes nothing: no database row, manifest or storage file, and it starts
 * no process itself. The owners' probes behave as in any worker, so LabInteriorDesignMaterials::ready() may still
 * write Python bytecode (spec 11.3, risk 15.1.11). Run it as the worker user (maverick under sg www-data), whose view
 * of the 0640 manifests is the one that counts, and not during a deploy window.
 */
class LabRuntimeManifestCheck
{
    public const VERSION = 'runtime-manifest-check/1';

    /**
     * Manifest key (config "iguide-lab.<key>_runtime_manifest") => owner, in report order. The check per owner is
     * explicit: LabLightingD2 has no ready() and is checked through validateRuntime() when enabled (lightingD2());
     * every other owner, LabHarmonization included (its snapshot() needs a source-cut hash), through its public
     * zero-argument ready().
     */
    public const OWNERS = [
        'workflow' => LabWorkflowArtifacts::class,
        'lighting_d2' => LabLightingD2::class,
        'harmonization' => LabHarmonization::class,
        'staging_assessment' => LabStagingAssessmentBridge::class,
        'staging_support' => LabStagingSupport::class,
        'staging_framing' => LabStagingFramings::class,
        'staging_layout' => LabStagingLayouts::class,
        'grass_assessment' => LabGrassAssessmentBridge::class,
        'grass' => LabGrassWorkflowArtifacts::class,
        'grass_source_admission' => LabGrassSourceAdmissionBridge::class,
        'declutter_removal' => LabDeclutterRemovalBridge::class,
        'declutter_support' => LabDeclutterSupport::class,
        'interior_design' => LabInteriorDesignAssessments::class,
        'interior_material' => LabInteriorDesignMaterials::class,
        'interior_room' => LabInteriorRoomAssessments::class,
        'interior_room_support' => LabRoomFurnishingSupport::class,
        'lifestyle_assessment' => LabLifestyleAssessmentBridge::class,
        'lifestyle_framing' => LabLifestyleFramings::class,
        'lifestyle_optical' => LabLifestyleOpticalDetails::class,
        'lifestyle_support' => LabLifestyleSupport::class,
    ];

    /** Preset (LabCatalog::PRESETS order) => its enabling flag in config/iguide-lab.php; null = always on (LabWorkflowPolicy.php:64-70). */
    public const PRESET_FLAGS = [
        'grass' => 'grass_workflow_enabled',
        'declutter' => 'declutter_workflow_enabled',
        'staging' => 'staging_workflow_enabled',
        'twilight' => null,
        'golden-hour' => null,
        'lifestyle' => 'lifestyle_workflow_enabled',
        'interior-design' => 'interior_design_workflow_enabled',
    ];

    /**
     * ok is true iff no manifest check is "fail" and every enabled preset is ready.
     *
     * @return array{manifests: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function run(): array
    {
        $manifests = [];
        foreach (self::OWNERS as $key => $owner) {
            $manifests[] = $this->manifest($key, $owner);
        }
        [$ready, $capabilitiesError] = $this->recipes();
        $presets = [];
        foreach (self::PRESET_FLAGS as $preset => $flag) {
            $presets[] = ['preset' => $preset, 'flag' => $flag, 'enabled' => $flag === null || config('iguide-lab.'.$flag, false) === true,
                'ready' => ($ready[$preset] ?? false) === true];
        }
        $failed = in_array('fail', array_column($manifests, 'check'), true);
        $blocked = array_filter($presets, fn (array $preset) => $preset['enabled'] && ! $preset['ready']) !== [];

        return ['manifests' => $manifests, 'summary' => [
            'version' => self::VERSION,
            'presetsReady' => count(array_filter($presets, fn (array $preset) => $preset['ready'])),
            'presets' => $presets,
            'flags' => $this->flags(),
            'capabilitiesError' => $capabilitiesError,
            'ok' => ! $failed && ! $blocked,
        ]];
    }

    /**
     * Each manifest's check exactly as run() reports it, without the preset readiness part (used by --write/--restore).
     *
     * @return array<string, array{check: 'pass'|'fail'|'not_applicable', error: ?array{class: ?string, status: ?int}}>
     */
    public function checks(): array
    {
        $checks = [];
        foreach (self::OWNERS as $key => $owner) {
            $line = $this->manifest($key, $owner);
            $checks[$key] = ['check' => $line['check'], 'error' => $line['error']];
        }

        return $checks;
    }

    /** @return array<string, mixed> one report line */
    private function manifest(string $key, string $owner): array
    {
        $path = config('iguide-lab.'.$key.'_runtime_manifest');
        $configured = is_string($path) && $path !== '';
        $file = $configured ? $this->file($path)
            : ['exists' => false, 'symlink' => false, 'readable' => false, 'bytes' => null, 'sha256' => null, 'posix' => null];
        // An unconfigured manifest is not applicable (a pass). An enabled D2 needs its manifest, so it is always validated.
        [$check, $error] = $configured || ($owner === LabLightingD2::class && LabLightingD2::enabled())
            ? $this->probe($owner) : ['not_applicable', null];

        return ['manifest' => $key, 'owner' => class_basename($owner), 'configured' => $configured, 'path' => $configured ? $path : null,
            ...$file, 'check' => $check, 'error' => $error];
    }

    /** @return array{0: 'pass'|'fail'|'not_applicable', 1: ?array{class: ?string, status: ?int}} */
    private function probe(string $owner): array
    {
        try {
            $ready = $owner === LabLightingD2::class ? $this->lightingD2() : app($owner)->ready();
        } catch (Throwable $error) {
            return ['fail', $this->error($error)];
        }
        if ($ready === null) {
            return ['not_applicable', null];
        }

        return $ready === true ? ['pass', null] : ['fail', ['class' => null, 'status' => null]];
    }

    /** LabLightingD2 only hashes in snapshot() and has no ready(); validateRuntime() aborts when the runtime is not valid. */
    private function lightingD2(): ?bool
    {
        if (! LabLightingD2::enabled()) {
            return null;
        }
        LabLightingD2::validateRuntime(LabLightingD2::snapshot());

        return true;
    }

    /** @return array{exists: bool, symlink: bool, readable: bool, bytes: ?int, sha256: ?string, posix: ?array<string, mixed>} */
    private function file(string $path): array
    {
        try {
            clearstatcache(true, $path);
            $exists = is_file($path);
            $stat = $exists ? @stat($path) : false;
            $readable = $exists && is_readable($path);
            $sha256 = $readable ? @hash_file('sha256', $path) : false;
            $posix = null;
            if (is_array($stat)) {
                $user = function_exists('posix_getpwuid') ? posix_getpwuid($stat['uid']) : false;
                $group = function_exists('posix_getgrgid') ? posix_getgrgid($stat['gid']) : false;
                $posix = ['uid' => $stat['uid'], 'user' => is_array($user) ? $user['name'] : null, 'gid' => $stat['gid'],
                    'group' => is_array($group) ? $group['name'] : null, 'mode' => sprintf('%04o', $stat['mode'] & 07777)];
            }

            return ['exists' => $exists, 'symlink' => is_link($path), 'readable' => $readable, 'bytes' => is_array($stat) ? $stat['size'] : null,
                'sha256' => is_string($sha256) ? $sha256 : null, 'posix' => $posix];
        } catch (Throwable) {
            return ['exists' => false, 'symlink' => false, 'readable' => false, 'bytes' => null, 'sha256' => null, 'posix' => null];
        }
    }

    /** @return array{0: array<string, bool>, 1: ?array{class: string, status: ?int}} preset => ready from capabilities()['recipes'] */
    private function recipes(): array
    {
        try {
            $ready = [];
            foreach (app(LabWorkflowPolicy::class)->capabilities()['recipes'] ?? [] as $recipe) {
                if (is_array($recipe) && is_string($recipe['preset'] ?? null)) {
                    $ready[$recipe['preset']] = ($recipe['ready'] ?? false) === true;
                }
            }

            return [$ready, null];
        } catch (Throwable $error) {
            return [[], $this->error($error)];
        }
    }

    /** Every boolean *_enabled flag of config/iguide-lab.php, read strictly (=== true) as the lab code reads them. */
    private function flags(): array|\stdClass
    {
        $flags = [];
        foreach ((array) config('iguide-lab', []) as $name => $value) {
            if (is_string($name) && str_ends_with($name, '_enabled')) {
                $flags[$name] = $value === true;
            }
        }

        return $flags === [] ? new \stdClass : $flags;
    }

    /** Only the class and HTTP status leave this class, never the message (it may hold paths or provider text). */
    private function error(Throwable $error): array
    {
        return ['class' => $error::class, 'status' => $error instanceof HttpExceptionInterface ? $error->getStatusCode() : null];
    }
}
