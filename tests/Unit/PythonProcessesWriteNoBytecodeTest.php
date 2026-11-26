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
 */
class PythonProcessesWriteNoBytecodeTest extends TestCase
{
    private const PHP_TREES = ['app', 'bootstrap', 'config', 'database', 'routes'];

    private const COMMAND_FUNCTIONS = ['proc_open', 'exec', 'shell_exec', 'system', 'passthru', 'popen'];

    /** python, flags, then a .py target; `python3 -` (a script on stdin) has no target and is not matched. */
    private const PYTHON_FILE_RUN = '~\bpython[0-9.]*((?:\s+-[A-Za-z]+)*)\s+["\']?([^\s"\'|;&`]+\.py)\b~';

    /** @var array<string, string> relative path => PHP source */
    private array $sources = [];

    /** @var array<string, array{tokens: list<PhpToken>, match: array<int, int>, namespace: string, uses: array<string, string>, functions: list<array{int, int}>}> */
    private array $files = [];

    /** @var array<string, array{path: string, open: int, close: int, parent: ?string, traits: list<string>, methods: array<string, array{int, int}>}> */
    private array $classes = [];

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

        $offenders = $this->offenders($sources);

        $this->assertSame([], $offenders, count($offenders)." Python process(es) without 'PYTHONDONTWRITEBYTECODE' => '1' in their environment:\n".implode("\n", $offenders));
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
        $expected = [];
        foreach ($fixtures as $path => $source) {
            foreach (explode("\n", $source) as $number => $line) {
                if (preg_match('~// offender: (.+)$~', $line, $marker)) {
                    $expected[] = $path.':'.($number + 1).' '.$marker[1];
                }
            }
        }

        $this->assertCount(8, $expected);
        $this->assertSame($expected, $this->offenders($fixtures));
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
        $files = array_merge(glob($root.'/deploy.sh') ?: [], glob($root.'/scripts/*.sh') ?: [], glob($root.'/scripts/deploy/*') ?: [],
            glob($root.'/docs/*.md') ?: [], glob($root.'/docs/*.txt') ?: []);
        $offenders = [];
        foreach ($files as $file) {
            if (! is_file($file) || preg_match('/\.(py|php)$/', $file)) {
                continue;
            }
            $exported = false;
            foreach (preg_split('/\R/', file_get_contents($file)) as $number => $line) {
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
                    if (! $outsideApp && ! str_contains($flags, 'B') && ! $exported && ! str_contains($line, 'PYTHONDONTWRITEBYTECODE=1')) {
                        $offenders[] = substr($file, strlen($root) + 1).':'.($number + 1).' '.trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "Host commands that run app-tree Python without -B:\n".implode("\n", $offenders));
    }

    /**
     * Every process start whose command names Python and whose environment lacks the variable.
     *
     * @param  array<string, string>  $sources  relative path => PHP source
     * @return list<string> "path:line label"
     */
    private function offenders(array $sources): array
    {
        $this->sources = $sources;
        $this->files = [];
        $this->classes = [];
        // Only files that can start a process are tokenized up front; parents and traits load on demand.
        $candidates = array_keys(array_filter($sources,
            fn (string $source) => preg_match('/\bProcess\b|\b(?:'.implode('|', self::COMMAND_FUNCTIONS).')\s*\(/i', $source) === 1));
        foreach ($candidates as $path) {
            $this->parse($path, $sources[$path]);
        }
        $offenders = [];
        foreach ($candidates as $path) {
            foreach ($this->starts($path) as [$at, $label, $command, $environment, $inline]) {
                if ($command === null) {
                    continue;
                }
                $seen = [];
                $commandRanges = $this->expand($path, $command[0], $command[1], $seen);
                if (! $this->namesPython($commandRanges)) {
                    continue;
                }
                $seen = [];
                $disabled = ($environment !== null && $this->setsVariable($this->expand($path, $environment[0], $environment[1], $seen)))
                    || ($inline && $this->disablesInline($commandRanges));
                if (! $disabled) {
                    $offenders[] = $path.':'.$this->files[$path]['tokens'][$at]->line.' '.$label;
                }
            }
        }

        return $offenders;
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
        $file = ['tokens' => $tokens, 'match' => $match, 'namespace' => '', 'uses' => [], 'functions' => []];
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
            } elseif ($token->is(T_FUNCTION) && ($body = $this->functionBody($tokens, $match, $i)) !== null) {
                $file['functions'][] = $body;
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
     * The token ranges an expression draws on: itself, every assignment in its enclosing method to a
     * variable it reads, and the body of every method it calls on $this, self, static or parent.
     *
     * @param  array<string, true>  $seen
     * @return list<array{string, int, int}>
     */
    private function expand(string $path, int $from, int $to, array &$seen): array
    {
        if (isset($seen[$path.':'.$from.':'.$to])) {
            return [];
        }
        $seen[$path.':'.$from.':'.$to] = true;
        $tokens = $this->files[$path]['tokens'];
        $ranges = [[$path, $from, $to]];
        [$scopeFrom, $scopeTo] = $this->scope($path, $from);
        for ($i = $from; $i < $to; $i++) {
            if ($tokens[$i]->is(T_VARIABLE) && $tokens[$i]->text !== '$this') {
                foreach ($this->assignments($path, $tokens[$i]->text, $scopeFrom, $scopeTo) as [$a, $b]) {
                    array_push($ranges, ...$this->expand($path, $a, $b, $seen));
                }
            } elseif ($tokens[$i]->is(T_STRING) && ($tokens[$i + 1]->text ?? '') === '('
                && ($tokens[$i - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])
                && in_array(strtolower($tokens[$i - 2]->text ?? ''), ['$this', 'self', 'static', 'parent'], true)
                && ($method = $this->method($path, $i, strtolower($tokens[$i - 2]->text), $tokens[$i]->text)) !== null) {
                array_push($ranges, ...$this->expand($method[0], $method[1], $method[2], $seen));
            }
        }

        return $ranges;
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

    /** @return list<array{int, int}> statements that assign $variable (or one of its elements) */
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
        $key = null;
        foreach ($this->classes as $candidate => $class) {
            if ($class['path'] === $path && $class['open'] < $at && $at < $class['close']
                && ($key === null || $class['open'] > $this->classes[$key]['open'])) {
                $key = $candidate;
            }
        }
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

    /** @param list<array{string, int, int}> $ranges */
    private function setsVariable(array $ranges): bool
    {
        foreach ($ranges as [$path, $from, $to]) {
            $tokens = $this->files[$path]['tokens'];
            for ($i = $from; $i < $to; $i++) {
                if ($this->literal($tokens[$i]) !== 'PYTHONDONTWRITEBYTECODE') {
                    continue;
                }
                if (($tokens[$i + 1] ?? null)?->is(T_DOUBLE_ARROW) && $this->literal($tokens[$i + 2] ?? null) === '1') {
                    return true;
                }
                if (($tokens[$i + 1]->text ?? '') === ']' && ($tokens[$i + 2]->text ?? '') === '=' && $this->literal($tokens[$i + 3] ?? null) === '1') {
                    return true;
                }
            }
        }

        return false;
    }

    /** A shell command string that runs python -B or sets the variable inline. @param list<array{string, int, int}> $ranges */
    private function disablesInline(array $ranges): bool
    {
        foreach ($this->literals($ranges) as $literal) {
            if (str_contains($literal, 'PYTHONDONTWRITEBYTECODE=1') || preg_match('/(?:^|\s)-B(?:\s|$)/', $literal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{string, int, int}>  $ranges
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
