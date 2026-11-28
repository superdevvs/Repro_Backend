<?php

namespace Tests\Unit;

use FilesystemIterator;
use PhpToken;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Python started by the app or by a host script must not write bytecode (__pycache__) next to
 * the code it runs. The backend source trees (app, bootstrap, config, resources, routes, scripts,
 * public, database) are fingerprinted file by file (scripts/deploy/backend-source-fingerprint.py),
 * and a .pyc appearing there changes the fingerprint that the database cutover runner checks
 * before every action. So every Python process environment in the app carries
 * 'PYTHONDONTWRITEBYTECODE' => '1', and host scripts run app-tree Python with python3 -B.
 *
 * -E and -I make Python ignore the variable (review W2V-1), so neither the app's argv nor a host
 * command may carry them without -B. The app's interpreter paths that come from .env are outside
 * what this test can see; see ENVIRONMENT_INTERPRETERS.
 */
class PythonProcessesWriteNoBytecodeTest extends TestCase
{
    private const PHP_TREES = ['app', 'bootstrap', 'config', 'database', 'routes'];

    /**
     * Python process starts the scan saw in the app on 26 Nov 2026, all in app/Services/IguideLab. Raise it
     * when a site is added; never lower it to make the test pass.
     */
    private const PYTHON_SITES_FLOOR = 34;

    /**
     * Interpreter paths that come from .env, not from the code (config/iguide-lab.php). The scan sees only the
     * argv the app builds, so each of these must be a real Python interpreter (or a venv link to one), never a
     * wrapper script that adds -E or -I or rebuilds the environment: that would make Python ignore
     * PYTHONDONTWRITEBYTECODE again, and no test here could see it (review W2V-1). The operator checks them on the
     * host, read-only, before the cutover (P2).
     */
    private const ENVIRONMENT_INTERPRETERS = [
        'IGUIDE_LAB_INTERIOR_MATERIAL_PYTHON',
        'IGUIDE_LAB_INTERIOR_ROOM_SUPPORT_PYTHON',
        'IGUIDE_LAB_LIFESTYLE_SUPPORT_PYTHON',
        'IGUIDE_LAB_STAGING_SUPPORT_PYTHON',
    ];

    private const COMMAND_FUNCTIONS = ['proc_open', 'exec', 'shell_exec', 'system', 'passthru', 'popen'];

    /** python, flags, then a .py target; `python3 -` (a script on stdin) has no target and is not matched. */
    private const PYTHON_FILE_RUN = '~\bpython[0-9.]*((?:\s+-[A-Za-z]+)*)\s+["\']?([^\s"\'|;&`]+\.py)\b~';

    /** @var array<string, string> relative path => PHP source */
    private array $sources = [];

    /** @var array<string, array{tokens: list<PhpToken>, match: array<int, int>, namespace: string, uses: array<string, string>, functions: list<array{int, int}>, signatures: list<array{int, int, ?string, list<string>, string}>}> */
    private array $files = [];

    /** @var array<string, array{path: string, open: int, close: int, parent: ?string, traits: list<string>, methods: array<string, array{int, int}>}> */
    private array $classes = [];

    /** @var array<string, array{list<array{string, int, int}>, bool}> "path:body:variable" => [caller arguments, unfollowed] */
    private array $callers = [];

    /** Whether the command being expanded reached a parameter whose callers the scan cannot all see. */
    private bool $unfollowed = false;

