<?php

namespace Tests\Feature;

use App\Models\IguideLabProject;
use App\Services\IguideLab\LabInteriorDesignPolicy;
use App\Services\IguideLab\LabInteriorRoomPolicy;
use App\Services\IguideLab\LabLifestyleFramingProtocol;
use App\Services\IguideLab\LabLifestyleOpticalProtocol;
use App\Services\IguideLab\LabLightingD2;
use App\Services\IguideLab\LabRoomFurnishingSupport;
use App\Services\IguideLab\LabRuntimeManifestCheck;
use App\Services\IguideLab\LabStagingFramingProtocol;
use App\Services\IguideLab\LabStagingLayouts;
use App\Services\IguideLab\LabStagingSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * iguide-lab:runtime-manifests --write / --restore (D2 tooling). Every manifest starts stale (old code hashes, correct
 * Python pins); the real owner validators decide whether the rebuilt manifests are valid.
 */
class IguideLabRuntimeManifestWriteTest extends TestCase
{
    use RefreshDatabase;

    private const STALE = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Pins the tool must never change (Python, libraries, executables, versions). */
    private const RUNTIME_PINS = ['version', 'recipeVersion', 'schemaVersion', 'validationStatus', 'pythonPath', 'pythonResolvedPath', 'pythonVersion',
        'numpyVersion', 'pillowVersion', 'scipyVersion', 'opencvVersion', 'opencvBuildSha256', 'pythonSha256', 'pythonExecutableSha256'];

    private string $dir;

    private string $key;

    /** @var array<string, string> manifest key => path; interior_material stays unconfigured (its probe runs real Python) */
    private array $paths = [];

