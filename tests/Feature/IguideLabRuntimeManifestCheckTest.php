<?php

namespace Tests\Feature;

use App\Services\IguideLab\LabAnalysisViews;
use App\Services\IguideLab\LabCatalog;
use App\Services\IguideLab\LabDeclutterRemovalBridge;
use App\Services\IguideLab\LabDeclutterSupport;
use App\Services\IguideLab\LabGrassAssessmentBridge;
use App\Services\IguideLab\LabGrassSourceAdmissionBridge;
use App\Services\IguideLab\LabGrassWorkflowArtifacts;
use App\Services\IguideLab\LabHarmonization;
use App\Services\IguideLab\LabInteriorDesignAssessments;
use App\Services\IguideLab\LabInteriorDesignMaterials;
use App\Services\IguideLab\LabInteriorRoomAssessments;
use App\Services\IguideLab\LabLifestyleAssessmentBridge;
use App\Services\IguideLab\LabLifestyleFramings;
use App\Services\IguideLab\LabLifestyleOpticalDetails;
use App\Services\IguideLab\LabLifestyleSupport;
use App\Services\IguideLab\LabLightingD2;
use App\Services\IguideLab\LabRoomFurnishingSupport;
use App\Services\IguideLab\LabRuntimeManifestCheck;
use App\Services\IguideLab\LabStagingAssessmentBridge;
use App\Services\IguideLab\LabStagingFramings;
use App\Services\IguideLab\LabStagingLayouts;
use App\Services\IguideLab\LabStagingSupport;
use App\Services\IguideLab\LabWorkflowArtifacts;
use App\Services\IguideLab\LabWorkflowPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\RecordingQueueFake;
use Tests\TestCase;

/** iguide-lab:runtime-manifests --check (spec 11.3, D15): read-only per-manifest validity and preset readiness. */
class IguideLabRuntimeManifestCheckTest extends TestCase
{
    use RefreshDatabase;

    /** The 20 manifests of spec 11.3, in report order, with their owners. */
    private const OWNERS = [
        'workflow' => LabWorkflowArtifacts::class, 'lighting_d2' => LabLightingD2::class, 'harmonization' => LabHarmonization::class,
        'staging_assessment' => LabStagingAssessmentBridge::class, 'staging_support' => LabStagingSupport::class,
        'staging_framing' => LabStagingFramings::class, 'staging_layout' => LabStagingLayouts::class,
        'grass_assessment' => LabGrassAssessmentBridge::class, 'grass' => LabGrassWorkflowArtifacts::class,
        'grass_source_admission' => LabGrassSourceAdmissionBridge::class, 'declutter_removal' => LabDeclutterRemovalBridge::class,
        'declutter_support' => LabDeclutterSupport::class, 'interior_design' => LabInteriorDesignAssessments::class,
        'interior_material' => LabInteriorDesignMaterials::class, 'interior_room' => LabInteriorRoomAssessments::class,
        'interior_room_support' => LabRoomFurnishingSupport::class, 'lifestyle_assessment' => LabLifestyleAssessmentBridge::class,
        'lifestyle_framing' => LabLifestyleFramings::class, 'lifestyle_optical' => LabLifestyleOpticalDetails::class,
        'lifestyle_support' => LabLifestyleSupport::class,
    ];

    private const PRESET_FLAGS = ['grass' => 'grass_workflow_enabled', 'declutter' => 'declutter_workflow_enabled',
        'staging' => 'staging_workflow_enabled', 'twilight' => null, 'golden-hour' => null, 'lifestyle' => 'lifestyle_workflow_enabled',
        'interior-design' => 'interior_design_workflow_enabled'];

    /** Text inside every fixture manifest; it must never reach the output (only hashes are printed). */
    private const CONTENT_MARKER = 'manifest-content-must-not-be-printed';

    private string $dir;