    public function test_every_python_process_started_by_the_app_disables_bytecode(): void
    {
        $root = dirname(__DIR__, 2);
        $sources = [];
        foreach (self::PHP_TREES as $tree) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$tree, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $sources[$tree.'/'.str_replace('\\', '/', substr($file->getPathname(), strlen($root.'/'.$tree) + 1))] = file_get_contents($file->getPathname());
                }
            }
        }
        ksort($sources);

        $scan = $this->scan($sources);
        $offenders = $scan['offenders'];

        $this->assertSame([], $offenders, count($offenders)." Python process(es) that may write bytecode (no 'PYTHONDONTWRITEBYTECODE' => '1' in the environment, -E or -I without -B, or a command the scan cannot see):\n".implode("\n", $offenders));
        // A floor, not a count: a refactor that moves a command where the scan cannot read it (a constant, a
        // property, a foreach list) makes the site vanish without any failure above (review W2V-2).
        $this->assertGreaterThanOrEqual(self::PYTHON_SITES_FLOOR, count($scan['sites']),
            'The scan saw only '.count($scan['sites']).' Python process starts, fewer than the '.self::PYTHON_SITES_FLOOR
            .' it saw on 26 Nov 2026: a command moved where the scan cannot read it. Make it readable again (a literal'
            ." argv, config(...), a local variable or a \$this helper) rather than lowering the floor.\n".implode("\n", $scan['sites']));
    }

    public function test_the_scan_reports_python_processes_in_each_code_style(): void
    {
        $fixtures = [
            'app/Fixture/Base.php' => <<<'PHP'
                <?php
                namespace App\Fixture;
                class Base
                {
                    protected function environment(): array
                    {
                        $env = array_fill_keys(array_keys(getenv()), false);
                        return array_merge($env, ['PATH' => '/usr/bin:/bin', 'PYTHONDONTWRITEBYTECODE' => '1']);
                    }
                    protected function bare(): array
                    {
                        return ['PATH' => '/usr/bin:/bin', 'PYTHONNOUSERSITE' => '1'];
                    }
                }
                PHP,
            'app/Fixture/Child.php' => <<<'PHP'
                <?php
                namespace App\Fixture;
                use Illuminate\Support\Facades\Process;
                use Symfony\Component\Process\Process as SymfonyProcess;
                class Child extends Base
                {
                    public function inherited(): void
                    {
                        (new SymfonyProcess([(string) config('lab.pipeline_python_path'), resource_path('lab/a.py')], null, $this->environment()))->run();
                        (new SymfonyProcess([(string) config('lab.pipeline_python_path'), resource_path('lab/a.py')], null, $this->bare()))->run(); // offender: new Process
                    }
                    public function compact(array $input): void
                    {
                        $env=array_merge([],['PATH'=>'/usr/bin:/bin']);$p=new \Symfony\Component\Process\Process([(string)config('lab.harmonization_python_path'),resource_path('lab/b.py'),'--request',$input['path']],null,$env,null,65); // offender: new Process
                        $e2=array_merge([],['PATH'=>'/usr/bin:/bin','PYTHONDONTWRITEBYTECODE'=>'1']);$q=new \Symfony\Component\Process\Process([(string)config('lab.harmonization_python_path'),resource_path('lab/b.py')],null,$e2,null,65);
                    }
                    public function variableCommand(string $worker): void
                    {
                        $env = ['PATH' => '/usr/bin:/bin'];
                        $args = [(string) config('lab.pipeline_python_path'), resource_path('lab/'.$worker)];
                        $process = new SymfonyProcess($args, null, $env, null, 30); // offender: new Process
                    }
                    public function namedArguments(): void
                    {
                        $python = '/usr/bin/python3';
                        (new SymfonyProcess(command: [$python, '-c', 'print(1)'], env: ['PYTHONDONTWRITEBYTECODE' => '1']))->run();
                        (new SymfonyProcess(env: ['PATH' => '/usr/bin'], command: [$python, '-c', 'print(1)']))->run(); // offender: new Process
                    }
                    public function inheritedEnvironment(): void
                    {
                        SymfonyProcess::fromShellCommandline('python3 '.resource_path('lab/c.py'))->run(); // offender: Process::fromShellCommandline
                        (new SymfonyProcess(['python', 'x.py']))->run(); // offender: new Process
                    }
                    public function elementAssignment(): void
                    {
                        $env = getenv();
                        $env['PYTHONDONTWRITEBYTECODE'] = '1';
                        (new SymfonyProcess(['python', 'x.py'], null, $env))->run();
                    }
                    public function facade(): void
                    {
                        Process::env(['PATH' => '/usr/bin'])->run(['python3', resource_path('lab/d.py')]); // offender: Process::env
                        Process::path(base_path())->env(['PYTHONDONTWRITEBYTECODE' => '1'])->run('python3 lab/d.py');
                        Process::run('ffmpeg -version');
                    }
                    public function phpFunctions(): void
                    {
                        exec('python3 '.escapeshellarg(resource_path('lab/e.py'))); // offender: exec
                        exec('python3 -B '.escapeshellarg(resource_path('lab/e.py')));
                        $pdo = new \PDO('sqlite::memory:'); $pdo->exec('select 1');
                        proc_open(['python3', 'lab/f.py'], [], $pipes, null, ['PYTHONDONTWRITEBYTECODE' => '1']);
                    }
                    public function notPython(): void
                    {
                        (new SymfonyProcess(['ffmpeg', '-version']))->run();
                    }
                }
                PHP,
        ];

        $this->assertCount(8, $this->assertScan($fixtures, 15));
    }

    public function test_the_scan_reports_interpreter_flags_that_make_python_ignore_the_variable(): void
    {
        // -E, and -I which implies it, make Python ignore every PYTHON* variable, this one included (review
        // W2V-1). -E is the silent case: the script's folder stays on sys.path, so its sibling imports still work
        // and are cached. Only -B beside them still disables bytecode.
        $fixtures = [
            'app/Fixture/Flags.php' => <<<'PHP'
                <?php
                namespace App\Fixture;
                use Symfony\Component\Process\Process;
                class Flags
                {
                    public function run(): void
                    {
                        $env = ['PATH' => '/usr/bin:/bin', 'PYTHONDONTWRITEBYTECODE' => '1'];
                        (new Process([(string) config('lab.pipeline_python_path'), '-I', resource_path('lab/a.py')], null, $env))->run(); // offender: new Process (-I ignores PYTHONDONTWRITEBYTECODE)
                        (new Process([(string) config('lab.pipeline_python_path'), '-E', resource_path('lab/a.py')], null, $env))->run(); // offender: new Process (-E ignores PYTHONDONTWRITEBYTECODE)
                        $argv = [(string) config('lab.harmonization_python_path'), '-sE', resource_path('lab/b.py')];
                        (new Process($argv, null, $env))->run(); // offender: new Process (-sE ignores PYTHONDONTWRITEBYTECODE)
                        (new Process([(string) config('lab.pipeline_python_path'), '-I', '-B', resource_path('lab/a.py')], null, $env))->run();
                        (new Process([(string) config('lab.pipeline_python_path'), '-IB', resource_path('lab/a.py')], null, $env))->run();
                        (new Process([(string) config('lab.pipeline_python_path'), '-s', resource_path('lab/a.py'), '--input', 'x'], null, $env))->run();
                        Process::fromShellCommandline('python3 -I '.resource_path('lab/c.py'), null, $env)->run(); // offender: Process::fromShellCommandline (-I ignores PYTHONDONTWRITEBYTECODE)
                        exec('PYTHONDONTWRITEBYTECODE=1 python3 -E '.escapeshellarg(resource_path('lab/e.py'))); // offender: exec (-E ignores PYTHONDONTWRITEBYTECODE)
                        exec('python3 -I -B '.escapeshellarg(resource_path('lab/e.py')));
                        exec('python3 -IB '.escapeshellarg(resource_path('lab/e.py')));
                    }
                }
                PHP,
        ];

        $this->assertCount(5, $this->assertScan($fixtures, 10));
    }

    public function test_the_scan_reads_the_last_assignment_of_the_variable(): void
    {
        // Review W2V-2: a key set and later removed or overridden used to pass, because any '=> '1'' counted.
        $fixtures = [
            'app/Fixture/LastBase.php' => <<<'PHP'
                <?php
                namespace App\Fixture;
                class LastBase
                {
                    protected function environment(): array
                    {
                        return ['PATH' => '/usr/bin:/bin', 'PYTHONDONTWRITEBYTECODE' => '1'];
                    }
                }
                PHP,
            'app/Fixture/Last.php' => <<<'PHP'
                <?php
                namespace App\Fixture;
                use Symfony\Component\Process\Process;
                class Last extends LastBase
                {
                    public function removedAfterTheMerge(): void
                    {
                        $env = array_merge(array_fill_keys(array_keys(getenv()), false), ['PATH' => '/usr/bin:/bin', 'PYTHONDONTWRITEBYTECODE' => '1']);
                        $env['PYTHONDONTWRITEBYTECODE'] = false;
                        (new Process([(string) config('lab.pipeline_python_path'), resource_path('lab/a.py')], null, $env))->run(); // offender: new Process
                    }
                    public function removedByUnset(): void
                    {
                        $env = ['PATH' => '/usr/bin:/bin', 'PYTHONDONTWRITEBYTECODE' => '1'];
                        unset($env['PYTHONDONTWRITEBYTECODE']);
                        (new Process(['python3', 'x.py'], null, $env))->run(); // offender: new Process
                    }
                    public function setAgainAfterward(): void
                    {
                        $env = ['PATH' => '/usr/bin:/bin', 'PYTHONDONTWRITEBYTECODE' => ''];
                        $env['PYTHONDONTWRITEBYTECODE'] = '1';
                        (new Process(['python3', 'x.py'], null, $env))->run();
                    }
                    public function overriddenInTheMerge(): void
                    {
                        (new Process(['python3', 'x.py'], null, array_merge($this->environment(), ['PYTHONDONTWRITEBYTECODE' => false])))->run(); // offender: new Process
                    }
                    public function mergedOverAFalseValue(): void
                    {
                        (new Process(['python3', 'x.py'], null, array_merge(['PYTHONDONTWRITEBYTECODE' => false], $this->environment())))->run();
                    }
                    public function helperThenChanged(): void
                    {
                        $env = $this->environment();
                        $env['PYTHONDONTWRITEBYTECODE'] = '0';
                        (new Process(['python3', 'x.py'], null, $env))->run(); // offender: new Process
                    }
                    public function nestedVariable(): void
                    {
                        $base = ['PATH' => '/usr/bin', 'PYTHONDONTWRITEBYTECODE' => '1'];
                        $env = array_merge($base, ['PYTHONDONTWRITEBYTECODE' => false]);
                        (new Process(['python3', 'x.py'], null, $env))->run(); // offender: new Process
                    }
                }
                PHP,
        ];

        $this->assertCount(5, $this->assertScan($fixtures, 7));
    }

    public function test_the_scan_follows_a_command_passed_to_a_helper(): void
    {
        // Review W2V-2: the command arrives as a parameter, so the start itself names no Python. The scan reads
        // what the helper's callers pass ($this, self, static or parent calls in the class, its subclasses and
        // traits). A start whose command it cannot follow (a public helper with no such caller, a closure's
        // parameter) and that names nothing itself is reported too, unless its environment sets the variable.
        $fixtures = [
            'app/Fixture/Helpers.php' => <<<'PHP'
                <?php
                namespace App\Fixture;
                use Symfony\Component\Process\Process;
                class Helpers
                {
                    public function composite(): void
                    {
                        $this->start([(string) config('lab.pipeline_python_path'), resource_path('lab/composite_grass.py')])->run();
                        $this->start(['ffmpeg', '-version'])->run();
                        $this->guarded([(string) config('lab.pipeline_python_path'), resource_path('lab/g.py')])->run();
                        self::outer([(string) config('lab.harmonization_python_path'), resource_path('lab/b.py')]);
                        $this->encode(['ffmpeg', '-i', 'x.mov']);
                        $this->withEnvironment([(string) config('lab.pipeline_python_path'), resource_path('lab/h.py')], ['PYTHONDONTWRITEBYTECODE' => '1']);
                    }
                    // An environment passed in does not count: another caller could pass one without the variable.
                    private function withEnvironment(array $command, array $env): void
                    {
                        (new Process($command, null, $env))->run(); // offender: new Process
                    }
                    private function start(array $command): Process
                    {
                        return new Process($command, null, ['PATH' => '/usr/bin:/bin']); // offender: new Process
                    }
                    private function guarded(array $command): Process
                    {
                        return new Process($command, null, ['PATH' => '/usr/bin:/bin', 'PYTHONDONTWRITEBYTECODE' => '1']);
                    }
                    private static function outer(array $argv): void
                    {
                        static::inner($argv);
                    }
                    private static function inner(array $command): void
                    {
                        (new Process($command, null, ['PATH' => '/usr/bin:/bin']))->run(); // offender: new Process
                    }
                    protected function encode(array $command): void
                    {
                        (new Process($command, null, ['PATH' => '/usr/bin:/bin']))->run();
                    }
                    protected function unused(array $command): void
                    {
                        (new Process($command))->run();
                    }
                    public function runAnything(array $command): void
                    {
                        (new Process($command))->run(); // offender: new Process (command from a parameter the scan cannot follow)
                    }
                    public function runGuarded(array $command): void
                    {
                        (new Process($command, null, ['PYTHONDONTWRITEBYTECODE' => '1']))->run();
                    }
                    public function inClosure(): void
                    {
                        array_map(function (array $command) { (new Process($command))->run(); }, []); // offender: new Process (command from a parameter the scan cannot follow)
                    }
                }
                PHP,
            'app/Fixture/HelperBase.php' => <<<'PHP'
                <?php
                namespace App\Fixture;
                use Symfony\Component\Process\Process;
                abstract class HelperBase
                {
                    protected function launch(array $command): void
                    {
                        (new Process($command, null, ['PATH' => '/usr/bin:/bin']))->run(); // offender: new Process
                    }
                }
                PHP,
            'app/Fixture/HelperChild.php' => <<<'PHP'
                <?php
                namespace App\Fixture;
                class HelperChild extends HelperBase
                {
                    public function grass(): void
                    {
                        $this->launch([(string) config('lab.pipeline_python_path'), resource_path('lab/grass.py')]);
                    }
                }
                PHP,
        ];

        $this->assertCount(6, $this->assertScan($fixtures, 5));
    }

    public function test_interpreters_named_in_the_environment_are_the_documented_ones(): void
    {
        // Keeps ENVIRONMENT_INTERPRETERS (and the warning on it) complete: a new interpreter read from .env is
        // operator configuration the scan cannot check.
        $keys = [];
        foreach (glob(dirname(__DIR__, 2).'/config/*.php') ?: [] as $file) {
            preg_match_all('/\benv\(\s*[\'"]([A-Z0-9_]*PYTHON[A-Z0-9_]*)[\'"]/', file_get_contents($file), $found);
            array_push($keys, ...$found[1]);
        }
        sort($keys);

        $this->assertSame(self::ENVIRONMENT_INTERPRETERS, $keys, 'An interpreter path read from .env: add it to ENVIRONMENT_INTERPRETERS, whose note says what the operator must check');
    }

    /**
     * Scans the fixtures and checks the offenders against their "// offender: label" markers and the number of
     * Python starts seen.
     *
     * @param  array<string, string>  $fixtures
     * @return list<string> the offenders
     */
    private function assertScan(array $fixtures, int $sites): array
    {
        $expected = [];
        foreach ($fixtures as $path => $source) {
            foreach (explode("\n", $source) as $number => $line) {
                if (preg_match('~// offender: (.+)$~', $line, $marker)) {
                    $expected[] = $path.':'.($number + 1).' '.$marker[1];
                }
            }
        }
        $scan = $this->scan($fixtures);

        $this->assertSame($expected, $scan['offenders']);
        $this->assertCount($sites, $scan['sites'], implode("\n", $scan['sites']));

        return $scan['offenders'];
    }

    public function test_python_caches_an_imported_module_only_without_pythondontwritebytecode(): void
    {
        $python = (new ExecutableFinder)->find('python3');
        if ($python === null) {
            $this->markTestSkipped('python3 is not on PATH, so the interpreter cannot be observed here (CI and the QA image have it).');
        }
        // The lab services build their environment the same way: every inherited variable removed,
        // then a fixed set added.
        $environment = array_merge(array_fill_keys(array_keys(array_merge($_SERVER, $_ENV, getenv() ?: [])), false),
            ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'PYTHONNOUSERSITE' => '1']);
        $caches = [];
        foreach (['without' => [], 'with' => ['PYTHONDONTWRITEBYTECODE' => '1']] as $case => $extra) {
            $directory = sys_get_temp_dir().'/python-bytecode-'.$case.'-'.bin2hex(random_bytes(6));
            mkdir($directory, 0700);
            try {
                file_put_contents($directory.'/sibling.py', "VALUE = 'imported'\n");
                file_put_contents($directory.'/main.py', "import sibling\nprint(sibling.VALUE)\n");
                $process = new Process([$python, $directory.'/main.py'], $directory, array_merge($environment, $extra), null, 30);
                $process->run();

                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $this->assertSame("imported\n", $process->getOutput());
                $caches[$case] = is_dir($directory.'/__pycache__') ? array_map('basename', glob($directory.'/__pycache__/*') ?: []) : null;
            } finally {
                foreach (glob($directory.'/__pycache__/*') ?: [] as $file) {
                    unlink($file);
                }
                if (is_dir($directory.'/__pycache__')) {
                    rmdir($directory.'/__pycache__');
                }
                array_map('unlink', glob($directory.'/*.py') ?: []);
                rmdir($directory);
            }
        }

        $this->assertNotNull($caches['without'], 'without the variable Python writes __pycache__ beside the imported module');
        $this->assertCount(1, preg_grep('/^sibling\..+\.pyc$/', $caches['without']), implode(', ', $caches['without']));
        $this->assertNull($caches['with'], 'with PYTHONDONTWRITEBYTECODE=1 no __pycache__ is created');
    }

    public function test_host_scripts_and_runbooks_run_app_tree_python_without_bytecode(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (array_merge(glob($root.'/deploy.sh') ?: [], glob($root.'/scripts/*.sh') ?: [], glob($root.'/scripts/deploy/*') ?: [],
            glob($root.'/docs/*.md') ?: [], glob($root.'/docs/*.txt') ?: []) as $file) {
            if (is_file($file) && ! preg_match('/\.(py|php)$/', $file)) {
                $files[substr($file, strlen($root) + 1)] = file_get_contents($file);
            }
        }
        $offenders = $this->hostOffenders($files);

        $this->assertSame([], $offenders, "Host commands that run app-tree Python without -B:\n".implode("\n", $offenders));
    }

    public function test_the_host_scan_reports_interpreter_flags_that_make_python_ignore_the_variable(): void
    {
        // Review W2V-1: neither the export nor an inline PYTHONDONTWRITEBYTECODE=1 counts under -E or -I.
        $text = <<<'TEXT'
            export PYTHONDONTWRITEBYTECODE=1
            python3 "$stage/scripts/deploy/a.py" --check
            python3 -I "$stage/scripts/deploy/b.py" # offender
            python3 -E -s scripts/deploy/c.py # offender
            python3 -I -B scripts/deploy/d.py
            python3 -IB scripts/deploy/e.py
            /usr/bin/python3 -I -S /opt/repro-cutover-fence/production/cli.py tick
            '@
            PYTHONDONTWRITEBYTECODE=1 python3 -I scripts/deploy/f.py # offender
            PYTHONDONTWRITEBYTECODE=1 python3 -s scripts/deploy/g.py
            python3 scripts/deploy/h.py # offender
            TEXT;
        $expected = [];
        foreach (explode("\n", $text) as $number => $line) {
            if (str_ends_with($line, '# offender')) {
                $expected[] = 'scripts/deploy/Fixture.ps1:'.($number + 1).' '.$line;
            }
        }

        $this->assertCount(4, $expected);
        $this->assertSame($expected, $this->hostOffenders(['scripts/deploy/Fixture.ps1' => $text]));
    }

    /**
     * Host lines that run app-tree Python while it may write bytecode.
     *
     * @param  array<string, string>  $files  relative path => text
     * @return list<string> "path:line command"
     */
    private function hostOffenders(array $files): array
    {
        $offenders = [];
        foreach ($files as $file => $text) {
            $exported = false;
            foreach (preg_split('/\R/', $text) as $number => $line) {
                // A PowerShell here-string (@' ... '@) is a separate remote script.
                if (preg_match("/^\\s*'@|@'\\s*$/", $line)) {
                    $exported = false;
                }
                if (preg_match('/^\s*export\s+PYTHONDONTWRITEBYTECODE=1\s*$/', $line)) {
                    $exported = true;
                }
                preg_match_all(self::PYTHON_FILE_RUN, $line, $runs, PREG_SET_ORDER);
                foreach ($runs as [, $flags, $target]) {
                    // Absolute targets outside /var/www/backend (for example /opt) are not in the app tree.
                    $outsideApp = str_starts_with($target, '/') && ! str_starts_with($target, '/var/www/backend/');
                    // -E and -I make Python ignore the variable, exported or inline; only -B still holds (review W2V-1).
                    $ignoresVariable = preg_match('/[EI]/', $flags) === 1;
                    if (! $outsideApp && ! str_contains($flags, 'B')
                        && ($ignoresVariable || (! $exported && ! str_contains($line, 'PYTHONDONTWRITEBYTECODE=1')))) {
                        $offenders[] = $file.':'.($number + 1).' '.trim($line);
                    }
                }
            }
        }

        return $offenders;
    }

    /**
     * The Python process starts the scan saw, and those that may write bytecode: the environment's last
     * assignment of the variable is not '1', the argv carries -E or -I without -B, or the command comes from
     * a parameter the scan cannot follow and names nothing itself.
     *
     * @param  array<string, string>  $sources  relative path => PHP source
     * @return array{sites: list<string>, offenders: list<string>} "path:line label" each
     */
    private function scan(array $sources): array
    {
        $this->sources = $sources;
        $this->files = [];
        $this->classes = [];
        $this->callers = [];
        // Only files that can start a process are tokenized up front; parents, traits and callers load on demand.
        $candidates = array_keys(array_filter($sources,
            fn (string $source) => preg_match('/\bProcess\b|\b(?:'.implode('|', self::COMMAND_FUNCTIONS).')\s*\(/i', $source) === 1));
        foreach ($candidates as $path) {
            $this->parse($path, $sources[$path]);
        }
        $sites = [];
        $offenders = [];
        foreach ($candidates as $path) {
            foreach ($this->starts($path) as [$at, $label, $command, $environment, $inline]) {
                if ($command === null) {
                    continue;
                }
                $seen = [];
                $this->unfollowed = false;
                // The command follows parameters to what the callers pass; the environment never does, so an
                // environment that arrives as a parameter does not count as setting the variable.
                $commandRanges = $this->expand($path, $command[0], $command[1], $seen, [], true);
                $python = $this->namesPython($commandRanges);
                $unknown = ! $python && $this->unfollowed && $this->literals($commandRanges) === [];
                if (! $python && ! $unknown) {
                    continue;
                }
                $site = $path.':'.$this->files[$path]['tokens'][$at]->line.' '.$label;
                if ($python) {
                    $sites[] = $site;
                    if (($flag = $this->ignoringFlag($commandRanges)) !== null) {
                        $offenders[] = $site.' ('.$flag.' ignores PYTHONDONTWRITEBYTECODE)';

                        continue;
                    }
                }
                $seen = [];
                $disabled = ($environment !== null && $this->setsVariable($this->expand($path, $environment[0], $environment[1], $seen)))
                    || ($inline && $this->disablesInline($commandRanges));
                if (! $disabled) {
                    $offenders[] = $unknown ? $site.' (command from a parameter the scan cannot follow)' : $site;
                }
            }
        }

        return ['sites' => $sites, 'offenders' => $offenders];
    }

    /** Tokens without whitespace or comments, bracket pairs, namespace imports, classes and function bodies. */
    private function parse(string $path, string $source): void
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($source),
            fn (PhpToken $token) => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML])));
        $match = [];
        $depth = [];
        $stack = [];
        foreach ($tokens as $i => $token) {
            $depth[$i] = count($stack);
            if (in_array($token->text, ['(', '[', '{'], true) || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE])) {
                $stack[] = $i;
            } elseif (in_array($token->text, [')', ']', '}'], true) && $stack !== []) {
                $match[array_pop($stack)] = $i;
            }
        }
        $file = ['tokens' => $tokens, 'match' => $match, 'namespace' => '', 'uses' => [], 'functions' => [], 'signatures' => []];
        $classes = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $previous = $tokens[$i - 1] ?? null;
            if ($token->is(T_NAMESPACE) && ($tokens[$i + 1] ?? null)?->is([T_STRING, T_NAME_QUALIFIED])) {
                $file['namespace'] = $tokens[$i + 1]->text;
            } elseif ($token->is(T_USE) && $depth[$i] === 0 && ($tokens[$i + 1] ?? null)?->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $name = ltrim($tokens[$i + 1]->text, '\\');
                $alias = ($tokens[$i + 2] ?? null)?->is(T_AS) ? $tokens[$i + 3]->text : substr(strrchr('\\'.$name, '\\'), 1);
                $file['uses'][strtolower($alias)] = $name;
            } elseif ($token->is([T_FUNCTION, T_FN]) && ($signature = $this->signature($tokens, $match, $depth, $i)) !== null) {
                if ($token->is(T_FUNCTION)) {
                    $file['functions'][] = [$signature[0], $signature[1]];
                }
                $file['signatures'][] = $signature;
            } elseif ($token->is([T_CLASS, T_TRAIT, T_ENUM, T_INTERFACE]) && ! $previous?->is(T_DOUBLE_COLON)) {
                $classes[] = $i;
            }
        }
        $this->files[$path] = $file;
        foreach ($classes as $i) {
            $this->parseClass($path, $i, $depth);
        }
    }

    /** @param array<int, int> $depth */
    private function parseClass(string $path, int $at, array $depth): void
    {
        $file = $this->files[$path];
        $tokens = $file['tokens'];
        $anonymous = ($tokens[$at - 1] ?? null)?->is(T_NEW) ?? false;
        $name = $anonymous ? 'class@'.$at : $tokens[$at + 1]->text;
        $parent = null;
        $open = null;
        for ($i = $at + 1; $i < count($tokens); $i++) {
            if ($tokens[$i]->text === '(' && isset($file['match'][$i])) {
                $i = $file['match'][$i];
            } elseif ($tokens[$i]->is(T_EXTENDS)) {
                $parent = $tokens[$i + 1]->text;
            } elseif ($tokens[$i]->text === '{') {
                $open = $i;
                break;
            }
        }
        if ($open === null || ! isset($file['match'][$open])) {
            return;
        }
        $class = ['path' => $path, 'open' => $open, 'close' => $file['match'][$open],
            'parent' => $parent === null ? null : $this->resolve($path, $parent), 'traits' => [], 'methods' => []];
        for ($i = $open + 1; $i < $class['close']; $i++) {
            if ($depth[$i] !== $depth[$open] + 1) {
                continue;
            }
            if ($tokens[$i]->is(T_USE)) {
                for ($j = $i + 1; $j < $class['close'] && ! in_array($tokens[$j]->text, [';', '{'], true); $j++) {
                    if ($tokens[$j]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                        $class['traits'][] = $this->resolve($path, $tokens[$j]->text);
                    }
                }
            } elseif ($tokens[$i]->is(T_FUNCTION) && ($body = $this->functionBody($tokens, $file['match'], $i)) !== null) {
                $method = $tokens[$i + 1]->text === '&' ? $tokens[$i + 2]->text : $tokens[$i + 1]->text;
                $class['methods'][strtolower($method)] = $body;
            }
        }
        $this->classes[strtolower($anonymous ? $path.'#'.$name : $this->resolve($path, $name))] = $class;
    }

    /**
     * A function, method, closure or arrow function at $at: its body, its name (null for a closure or an arrow
     * function), its parameters in order and its visibility.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array<int, int>  $match
     * @param  array<int, int>  $depth
     * @return array{int, int, ?string, list<string>, string}|null
     */
    private function signature(array $tokens, array $match, array $depth, int $at): ?array
    {
        $open = $at + 1;
        while (isset($tokens[$open]) && $tokens[$open]->text !== '(') {
            $open++;
        }
        if (! isset($match[$open])) {
            return null;
        }
        if ($tokens[$at]->is(T_FN)) {
            $arrow = $match[$open] + 1;
            while (isset($tokens[$arrow]) && ! $tokens[$arrow]->is(T_DOUBLE_ARROW)) {
                $arrow++;
            }
            $body = isset($tokens[$arrow]) ? [$arrow, $this->expressionEnd($tokens, $match, $arrow + 1)] : null;
        } else {
            $body = $this->functionBody($tokens, $match, $at);
        }
        if ($body === null) {
            return null;
        }
        $parameters = [];
        for ($i = $open + 1; $i < $match[$open]; $i++) {
            if ($tokens[$i]->is(T_VARIABLE) && $depth[$i] === $depth[$open] + 1) {
                $parameters[] = $tokens[$i]->text;
            }
        }
        $name = null;
        for ($i = $at + 1; $i < $open; $i++) {
            if ($tokens[$i]->text !== '&') {
                $name = $tokens[$i]->text;
            }
        }
        $visibility = 'public';
        for ($i = $at - 1; ($tokens[$i] ?? null)?->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL]); $i--) {
            if ($tokens[$i]->is([T_PROTECTED, T_PRIVATE])) {
                $visibility = strtolower($tokens[$i]->text);
            }
        }

        return [$body[0], $body[1], $name, $parameters, $visibility];
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  array<int, int>  $match
     * @return array{int, int}|null the body's braces
     */
    private function functionBody(array $tokens, array $match, int $at): ?array
    {
        $i = $at + 1;
        while (isset($tokens[$i]) && $tokens[$i]->text !== '(') {
            $i++;
        }
        if (! isset($match[$i])) {
            return null;
        }
        for ($i = $match[$i] + 1; isset($tokens[$i]); $i++) {
            if ($tokens[$i]->is(T_USE) && isset($match[$i + 1])) {
                $i = $match[$i + 1];
            } elseif ($tokens[$i]->text === ';') {
                return null;
            } elseif ($tokens[$i]->text === '{') {
                return isset($match[$i]) ? [$i, $match[$i]] : null;
            }
        }

        return null;
    }

    /** A class name as written in $path, fully qualified with its namespace and imports. */
    private function resolve(string $path, string $name): string
    {
        $file = $this->files[$path];
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }
        $first = strtolower(explode('\\', $name)[0]);
        if (isset($file['uses'][$first])) {
            return $file['uses'][$first].substr($name, strlen($first));
        }

        return ltrim($file['namespace'].'\\'.$name, '\\');
    }

    private function isProcess(string $path, string $name): bool
    {
        return str_ends_with(strtolower('\\'.$this->resolve($path, $name)), '\\process');
    }

    /**
     * Process starts in a file: [token, label, command range, environment range, may the command itself disable bytecode].
     *
     * @return list<array{int, string, array{int, int}|null, array{int, int}|null, bool}>
     */
    private function starts(string $path): array
    {
        $tokens = $this->files[$path]['tokens'];
        $match = $this->files[$path]['match'];
        $names = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
        $sites = [];
        foreach ($tokens as $i => $token) {
            $next = $tokens[$i + 1] ?? null;
            $previous = $tokens[$i - 1] ?? null;
            if ($token->is(T_NEW) && $next?->is($names) && ($tokens[$i + 2]->text ?? '') === '(' && $this->isProcess($path, $next->text)) {
                $arguments = $this->arguments($tokens, $match, $i + 2);
                $sites[] = [$i, 'new Process', $arguments[0] ?? $arguments['command'] ?? null, $arguments[2] ?? $arguments['env'] ?? null, false];
            } elseif ($token->is($names) && $next?->is(T_DOUBLE_COLON) && ($tokens[$i + 2] ?? null)?->is(T_STRING)
                && ($tokens[$i + 3]->text ?? '') === '(' && $this->isProcess($path, $token->text)) {
                $method = $tokens[$i + 2]->text;
                if (strcasecmp($method, 'fromShellCommandline') === 0) {
                    $arguments = $this->arguments($tokens, $match, $i + 3);
                    $sites[] = [$i, 'Process::fromShellCommandline', $arguments[0] ?? $arguments['command'] ?? null, $arguments[2] ?? $arguments['env'] ?? null, false];
                } else {
                    // Laravel's Process facade: the call chain is the process, ->env([...]) its environment.
                    $end = $this->expressionEnd($tokens, $match, $i);
                    $sites[] = [$i, 'Process::'.$method, [$i, $end], $this->chainEnvironment($tokens, $match, $i, $end), false];
                }
            } elseif ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && ($next->text ?? '') === '('
                && in_array(strtolower(ltrim($token->text, '\\')), self::COMMAND_FUNCTIONS, true)
                && ! $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW])) {
                $function = strtolower(ltrim($token->text, '\\'));
                $arguments = $this->arguments($tokens, $match, $i + 1);
                $environment = $function === 'proc_open' ? ($arguments[4] ?? $arguments['env_vars'] ?? null) : null;
                $sites[] = [$i, $function, $arguments[0] ?? $arguments['command'] ?? null, $environment, true];
            }
        }

        return $sites;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  array<int, int>  $match
     * @return array<int|string, array{int, int}> positional and named argument ranges
     */
    private function arguments(array $tokens, array $match, int $open): array
    {
        $arguments = [];
        $position = 0;
        $start = $open + 1;
        $close = $match[$open] ?? $open;
        for ($i = $start; $i <= $close; $i++) {
            if ($i < $close && isset($match[$i])) {
                $i = $match[$i];

                continue;
            }
            if ($i === $close || $tokens[$i]->text === ',') {
                if ($i > $start) {
                    if ($tokens[$start]->is(T_STRING) && ($tokens[$start + 1]->text ?? '') === ':') {
                        $arguments[$tokens[$start]->text] = [$start + 2, $i];
                    } else {
                        $arguments[$position++] = [$start, $i];
                    }
                }
                $start = $i + 1;
            }
        }

        return $arguments;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  array<int, int>  $match
     */
    private function expressionEnd(array $tokens, array $match, int $from): int
    {
        for ($i = $from; isset($tokens[$i]); $i++) {
            if (isset($match[$i])) {
                $i = $match[$i];
            } elseif (in_array($tokens[$i]->text, [';', ',', ')', ']', '}'], true)) {
                return $i;
            }
        }

        return count($tokens);
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  array<int, int>  $match
     * @return array{int, int}|null
     */
    private function chainEnvironment(array $tokens, array $match, int $from, int $to): ?array
    {
        for ($i = $from; $i < $to; $i++) {
            if ($tokens[$i]->is(T_STRING) && strcasecmp($tokens[$i]->text, 'env') === 0 && ($tokens[$i + 1]->text ?? '') === '('
                && ($tokens[$i - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])) {
                return $this->arguments($tokens, $match, $i + 1)[0] ?? null;
            }
        }

        return null;
    }

    /**
     * The token ranges an expression draws on: itself, every assignment (or unset) in its enclosing method of a
     * variable it reads, and the body of every method it calls on $this, self, static or parent. With $follow,
     * a parameter also draws on what the method's callers pass for it.
     *
     * Each range carries its order: the positions of the calls that led to it. Comparing [...order, token]
     * keys tells which of two assignments of the variable runs later (setsVariable).
     *
     * @param  array<string, true>  $seen
     * @param  list<int>  $order
     * @return list<array{string, int, int, list<int>}>
     */
    private function expand(string $path, int $from, int $to, array &$seen, array $order = [], bool $follow = false): array
    {
        if (isset($seen[$path.':'.$from.':'.$to])) {
            return [];
        }
        $seen[$path.':'.$from.':'.$to] = true;
        $tokens = $this->files[$path]['tokens'];
        $ranges = [[$path, $from, $to, $order]];
        [$scopeFrom, $scopeTo] = $this->scope($path, $from);
        for ($i = $from; $i < $to; $i++) {
            if ($tokens[$i]->is(T_VARIABLE) && $tokens[$i]->text !== '$this') {
                foreach ($this->assignments($path, $tokens[$i]->text, $scopeFrom, $scopeTo) as [$a, $b]) {
                    array_push($ranges, ...$this->expand($path, $a, $b, $seen, $order, $follow));
                }
                if ($follow) {
                    foreach ($this->callerArguments($path, $i, $tokens[$i]->text) as [$caller, $a, $b]) {
                        array_push($ranges, ...$this->expand($caller, $a, $b, $seen, [], true));
                    }
                }
            } elseif ($tokens[$i]->is(T_STRING) && ($tokens[$i + 1]->text ?? '') === '('
                && ($tokens[$i - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])
                && in_array(strtolower($tokens[$i - 2]->text ?? ''), ['$this', 'self', 'static', 'parent'], true)
                && ($method = $this->method($path, $i, strtolower($tokens[$i - 2]->text), $tokens[$i]->text)) !== null) {
                array_push($ranges, ...$this->expand($method[0], $method[1], $method[2], $seen, [...$order, $i], $follow));
            }
        }

        return $ranges;
    }

    /**
     * What the callers pass for $variable when it is a parameter of the method around $at: every call on
     * $this, self, static or parent of a method with that name, from the declaring class, a subclass or a
     * class using the declaring trait. A closure's parameter, a function's, or a public method's (whose other
     * callers the scan cannot see) also marks the command unfollowed.
     *
     * @return list<array{string, int, int}> caller path and argument range
     */
    private function callerArguments(string $path, int $at, string $variable): array
    {
        $signature = null;
        foreach ($this->files[$path]['signatures'] as $candidate) {
            if ($candidate[0] <= $at && $at <= $candidate[1] && in_array($variable, $candidate[3], true)
                && ($signature === null || $candidate[0] > $signature[0])) {
                $signature = $candidate;
            }
        }
        if ($signature === null) {
            return [];
        }
        $key = $path.':'.$signature[0].':'.$variable;
        if (! isset($this->callers[$key])) {
            [, , $name, $parameters, $visibility] = $signature;
            $declaring = $this->classAt($path, $signature[0]);
            $found = [];
            $unfollowed = $name === null || $declaring === null || $visibility === 'public';
            if ($name !== null && $declaring !== null) {
                $index = array_search($variable, $parameters, true);
                $pattern = '/(?:\$this\s*\??->|\b(?:self|static|parent)\s*::)\s*'.preg_quote($name, '/').'\s*\(/i';
                foreach ($this->sources as $callerPath => $source) {
                    if (preg_match($pattern, $source) !== 1) {
                        continue;
                    }
                    if (! isset($this->files[$callerPath])) {
                        $this->parse($callerPath, $source);
                    }
                    $tokens = $this->files[$callerPath]['tokens'];
                    foreach ($tokens as $i => $token) {
                        if (! $token->is(T_STRING) || strcasecmp($token->text, $name) !== 0 || ($tokens[$i + 1]->text ?? '') !== '('
                            || ! ($tokens[$i - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])
                            || ! in_array(strtolower($tokens[$i - 2]->text ?? ''), ['$this', 'self', 'static', 'parent'], true)
                            || ($caller = $this->classAt($callerPath, $i)) === null || ! $this->related($caller, $declaring)) {
                            continue;
                        }
                        $arguments = $this->arguments($tokens, $this->files[$callerPath]['match'], $i + 1);
                        if (($argument = $arguments[$index] ?? $arguments[substr($variable, 1)] ?? null) !== null) {
                            $found[] = [$callerPath, ...$argument];
                        }
                    }
                }
            }
            $this->callers[$key] = [$found, $unfollowed];
        }
        $this->unfollowed = $this->unfollowed || $this->callers[$key][1];

        return $this->callers[$key][0];
    }

    /** The innermost class, trait or enum around $at in $path, as a lower-case key of $classes. */
    private function classAt(string $path, int $at): ?string
    {
        $key = null;
        foreach ($this->classes as $candidate => $class) {
            if ($class['path'] === $path && $class['open'] < $at && $at < $class['close']
                && ($key === null || $class['open'] > $this->classes[$key]['open'])) {
                $key = $candidate;
            }
        }

        return $key;
    }

    /** Whether $class is $declaring, extends it or uses it as a trait (directly or through a parent). */
    private function related(string $class, string $declaring): bool
    {
        $key = $class;
        for ($guard = 0; $key !== null && $this->load($key) && $guard < 16; $guard++) {
            if ($key === $declaring || in_array($declaring, array_map('strtolower', $this->classes[$key]['traits']), true)) {
                return true;
            }
            $key = $this->classes[$key]['parent'] === null ? null : strtolower($this->classes[$key]['parent']);
        }

        return false;
    }

    /** @return array{int, int} the outermost function body around $at, or the whole file */
    private function scope(string $path, int $at): array
    {
        $scope = null;
        foreach ($this->files[$path]['functions'] as [$open, $close]) {
            if ($open <= $at && $at <= $close && ($scope === null || $open < $scope[0])) {
                $scope = [$open, $close];
            }
        }

        return $scope ?? [0, count($this->files[$path]['tokens'])];
    }

    /** @return list<array{int, int}> statements that assign $variable (or one of its elements), or unset an element */
    private function assignments(string $path, string $variable, int $from, int $to): array
    {
        $tokens = $this->files[$path]['tokens'];
        $match = $this->files[$path]['match'];
        $found = [];
        for ($i = $from; $i < $to; $i++) {
            if (! $tokens[$i]->is(T_VARIABLE) || $tokens[$i]->text !== $variable
                || ($tokens[$i - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])) {
                continue;
            }
            if (($tokens[$i - 1]->text ?? '') === '(' && ($tokens[$i - 2] ?? null)?->is(T_UNSET) && isset($match[$i - 1])) {
                $found[] = [$i - 2, $match[$i - 1] + 1];

                continue;
            }
            $j = $i + 1;
            while (($tokens[$j]->text ?? '') === '[' && isset($match[$j])) {
                $j = $match[$j] + 1;
            }
            if (isset($tokens[$j]) && ($tokens[$j]->text === '=' || $tokens[$j]->is([T_CONCAT_EQUAL, T_PLUS_EQUAL, T_COALESCE_EQUAL]))) {
                $found[] = [$i, $this->expressionEnd($tokens, $match, $j + 1)];
            }
        }

        return $found;
    }

    /** @return array{string, int, int}|null the body of $name as seen from the class around $at */
    private function method(string $path, int $at, string $receiver, string $name): ?array
    {
        $key = $this->classAt($path, $at);
        if ($key !== null && $receiver === 'parent') {
            $key = $this->classes[$key]['parent'] === null ? null : strtolower($this->classes[$key]['parent']);
        }
        for ($guard = 0; $key !== null && $this->load($key) && $guard < 16; $guard++) {
            $class = $this->classes[$key];
            foreach ([$key, ...array_map('strtolower', $class['traits'])] as $owner) {
                if ($this->load($owner) && isset($this->classes[$owner]['methods'][strtolower($name)])) {
                    return [$this->classes[$owner]['path'], ...$this->classes[$owner]['methods'][strtolower($name)]];
                }
            }
            $key = $class['parent'] === null ? null : strtolower($class['parent']);
        }

        return null;
    }

    /** Parse the not yet tokenized source that declares $class (a lower-case FQCN); false when none does. */
    private function load(string $class): bool
    {
        $short = substr(strrchr('\\'.$class, '\\'), 1);
        foreach ($this->sources as $path => $source) {
            if (! isset($this->classes[$class]) && ! isset($this->files[$path]) && strcasecmp(basename($path, '.php'), $short) === 0) {
                $this->parse($path, $source);
            }
        }

        return isset($this->classes[$class]);
    }

    /** @param list<array{string, int, int}> $ranges */
    private function namesPython(array $ranges): bool
    {
        foreach ($this->literals($ranges) as $literal) {
            if (preg_match('/python_?path$/i', $literal) || preg_match('~(?:^|[\s/])python[0-9.]*(?:$|\s)~i', $literal)
                || preg_match('/\.py(?:$|[\s\'"])/i', $literal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the last assignment of the variable is the literal '1': a later key in an array_merge, a later
     * element assignment or an unset overrides an earlier one (review W2V-2).
     *
     * @param  list<array{string, int, int, list<int>}>  $ranges
     */
    private function setsVariable(array $ranges): bool
    {
        $last = null;
        foreach ($ranges as [$path, $from, $to, $order]) {
            $tokens = $this->files[$path]['tokens'];
            for ($i = $from; $i < $to; $i++) {
                if ($this->literal($tokens[$i]) !== 'PYTHONDONTWRITEBYTECODE') {
                    continue;
                }
                if (($tokens[$i + 1] ?? null)?->is(T_DOUBLE_ARROW)) {
                    $value = $i + 2;
                } elseif (($tokens[$i + 1]->text ?? '') === ']' && ($tokens[$i + 2]->text ?? '') === '=') {
                    $value = $i + 3;
                } elseif (($tokens[$i + 1]->text ?? '') === ']' && $tokens[$from]->is(T_UNSET)) {
                    $value = null;
                } else {
                    continue;
                }
                // Only a value that is the literal '1' and nothing more ('1'.$x is not).
                $set = $value !== null && $this->literal($tokens[$value] ?? null) === '1'
                    && in_array($tokens[$value + 1]->text ?? '', [',', ']', ')', ';'], true);
                $key = [...$order, $i];
                if ($last === null || $this->runsBefore($last[0], $key)) {
                    $last = [$key, $set];
                }
            }
        }

        return $last !== null && $last[1];
    }

    /**
     * @param  list<int>  $a
     * @param  list<int>  $b
     */
    private function runsBefore(array $a, array $b): bool
    {
        foreach ($a as $n => $position) {
            if (! isset($b[$n])) {
                return false;
            }
            if ($position !== $b[$n]) {
                return $position < $b[$n];
            }
        }

        return count($a) < count($b);
    }

    /** A shell command string that runs python -B or sets the variable inline. @param list<array{string, int, int, list<int>}> $ranges */
    private function disablesInline(array $ranges): bool
    {
        foreach ($this->literals($ranges) as $literal) {
            if (str_contains($literal, 'PYTHONDONTWRITEBYTECODE=1') || preg_grep('/^-[A-Za-z]*B[A-Za-z]*$/', $this->words($literal)) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first flag word that makes Python ignore PYTHON* variables (-E, or -I which implies it, alone or
     * combined), unless a word carries -B (review W2V-1). A script's own option spelled like one is reported too.
     *
     * @param  list<array{string, int, int, list<int>}>  $ranges
     */
    private function ignoringFlag(array $ranges): ?string
    {
        $words = [];
        foreach ($this->literals($ranges) as $literal) {
            array_push($words, ...$this->words($literal));
        }
        if (preg_grep('/^-[A-Za-z]*B[A-Za-z]*$/', $words) !== []) {
            return null;
        }

        return array_values(preg_grep('/^-[A-Za-z]*[EI][A-Za-z]*$/', $words))[0] ?? null;
    }

    /** @return list<string> */
    private function words(string $literal): array
    {
        return preg_split('/\s+/', $literal, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param  list<array{string, int, int, list<int>}>  $ranges
     * @return list<string>
     */
    private function literals(array $ranges): array
    {
        $literals = [];
        foreach ($ranges as [$path, $from, $to]) {
            for ($i = $from; $i < $to; $i++) {
                $literal = $this->literal($this->files[$path]['tokens'][$i]);
                if ($literal !== null) {
                    $literals[] = $literal;
                }
            }
        }

        return $literals;
    }

    private function literal(?PhpToken $token): ?string
    {
        if ($token?->is(T_CONSTANT_ENCAPSED_STRING)) {
            return substr($token->text, 1, -1);
        }

        return $token?->is(T_ENCAPSED_AND_WHITESPACE) ? $token->text : null;
    }
}