    /** @var array<string, string> manifest key => the stale bytes it started with */
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->dir = sys_get_temp_dir().'/iguide-manifest-write-'.Str::random(12);
        mkdir($this->dir, 0700);
        $this->key = $this->dir.'-openai.key';
        file_put_contents($this->key, 'fixture-primary');
        $config = ['iguide-lab.openai_primary_key_path' => $this->key, 'iguide-lab.lighting_d2_enabled' => true, 'iguide-lab.staging_framing_enabled' => true,
            'iguide-lab.interior_material_runtime_manifest' => null];
        foreach (['pipeline', 'harmonization', 'staging_support', 'lifestyle_support', 'interior_room_support', 'interior_material'] as $python) {
            $config['iguide-lab.'.$python.'_python_path'] = PHP_BINARY;
        }
        foreach (array_keys(LabRuntimeManifestCheck::OWNERS) as $key) {
            if ($key === 'interior_material') continue;
            mkdir($this->dir.'/'.$key, 0700);
            $this->paths[$key] = $this->dir.'/'.$key.'/runtime-manifest.json';
            $this->original[$key] = json_encode($this->stale($key), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
            file_put_contents($this->paths[$key], $this->original[$key]);
            chmod($this->paths[$key], 0640);
            $config['iguide-lab.'.$key.'_runtime_manifest'] = $this->paths[$key];
        }
        config($config);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            rmdir($this->dir);
        }
        if (is_file($this->key)) unlink($this->key);
        parent::tearDown();
    }

    public function test_write_rebuilds_stale_manifests_that_every_real_validator_accepts(): void
    {
        $before = $this->checks();
        foreach ($this->paths as $key => $path) $this->assertSame('fail', $before[$key], $key.' starts stale');
        $inodes = array_map('fileinode', $this->paths);

        [$exit, $output] = $this->manifests(['--write' => true]);

        $this->assertSame(0, $exit, $output);
        $after = $this->checks();
        foreach ($this->paths as $key => $path) $this->assertSame('pass', $after[$key], $key);
        $this->assertSame('not_applicable', $after['interior_material']);
        $set = $this->set($output);
        $lines = $this->jsonLines($output);
        foreach ($this->paths as $key => $path) {
            $this->assertSame(['write', 'pass'], [$lines[$key]['action'], $lines[$key]['check']], $key);
            $stale = json_decode($this->original[$key], true);
            $written = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            foreach (self::RUNTIME_PINS as $pin) {
                if (array_key_exists($pin, $stale)) $this->assertSame($stale[$pin], $written[$pin], $key.' keeps '.$pin);
            }
            // Backup + atomic swap: the old bytes sit beside the manifest, the manifest is a new inode, no temp file is left.
            $this->assertSame([$path, $path.'.bak-'.$set], $this->files(dirname($path)), $key);
            $this->assertSame($this->original[$key], file_get_contents($path.'.bak-'.$set));
            $this->assertNotSame($inodes[$key], fileinode($path), $key.' was replaced by rename, not rewritten in place');
            $this->assertSame(['0640', '0640'], [$this->mode($path), $this->mode($path.'.bak-'.$set)]);
            $this->assertStringContainsString("restore {$key}: cp -p '{$path}.bak-{$set}' '{$path}'", $output);
        }
        $this->assertStringContainsString('php artisan iguide-lab:runtime-manifests --restore='.$set, $output);
        $this->assertStringContainsString('Backup set: '.$set, $output);

        // A second write finds nothing to change and touches nothing.
        $state = $this->state();
        [$exit, $output] = $this->manifests(['--write' => true]);
        $this->assertSame(0, $exit, $output);
        $this->assertSame($state, $this->state());
        foreach ($this->paths as $key => $path) $this->assertSame('unchanged', $this->jsonLines($output)[$key]['action'], $key);
        $this->assertSame('not_configured', $this->jsonLines($output)['interior_material']['action']);
        $this->assertStringNotContainsString('Backup set:', $output);
    }

    public function test_write_rebuilds_interior_material_against_its_real_python_probe(): void
    {
        $python = '/usr/local/bin/python3.12';
        $path = $this->dir.'/interior_material.json';
        file_put_contents($path, json_encode(['version' => 'interior-material-runtime/1', 'validationStatus' => 'passed', 'python' => '3.12.3', 'numpy' => '2.5.4',
            'pillow' => '13.0.0', 'opencv' => '5.0.0', 'files' => ['interior_material_worker.py' => self::STALE]]));
        chmod($path, 0640);
        config(['iguide-lab.interior_material_runtime_manifest' => $path, 'iguide-lab.interior_material_python_path' => $python]);
        $this->assertSame('fail', $this->checks()['interior_material']);
        $before = file_get_contents($path);

        [$exit, $output] = $this->manifests(['--write' => true, '--only' => 'interior_material']);

        if (! is_executable($python)) {
            // CI has no pinned Python runtime (it runs the PHP suite only), so the real probe cannot pass there:
            // the candidate must then fail validation and nothing may be written. Never skip (CI fails on skips).
            $this->assertSame(1, $exit, $output);
            $this->assertSame($before, file_get_contents($path), 'a candidate that cannot be validated is never written');
            return;
        }
        $this->assertSame(0, $exit, $output);
        $this->assertSame(['write', 'pass'], [$this->jsonLines($output)['interior_material']['action'], $this->jsonLines($output)['interior_material']['check']]);
        $this->assertSame('pass', $this->checks()['interior_material'], 'the Python worker accepts the rebuilt file hashes');
    }

    public function test_refuses_while_lab_work_is_in_flight(): void
    {
        $project = IguideLabProject::create(['name' => 'In flight', 'token_hash' => hash('sha256', Str::random(64)), 'expires_at' => now()->addDay(), 'max_runs' => 20]);
        $workflow = (string) Str::uuid();
        DB::table('iguide_lab_workflows')->insert(['id' => $workflow, 'project_id' => $project->id, 'request_id' => (string) Str::uuid(),
            'request_hash' => str_repeat('a', 64), 'status' => 'running', 'policy' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $state = $this->state();

        foreach ([['--write' => true], ['--write' => true, '--dry-run' => true], ['--restore' => '20261117T000000Z-abcdef']] as $options) {
            [$exit, $output] = $this->manifests($options);
            $this->assertSame(1, $exit, json_encode($options));
            $this->assertStringContainsString('Refused: lab work is in flight (workflows=1, workflowItems=0, runs=0, tourRuns=0)', $output);
            $this->assertSame($state, $this->state(), 'nothing was written, backed up or left behind');
        }

        DB::table('iguide_lab_workflows')->where('id', $workflow)->update(['status' => 'ready']);
        [$exit, $output] = $this->manifests(['--write' => true]);
        $this->assertSame(0, $exit, $output);
    }

    public function test_a_settled_needs_attention_run_does_not_block_and_a_live_run_is_named(): void
    {
        $project = IguideLabProject::create(['name' => 'Settled', 'token_hash' => hash('sha256', Str::random(64)), 'expires_at' => now()->addDay(), 'max_runs' => 20]);
        $case = (string) Str::uuid();
        DB::table('iguide_lab_cases')->insert(['id' => $case, 'project_id' => $project->id, 'label' => 'Case', 'projection' => 'equirectangular', 'width' => 2048,
            'height' => 1024, 'source_sha256' => str_repeat('c', 64), 'source_path' => 'x.png', 'source_mime' => 'image/png', 'created_at' => now(), 'updated_at' => now()]);
        $run = fn (string $status) => tap((string) Str::uuid(), fn ($id) => DB::table('iguide_lab_runs')->insert(['id' => $id, 'project_id' => $project->id, 'case_id' => $case,
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'model_id' => 'm', 'provider' => 'openai', 'preset' => 'grass', 'prompt' => 'p',
            'options' => '{}', 'status' => $status, 'completed_at' => $status === 'needs_attention' ? now() : null, 'created_at' => now(), 'updated_at' => now()]));
        $run('needs_attention');

        $this->assertSame(['workflows' => 0, 'workflowItems' => 0, 'runs' => 0, 'tourRuns' => 0], app(\App\Services\IguideLab\LabRuntimeManifests::class)->inFlight());
        [$exit, $output] = $this->manifests(['--write' => true, '--dry-run' => true]);
        $this->assertSame(0, $exit, $output);

        $live = $run('processing');
        $state = $this->state();
        [$exit, $output] = $this->manifests(['--write' => true]);
        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Refused: lab work is in flight (workflows=0, workflowItems=0, runs=1, tourRuns=0)', $output);
        $this->assertStringContainsString('run '.$live.' processing', $output, 'the refusal names the blocking record');
        $this->assertSame($state, $this->state());
    }

    public function test_a_candidate_that_fails_its_validator_writes_nothing(): void
    {
        // Python pins are never changed, so a stale manifest with a wrong OpenCV pin cannot be made valid.
        $broken = json_decode($this->original['staging_support'], true);
        $broken['opencvVersion'] = '4.10.0';
        file_put_contents($this->paths['staging_support'], json_encode($broken));
        $state = $this->state();

        [$exit, $output] = $this->manifests(['--write' => true]);

        $this->assertSame(1, $exit, $output);
        $this->assertSame($state, $this->state(), 'no manifest, backup or temp file was written');
        $lines = $this->jsonLines($output);
        $this->assertSame(['write', 'fail'], [$lines['staging_support']['action'], $lines['staging_support']['check']]);
        $this->assertSame('pass', $lines['grass']['check'], 'the other candidates were valid');
        $this->assertStringContainsString('Refused: a rebuilt manifest does not pass its owner\'s check (staging_support); nothing was written.', $output);
        $this->assertStringNotContainsString('Backup set:', $output);
    }

    public function test_dry_run_validates_and_writes_nothing(): void
    {
        $state = $this->state();

        [$exit, $output] = $this->manifests(['--write' => true, '--dry-run' => true]);

        $this->assertSame(0, $exit, $output);
        $this->assertSame($state, $this->state());
        foreach ($this->paths as $key => $path) $this->assertSame(['write', 'pass'], [$this->jsonLines($output)[$key]['action'], $this->jsonLines($output)[$key]['check']], $key);
        $this->assertStringContainsString('Dry run: nothing was written.', $output);
    }

    public function test_write_changes_only_code_pins_and_keeps_the_file_layout(): void
    {
        // Compact, escaped slashes, no trailing newline, an empty object and a float: all kept; only the code pins change.
        $raw = '{"note":{},"ratio":1.0,'.substr(json_encode($this->stale('grass_assessment')), 1);
        file_put_contents($this->paths['grass_assessment'], $raw);

        [$exit, $output] = $this->manifests(['--write' => true, '--only' => 'grass_assessment']);

        $this->assertSame(0, $exit, $output);
        $written = file_get_contents($this->paths['grass_assessment']);
        $this->assertStringStartsWith('{"note":{},"ratio":1.0,"validationStatus":"passed","pythonPath":"'.str_replace('/', '\/', PHP_BINARY).'",', $written);
        $this->assertStringNotContainsString("\n", $written);
        $old = json_decode($raw, true);
        $new = json_decode($written, true);
        $changed = array_keys(array_filter($new, fn ($value, $key) => ($old[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
        foreach ($changed as $key) $this->assertMatchesRegularExpression('/\Avalidated[A-Z]\w*Sha256\z/', $key);
        $this->assertSame($changed, $this->jsonLines($output)['grass_assessment']['changedKeys']);
        $this->assertSame('pass', $this->checks()['grass_assessment']);
    }

    public function test_refuses_when_another_manifest_would_stop_passing(): void
    {
        // staging_support is out of scope; its check passes before the candidates are in place and fails with them.
        $this->mock(LabStagingSupport::class, fn (MockInterface $mock) => $mock->shouldReceive('ready')->andReturn(true, false));
        $state = $this->state();

        [$exit, $output] = $this->manifests(['--write' => true, '--only' => 'grass']);

        $this->assertSame(1, $exit, $output);
        $this->assertSame($state, $this->state());
        $this->assertSame('pass', $this->jsonLines($output)['grass']['check']);
        $this->assertStringContainsString('Refused: the rebuilt manifests would make another manifest fail its check (staging_support); nothing was written.', $output);
    }

    public function test_refuses_when_the_new_file_would_have_another_owner(): void
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            // Without root no file can be given a foreign owner. CI runs unprivileged and fails on skipped tests, so assert
            // the unprivileged half of the rule instead: a manifest the current user owns is rewritten with the same owner,
            // group and mode.
            $stat = stat($this->paths['grass']);
            [$exit, $output] = $this->manifests(['--write' => true, '--only' => 'grass']);
            $this->assertSame(0, $exit, $output);
            clearstatcache();
            $after = stat($this->paths['grass']);
            $this->assertSame([$stat['uid'], $stat['gid'], $stat['mode'] & 0777], [$after['uid'], $after['gid'], $after['mode'] & 0777]);
            return;
        }
        chown($this->paths['grass'], 4242);
        $state = $this->state();

        [$exit, $output] = $this->manifests(['--write' => true]);

        $this->assertSame(1, $exit, $output);
        $this->assertSame($state, $this->state());
        $this->assertMatchesRegularExpression('/Refused: grass would be written as uid 0 gid \d+ mode 0640, but the manifest is uid 4242 gid \d+ mode 0640; run this as the manifest owner\. Nothing was written\./', $output);
    }

    public function test_restore_puts_a_backup_set_back(): void
    {
        [, $output] = $this->manifests(['--write' => true]);
        $set = $this->set($output);
        $written = array_map('file_get_contents', $this->paths);

        [$exit, $output] = $this->manifests(['--restore' => $set]);

        $this->assertSame(0, $exit, $output);
        foreach ($this->paths as $key => $path) {
            $this->assertSame($this->original[$key], file_get_contents($path), $key);
            $this->assertSame('0640', $this->mode($path));
            $this->assertSame('restore', $this->jsonLines($output)[$key]['action'], $key);
        }
        // The restore is itself undoable: what it replaced is kept as a new set.
        $undo = $this->set($output);
        $this->assertNotSame($set, $undo);
        foreach ($this->paths as $key => $path) $this->assertSame($written[$key], file_get_contents($path.'.bak-'.$undo), $key);
        $this->assertSame('fail', $this->checks()['grass'], 'the restored stale manifest is back in force');

        // Restoring the same set again changes nothing.
        $state = $this->state();
        [$exit, $output] = $this->manifests(['--restore' => $set]);
        $this->assertSame(0, $exit, $output);
        $this->assertSame($state, $this->state());
        $this->assertSame('unchanged', $this->jsonLines($output)['grass']['action']);

        // Unknown or malformed sets are refused.
        foreach (['20990101T000000Z-zzzzzz' => 'Refused: no backup set 20990101T000000Z-zzzzzz', '../../etc/passwd' => 'Refused: a backup set looks like 20261117T013000Z-abc123'] as $bad => $message) {
            [$exit, $output] = $this->manifests(['--restore' => $bad]);
            $this->assertSame(1, $exit, $bad);
            $this->assertStringContainsString($message, $output);
            $this->assertSame($state, $this->state());
        }
    }

    public function test_only_limits_write_and_restore_to_the_named_manifests(): void
    {
        $untouched = array_diff_key($this->paths, ['grass' => true, 'declutter_removal' => true]);
        $state = $this->state(array_keys($untouched));

        [$exit, $output] = $this->manifests(['--write' => true, '--only' => 'grass, declutter_removal']);

        $this->assertSame(0, $exit, $output);
        $this->assertSame(['grass', 'declutter_removal'], array_keys($this->jsonLines($output)));
        $checks = $this->checks();
        $this->assertSame(['pass', 'pass'], [$checks['grass'], $checks['declutter_removal']]);
        $this->assertSame($state, $this->state(array_keys($untouched)), 'manifests outside --only are not written or backed up');
        $set = $this->set($output);

        [$exit, $output] = $this->manifests(['--restore' => $set, '--only' => 'grass']);
        $this->assertSame(0, $exit, $output);
        $this->assertSame($this->original['grass'], file_get_contents($this->paths['grass']));
        $this->assertNotSame($this->original['declutter_removal'], file_get_contents($this->paths['declutter_removal']));

        $all = $this->state();
        foreach ([['--write' => true, '--only' => 'grass,unknown'], ['--write' => true, '--only' => ' , ']] as $options) {
            [$exit, $output] = $this->manifests($options);
            $this->assertSame(1, $exit, json_encode($options));
            $this->assertStringContainsString('Refused: --only needs manifest names from: workflow, lighting_d2,', $output);
            $this->assertSame($all, $this->state());
        }
    }

    public function test_modes_are_exclusive(): void
    {
        $state = $this->state();
        foreach ([[], ['--check' => true, '--write' => true], ['--write' => true, '--restore' => '20261117T000000Z-abcdef'],
            ['--check' => true, '--only' => 'grass'], ['--check' => true, '--dry-run' => true], ['--dry-run' => true], ['--restore' => true]] as $options) {
            [$exit, $output] = $this->manifests($options);
            $this->assertSame(1, $exit, json_encode($options));
            $this->assertSame("Choose one of --check, --write [--dry-run] [--only=a,b] or --restore=<set> [--only=a,b].\n", $output, json_encode($options));
        }
        $this->assertSame($state, $this->state());
    }

    /** @return array<string, mixed> a manifest with valid runtime pins and stale (or missing) code pins */
    private function stale(string $key): array
    {
        $python = PHP_BINARY;
        $pythonSha = hash_file('sha256', $python);
        $s = self::STALE;
        $base = ['validationStatus' => 'passed', 'pythonPath' => $python, 'pythonVersion' => '3.12.3', 'numpyVersion' => '2.5.4', 'pillowVersion' => '13.0.0'];
        $opencv = ['opencvVersion' => '5.0.0', 'opencvBuildSha256' => str_repeat('c', 64)];

        return match ($key) {
            'workflow' => $base + ['validatedWorkerSha256' => $s, 'validatedPreparerSha256' => $s],
            'grass' => $base + ['validatedGrassCoreSha256' => $s],
            'lighting_d2' => $base + ['recipeVersion' => LabLightingD2::VERSION, 'pythonExecutableSha256' => $pythonSha, 'authoritySha256' => $s, 'files' => ['worker' => $s]],
            'harmonization' => ['schemaVersion' => 1] + $base + ['scipyVersion' => '1.18.1', 'validatedSolverSha256' => $s],
            'staging_support', 'lifestyle_support' => $base + $opencv + ['validatedCoreSha256' => $s],
            'declutter_support' => $base + ['scipyVersion' => '1.18.1', 'validatedWorkerSha256' => $s],
            'staging_framing' => ['version' => LabStagingFramingProtocol::VERSION] + $base + ['implementation' => ['serviceSha256' => $s]],
            'lifestyle_framing' => ['version' => LabLifestyleFramingProtocol::VERSION] + $base + ['implementation' => ['serviceSha256' => $s]],
            'staging_layout' => ['version' => LabStagingLayouts::VERSION] + $base + ['pythonSha256' => $pythonSha, 'files' => ['config/iguide-lab.php' => $s]],
            'interior_design' => ['version' => LabInteriorDesignPolicy::VERSION] + $base + ['implementation' => ['serviceSha256' => $s]],
            'interior_room' => ['version' => LabInteriorRoomPolicy::VERSION] + $base + ['pythonSha256' => $pythonSha, 'implementation' => ['serviceSha256' => $s]],
            'interior_room_support' => ['version' => LabRoomFurnishingSupport::VERSION] + $base + ['pythonSha256' => $pythonSha] + $opencv + ['implementation' => ['adapterSha256' => $s]],
            'lifestyle_optical' => ['version' => LabLifestyleOpticalProtocol::VERSION] + $base + ['pythonResolvedPath' => realpath($python),
                'pythonSha256' => hash_file('sha256', realpath($python)), 'implementation' => ['serviceSha256' => $s]],
            // The five assessment bridges: only some code pins are present; the tool must add the rest.
            default => $base + ['validatedAdapterSha256' => $s, 'validatedGeometrySha256' => $s],
        };
    }

    /** @return array{0: int, 1: string} */
    private function manifests(array $options): array
    {
        $exit = Artisan::call('iguide-lab:runtime-manifests', $options);

        return [$exit, Artisan::output()];
    }

    /** @return array<string, string> manifest key => check */
    private function checks(): array
    {
        return array_map(fn (array $check) => $check['check'], app(LabRuntimeManifestCheck::class)->checks());
    }

    /** @return array<string, array<string, mixed>> manifest key => its JSON output line */
    private function jsonLines(string $output): array
    {
        $lines = [];
        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, '{')) {
                $line = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $lines[$line['manifest']] = $line;
            }
        }

        return $lines;
    }

    private function set(string $output): string
    {
        $this->assertMatchesRegularExpression('/^Backup set: (\d{8}T\d{6}Z-[a-z0-9]{6})$/m', $output);
        preg_match('/^Backup set: (\S+)$/m', $output, $match);

        return $match[1];
    }

    /** @return list<string> */
    private function files(string $dir): array
    {
        $files = array_map(fn ($name) => $dir.'/'.$name, array_values(array_diff(scandir($dir), ['.', '..'])));
        sort($files);

        return $files;
    }

    private function mode(string $path): string
    {
        clearstatcache(true, $path);

        return sprintf('%04o', fileperms($path) & 07777);
    }

    /** @return array<string, string> every file under the fixture directories => sha256:inode:mode (optionally only some manifest directories) */
    private function state(?array $keys = null): array
    {
        clearstatcache();
        $state = [];
        foreach ($keys ?? array_keys($this->paths) as $key) {
            foreach ($this->files($this->dir.'/'.$key) as $file) $state[$file] = hash_file('sha256', $file).':'.fileinode($file).':'.$this->mode($file);
        }

        return $state;
    }
}