    /** @var array<string, string> manifest key => fixture path */
    private array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->dir = sys_get_temp_dir().'/iguide-manifest-check-'.Str::random(12);
        mkdir($this->dir, 0700);
        $config = ['iguide-lab.lighting_d2_enabled' => false];
        foreach (self::PRESET_FLAGS as $flag) if ($flag !== null) $config['iguide-lab.'.$flag] = false;
        foreach (array_keys(self::OWNERS) as $key) {
            $this->paths[$key] = $this->dir.'/'.$key.'.json';
            file_put_contents($this->paths[$key], json_encode(['key' => $key, 'note' => self::CONTENT_MARKER]));
            chmod($this->paths[$key], 0640);
            $config['iguide-lab.'.$key.'_runtime_manifest'] = $this->paths[$key];
        }
        config($config);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) unlink($file);
        if (is_dir($this->dir)) rmdir($this->dir);
        parent::tearDown();
    }

    public function test_reports_every_manifest_and_exits_zero_when_ready(): void
    {
        $this->assertSame(array_keys(LabCatalog::PRESETS), array_keys(LabRuntimeManifestCheck::PRESET_FLAGS), 'every catalog preset is reported');
        $this->assertSame(self::OWNERS, LabRuntimeManifestCheck::OWNERS);
        config(['iguide-lab.lifestyle_optical_runtime_manifest' => null]);
        $this->mockOwners(except: ['lifestyle_optical']);
        $this->mock(LabLifestyleOpticalDetails::class, fn (MockInterface $mock) => $mock->shouldNotReceive('ready'));
        $this->policy(['twilight' => true, 'golden-hour' => true]);

        [$exit, $lines] = $this->check();

        $this->assertSame(0, $exit);
        $this->assertCount(21, $lines);
        $this->assertSame(array_keys(self::OWNERS), array_column(array_slice($lines, 0, 20), 'manifest'));
        foreach (array_slice($lines, 0, 20) as $line) {
            $key = $line['manifest'];
            $this->assertSame(['manifest', 'owner', 'configured', 'path', 'exists', 'symlink', 'readable', 'bytes', 'sha256', 'posix', 'check', 'error'], array_keys($line), $key);
            $this->assertSame(class_basename(self::OWNERS[$key]), $line['owner']);
            if ($key === 'lifestyle_optical') {
                $this->assertSame(['configured' => false, 'path' => null, 'exists' => false, 'symlink' => false, 'readable' => false, 'bytes' => null,
                    'sha256' => null, 'posix' => null, 'check' => 'not_applicable', 'error' => null], array_slice($line, 2), 'unconfigured counts as a pass');
                continue;
            }
            $this->assertTrue($line['configured']);
            $this->assertSame($this->paths[$key], $line['path']);
            $this->assertSame([true, false, true], [$line['exists'], $line['symlink'], $line['readable']], $key);
            $this->assertSame(filesize($this->paths[$key]), $line['bytes']);
            $this->assertSame(hash_file('sha256', $this->paths[$key]), $line['sha256']);
            $this->assertSame(['uid', 'user', 'gid', 'group', 'mode'], array_keys($line['posix']));
            $this->assertSame([fileowner($this->paths[$key]), filegroup($this->paths[$key]), '0640'], [$line['posix']['uid'], $line['posix']['gid'], $line['posix']['mode']]);
            if (function_exists('posix_getpwuid')) $this->assertSame(posix_getpwuid(fileowner($this->paths[$key]))['name'], $line['posix']['user']);
            // D2 is disabled, so its configured manifest is not applicable (spec 11.3 per-owner table).
            $this->assertSame($key === 'lighting_d2' ? 'not_applicable' : 'pass', $line['check'], $key);
            $this->assertNull($line['error']);
        }
        $summary = $lines[20];
        $this->assertSame(['version', 'presetsReady', 'presets', 'flags', 'capabilitiesError', 'ok'], array_keys($summary));
        $this->assertSame('runtime-manifest-check/1', $summary['version']);
        $this->assertSame(2, $summary['presetsReady']);
        $expected = [];
        foreach (self::PRESET_FLAGS as $preset => $flag) {
            $expected[] = ['preset' => $preset, 'flag' => $flag, 'enabled' => $flag === null, 'ready' => in_array($preset, ['twilight', 'golden-hour'], true)];
        }
        $this->assertSame($expected, $summary['presets']);
        $this->assertFalse($summary['flags']['staging_workflow_enabled']);
        $this->assertFalse($summary['flags']['lighting_d2_enabled']);
        foreach ($summary['flags'] as $name => $value) {
            $this->assertStringEndsWith('_enabled', $name);
            $this->assertIsBool($value);
        }
        $this->assertNull($summary['capabilitiesError']);
        $this->assertTrue($summary['ok']);
        $this->assertStringNotContainsString(self::CONTENT_MARKER, Artisan::output());

        // Every enabled preset ready: still exit 0, and presetsReady counts all seven.
        foreach (self::PRESET_FLAGS as $flag) if ($flag !== null) config(['iguide-lab.'.$flag => true]);
        $this->policy(array_fill_keys(array_keys(self::PRESET_FLAGS), true));
        [$exit, $lines] = $this->check();
        $this->assertSame([0, 7, true], [$exit, $lines[20]['presetsReady'], $lines[20]['ok']]);
        $this->assertTrue($lines[20]['flags']['staging_workflow_enabled']);
    }

    public function test_exits_one_when_a_manifest_is_not_ready(): void
    {
        $this->policy(['twilight' => true, 'golden-hour' => true]);

        // A probe that returns false.
        $this->mockOwners(except: ['staging_support']);
        $this->mock(LabStagingSupport::class, fn (MockInterface $mock) => $mock->shouldReceive('ready')->once()->andReturn(false));
        [$exit, $lines] = $this->check();
        $this->assertSame(1, $exit);
        $line = $this->line($lines, 'staging_support');
        $this->assertSame(['fail', ['class' => null, 'status' => null]], [$line['check'], $line['error']]);
        $this->assertFalse($lines[20]['ok']);
        $this->assertSame(2, $lines[20]['presetsReady'], 'preset readiness is reported independently of manifest failures');

        // A probe that throws: only the exception class and HTTP status are printed, never the message.
        $this->mockOwners(except: ['grass_assessment']);
        $this->mock(LabGrassAssessmentBridge::class, fn (MockInterface $mock) => $mock->shouldReceive('ready')
            ->andThrow(new HttpException(503, 'Private detail /home/maverick/secret-runtime')));
        [$exit, $lines] = $this->check();
        $this->assertSame(1, $exit);
        $this->assertSame(['fail', ['class' => HttpException::class, 'status' => 503]], [$this->line($lines, 'grass_assessment')['check'], $this->line($lines, 'grass_assessment')['error']]);
        $this->assertStringNotContainsString('secret-runtime', Artisan::output());
        $this->mock(LabGrassAssessmentBridge::class, fn (MockInterface $mock) => $mock->shouldReceive('ready')->andThrow(new \RuntimeException('runtime text')));
        [$exit, $lines] = $this->check();
        $this->assertSame([1, ['class' => \RuntimeException::class, 'status' => null]], [$exit, $this->line($lines, 'grass_assessment')['error']]);
        $this->assertStringNotContainsString('runtime text', Artisan::output());

        // A configured manifest that does not exist still runs its owner's probe, which decides.
        $this->mockOwners();
        config(['iguide-lab.staging_layout_runtime_manifest' => $this->dir.'/missing.json']);
        $this->mock(LabStagingLayouts::class, fn (MockInterface $mock) => $mock->shouldReceive('ready')->once()->andReturn(false));
        [$exit, $lines] = $this->check();
        $line = $this->line($lines, 'staging_layout');
        $this->assertSame([1, true, false, null, null, null, 'fail'], [$exit, $line['configured'], $line['exists'], $line['bytes'], $line['sha256'], $line['posix'], $line['check']]);

        // Every manifest passes, but an always-on preset is not ready.
        config(['iguide-lab.staging_layout_runtime_manifest' => $this->paths['staging_layout']]);
        $this->mockOwners();
        $this->policy(['twilight' => true, 'golden-hour' => false]);
        [$exit, $lines] = $this->check();
        $this->assertSame([1, false, 1], [$exit, $lines[20]['ok'], $lines[20]['presetsReady']]);
        $this->assertNotContains('fail', array_column(array_slice($lines, 0, 20), 'check'));

        // An enabled preset that is not ready fails; the same preset disabled does not.
        $this->policy(['twilight' => true, 'golden-hour' => true, 'staging' => false]);
        config(['iguide-lab.staging_workflow_enabled' => true]);
        [$exit, $lines] = $this->check();
        $this->assertSame([1, false], [$exit, $lines[20]['ok']]);
        $this->assertSame(['preset' => 'staging', 'flag' => 'staging_workflow_enabled', 'enabled' => true, 'ready' => false], $lines[20]['presets'][2]);
        config(['iguide-lab.staging_workflow_enabled' => false]);
        [$exit] = $this->check();
        $this->assertSame(0, $exit);

        // Capabilities that throw make every preset not ready, and the class is reported.
        $this->app->instance(LabWorkflowPolicy::class, new class extends LabWorkflowPolicy {
            public function capabilities(): array { throw new \RuntimeException('policy detail'); }
        });
        [$exit, $lines] = $this->check();
        $this->assertSame([1, 0, ['class' => \RuntimeException::class, 'status' => null]], [$exit, $lines[20]['presetsReady'], $lines[20]['capabilitiesError']]);
        $this->assertStringNotContainsString('policy detail', Artisan::output());
    }

    public function test_d2_uses_validate_runtime_only_when_enabled(): void
    {
        $this->mockOwners();
        $this->policy(['twilight' => true, 'golden-hour' => true]);
        config(['iguide-lab.pipeline_python_path' => PHP_BINARY]);

        // Disabled: never validated, even with an invalid manifest.
        file_put_contents($this->paths['lighting_d2'], '{}');
        [$exit, $lines] = $this->check();
        $this->assertSame([0, 'not_applicable', null], [$exit, $this->line($lines, 'lighting_d2')['check'], $this->line($lines, 'lighting_d2')['error']]);
        $this->assertSame(hash_file('sha256', $this->paths['lighting_d2']), $this->line($lines, 'lighting_d2')['sha256'], 'the file is still reported');

        // Enabled with an invalid manifest: validateRuntime() aborts with 503.
        config(['iguide-lab.lighting_d2_enabled' => true]);
        [$exit, $lines] = $this->check();
        $this->assertSame([1, 'fail', ['class' => HttpException::class, 'status' => 503]], [$exit, $this->line($lines, 'lighting_d2')['check'], $this->line($lines, 'lighting_d2')['error']]);

        // Enabled with a manifest that matches LabLightingD2::snapshot(): validateRuntime() passes.
        $snapshot = LabLightingD2::snapshot();
        file_put_contents($this->paths['lighting_d2'], json_encode(['validationStatus' => 'passed', 'recipeVersion' => LabLightingD2::VERSION,
            'pythonPath' => PHP_BINARY, 'pythonVersion' => '3.12.3', 'pythonExecutableSha256' => $snapshot['pythonExecutableSha256'],
            'numpyVersion' => '2.5.4', 'pillowVersion' => '13.0.0', 'authoritySha256' => $snapshot['authoritySha256'], 'files' => $snapshot['files']]));
        [$exit, $lines] = $this->check();
        $this->assertSame([0, 'pass', null], [$exit, $this->line($lines, 'lighting_d2')['check'], $this->line($lines, 'lighting_d2')['error']]);

        // Enabled without a configured manifest: an enabled D2 needs one, so it fails (validateRuntime aborts 503).
        config(['iguide-lab.lighting_d2_runtime_manifest' => null]);
        [$exit, $lines] = $this->check();
        $line = $this->line($lines, 'lighting_d2');
        $this->assertSame([1, false, 'fail', 503], [$exit, $line['configured'], $line['check'], $line['error']['status']]);

        // Disabled and unconfigured: not applicable.
        config(['iguide-lab.lighting_d2_enabled' => false]);
        [$exit, $lines] = $this->check();
        $this->assertSame([0, 'not_applicable'], [$exit, $this->line($lines, 'lighting_d2')['check']]);
    }

    public function test_harmonization_uses_ready(): void
    {
        $this->policy(['twilight' => true, 'golden-hour' => true]);
        $this->mockOwners(except: ['harmonization']);
        // snapshot(string) needs a source-cut hash; the check must call ready() only (a Mockery mock rejects any other call).
        $this->mock(LabHarmonization::class, fn (MockInterface $mock) => $mock->shouldReceive('ready')->once()->andReturn(true));
        [$exit, $lines] = $this->check();
        $this->assertSame([0, 'pass'], [$exit, $this->line($lines, 'harmonization')['check']]);

        $this->mock(LabHarmonization::class, fn (MockInterface $mock) => $mock->shouldReceive('ready')->once()->andReturn(false));
        [$exit, $lines] = $this->check();
        $this->assertSame([1, 'fail'], [$exit, $this->line($lines, 'harmonization')['check']]);
    }

    public function test_write_and_restore_are_refused(): void
    {
        foreach (self::OWNERS as $key => $owner) {
            if ($owner !== LabLightingD2::class) $this->mock($owner, fn (MockInterface $mock) => $mock->shouldNotReceive('ready'));
        }
        $this->app->instance(LabWorkflowPolicy::class, new class extends LabWorkflowPolicy {
            public function capabilities(): array { throw new \LogicException('capabilities must not be read'); }
        });
        $before = $this->manifestState();
        foreach ([['--write' => true], ['--restore' => true], [], ['--check' => true, '--write' => true], ['--check' => true, '--restore' => true]] as $options) {
            $exit = Artisan::call('iguide-lab:runtime-manifests', $options);
            $this->assertSame(1, $exit, json_encode($options));
            $this->assertSame("Only --check is available in this release.\n", Artisan::output(), json_encode($options));
        }
        $this->assertSame($before, $this->manifestState());
    }

    public function test_check_writes_no_rows_and_no_storage_files(): void
    {
        $queue = RecordingQueueFake::install($this);
        // The real LabWorkflowPolicy::capabilities() runs, with the runtime owners mocked as in IguideLabWorkflowTest.
        $key = $this->dir.'/openai-primary.key';
        file_put_contents($key, 'fixture-primary');
        config(['iguide-lab.openai_primary_key_path' => $key, 'services.openai.api_key' => 'fixture-fallback']);
        $this->mock(LabAnalysisViews::class)->shouldReceive('ready')->andReturn(true);
        $this->mockOwners(except: ['workflow']);
        $this->mock(LabWorkflowArtifacts::class, function (MockInterface $mock) {
            $mock->shouldReceive('snapshot')->andReturn(['recipeVersion' => 'fixture/1']);
            $mock->shouldReceive('ready')->andReturn(true);
        });
        $tables = $this->tableCounts();
        $storage = $this->tree(storage_path());
        $manifests = $this->manifestState();
        $bytecode = $this->tree(resource_path('iguide-lab'), '__pycache__');
        $statements = [];
        DB::listen(function ($query) use (&$statements) { $statements[] = $query->sql; });

        [$exit, $lines] = $this->check();

        $this->assertSame(0, $exit);
        $this->assertSame(2, $lines[20]['presetsReady'], 'the real capabilities chain ran: twilight and golden hour are ready');
        $this->assertSame([], array_values(array_filter($statements, fn ($sql) => ! preg_match('/\A\s*select\b/i', $sql))), 'no write statement');
        $this->assertSame($tables, $this->tableCounts());
        $this->assertSame($storage, $this->tree(storage_path()), 'no file under storage/ was created, changed or removed');
        $this->assertSame($manifests, $this->manifestState());
        $this->assertSame($bytecode, $this->tree(resource_path('iguide-lab'), '__pycache__'));
        $this->assertSame([], $queue->levels, 'nothing was queued');
        $this->assertStringNotContainsString('fixture-primary', Artisan::output());
        $this->assertStringNotContainsString('fixture-fallback', Artisan::output());
    }

    /** Mock every owner's ready() as true, except the listed manifest keys (LabLightingD2 is static and never mocked). */
    private function mockOwners(array $except = []): void
    {
        foreach (self::OWNERS as $key => $owner) {
            if ($owner === LabLightingD2::class || in_array($key, $except, true)) continue;
            $this->mock($owner, fn (MockInterface $mock) => $mock->shouldReceive('ready')->andReturn(true));
        }
    }

    /** @param array<string, bool> $ready preset => ready; missing presets are not ready */
    private function policy(array $ready): void
    {
        $this->app->instance(LabWorkflowPolicy::class, new class($ready) extends LabWorkflowPolicy {
            public function __construct(private array $ready) {}
            public function capabilities(): array
            {
                return ['supported' => true, 'recipes' => array_map(fn ($preset) => ['preset' => $preset, 'ready' => $this->ready[$preset] ?? false, 'reason' => null],
                    array_keys(LabCatalog::PRESETS))];
            }
        });
    }

    /** @return array{0: int, 1: list<array<string, mixed>>} */
    private function check(): array
    {
        $exit = Artisan::call('iguide-lab:runtime-manifests', ['--check' => true]);
        $output = Artisan::output();
        $this->assertStringEndsWith("\n", $output);
        $lines = array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", rtrim($output, "\n")));

        return [$exit, $lines];
    }

    private function line(array $lines, string $key): array
    {
        foreach ($lines as $line) if (($line['manifest'] ?? null) === $key) return $line;
        $this->fail('No line for '.$key);
    }

    /** @return array<string, array{0: string, 1: int}> */
    private function manifestState(): array
    {
        clearstatcache();
        $state = [];
        foreach (glob($this->dir.'/*') ?: [] as $file) $state[$file] = [hash_file('sha256', $file), filemtime($file)];
        ksort($state);

        return $state;
    }

    /** @return array<string, string> relative path => size:mtime:mode (directories end with /) */
    private function tree(string $root, ?string $only = null): array
    {
        clearstatcache();
        $tree = [];
        if (! is_dir($root)) return $tree;
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            $relative = substr($item->getPathname(), strlen($root));
            if ($only !== null && ! str_contains($relative, $only)) continue;
            $tree[$relative.($item->isDir() ? '/' : '')] = $item->isDir() ? 'dir' : $item->getSize().':'.$item->getMTime().':'.decoct($item->getPerms() & 07777);
        }
        ksort($tree);

        return $tree;
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name") as $table) {
            $counts[$table->name] = DB::table($table->name)->count();
        }

        return $counts;
    }
}
