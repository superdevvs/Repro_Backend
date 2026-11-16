<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * [program:repro-lab-tour] in scripts/install-studio-runtime.sh (spec 11.6, D5).
 *
 * The installer itself needs the production host (fixed app path, x86_64, apt, cron, Supervisor, sg, www-data), so
 * the render tests run the script's own lines in a temporary directory: the block from worker.sh to the end of the
 * supervisord.conf rewrite, and the final status block. Only the tour-count call to /usr/bin/sg is pointed at a stub;
 * no Supervisor, sudo or artisan runs.
 */
class IguideLabTourInstallerTest extends TestCase
{
    private const SG_COUNT = "/usr/bin/sg www-data -c '/usr/bin/php artisan iguide-lab:tour-workers --count'";

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/iguide-installer-'.Str::random(12);
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', '--', $this->dir]))->run();
        parent::tearDown();
    }

    public function test_installer_defines_the_tour_program(): void
    {
        $script = $this->script();
        foreach (['[program:repro-lab-tour]', 'paused-lab-tour', 'queue:work iguide-lab-tour --queue=iguide-lab-tour', '--timeout=660',
            'stopwaitsecs=720', 'numprocs=$tour_workers', 'test -f "$app/app/Jobs/ProcessIguideLabTourSurvey.php"',
            'test -f app/Jobs/ProcessIguideLabTourSurvey.php', 'test ! -f "$runtime/paused"', 'test ! -f "$runtime/paused-lab-tour"',
            self::SG_COUNT." 2>/dev/null || true)\"", "status 'repro-lab-tour:*' || true"] as $needle) {
            $this->assertStringContainsString($needle, $script, $needle);
        }
        $block = $this->between($script, "tour_program=''\n", 'cat > "$runtime/supervisord.conf" <<EOF');
        $this->assertStringNotContainsString('exit 1', $block, 'the tour block never aborts the Studio reinstall');
        $this->assertStringNotContainsString('exit 1', $this->tail($script));
        // The Studio worker and program are unchanged.
        $this->assertStringContainsString("exec /usr/bin/sg www-data -c 'exec /usr/bin/php -d memory_limit=1024M artisan queue:work studio --queue=studio --sleep=2 --tries=3 --timeout=7200 --memory=768 --max-time=3600'", $script);
        $this->assertStringContainsString(str_replace(['RUNTIME', 'APP'], ['$runtime', '$app'], $this->studioConfig()), $script);
        $this->assertStringContainsString("supervisorctl -c \"\$runtime/supervisord.conf\" status repro-studio\n", $script);

        $syntax = new Process(['bash', '-n', base_path('scripts/install-studio-runtime.sh')]);
        $syntax->run();
        $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
    }

    public function test_rendered_config_adds_the_tour_program_and_keeps_studio_identical(): void
    {
        [$app, $runtime] = $this->layout(withTourJob: true);
        $render = $this->render($app, $runtime, "3\n");
        $this->assertTrue($render->isSuccessful(), $render->getErrorOutput());
        $this->assertSame('', $render->getErrorOutput());
        $this->assertSame($app.'|www-data|-c|/usr/bin/php artisan iguide-lab:tour-workers --count|', file_get_contents($this->dir.'/sg.log'),
            'the count is read once, from the app directory, through sg www-data');

        $this->assertSame($this->expand($this->studioConfig().$this->tourConfig(3), $app, $runtime), file_get_contents($runtime.'/supervisord.conf'));
        $this->assertSame($this->expand($this->studioWorker(), $app, $runtime), file_get_contents($runtime.'/worker.sh'));
        $this->assertSame($this->expand($this->tourWorker(), $app, $runtime), file_get_contents($runtime.'/lab-tour-worker.sh'));
        $this->assertSame('0755', sprintf('%04o', fileperms($runtime.'/lab-tour-worker.sh') & 07777));
        $syntax = new Process(['sh', '-n', $runtime.'/lab-tour-worker.sh']);
        $syntax->run();
        $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());

        // The worker reaches its exec only without either pause file and with the tour job present.
        $this->assertSame([0, 'reached-exec'], $this->runWorker($runtime), 'control: nothing paused');
        foreach (['paused', 'paused-lab-tour'] as $pause) {
            touch($runtime.'/'.$pause);
            [$exit, $output] = $this->runWorker($runtime);
            $this->assertSame('', $output, $pause);
            $this->assertNotSame(0, $exit, $pause);
            unlink($runtime.'/'.$pause);
        }
        unlink($app.'/app/Jobs/ProcessIguideLabTourSurvey.php');
        [$exit, $output] = $this->runWorker($runtime);
        $this->assertSame('', $output, 'tour job missing');
        $this->assertNotSame(0, $exit, 'tour job missing');

        // A reinstall renders the same files again (the program survives reinstalls), with the new count.
        touch($app.'/app/Jobs/ProcessIguideLabTourSurvey.php');
        $render = $this->render($app, $runtime, '8');
        $this->assertTrue($render->isSuccessful(), $render->getErrorOutput());
        $this->assertSame($this->expand($this->studioConfig().$this->tourConfig(8), $app, $runtime), file_get_contents($runtime.'/supervisord.conf'));
    }

    public function test_without_the_tour_job_or_a_valid_count_only_studio_is_rendered(): void
    {
        [$app, $runtime] = $this->layout(withTourJob: false);
        $render = $this->render($app, $runtime, '3');
        $this->assertTrue($render->isSuccessful(), $render->getErrorOutput());
        $this->assertFileDoesNotExist($this->dir.'/sg.log', 'no artisan call without the tour job');
        $this->assertSame($this->expand($this->studioConfig(), $app, $runtime), file_get_contents($runtime.'/supervisord.conf'), 'byte-identical to the pre-D1 file');
        $this->assertFileDoesNotExist($runtime.'/lab-tour-worker.sh');
        $this->assertSame('', $render->getErrorOutput());

        touch($app.'/app/Jobs/ProcessIguideLabTourSurvey.php');
        foreach (['0', '9', '10', '', 'abc', '3 4', "3\n4", ' 3', '-1'] as $count) {
            $render = $this->render($app, $runtime, $count);
            $this->assertTrue($render->isSuccessful(), var_export($count, true).': '.$render->getErrorOutput());
            $this->assertStringContainsString('Warning: unexpected tour worker count', $render->getErrorOutput(), var_export($count, true));
            $this->assertSame($this->expand($this->studioConfig(), $app, $runtime), file_get_contents($runtime.'/supervisord.conf'), var_export($count, true));
            $this->assertFileDoesNotExist($runtime.'/lab-tour-worker.sh');
        }
        // A failing artisan call (non-zero exit, output on stderr) does not abort the reinstall either.
        $render = $this->render($app, $runtime, '4', failing: true);
        $this->assertTrue($render->isSuccessful(), $render->getErrorOutput());
        $this->assertStringContainsString("Warning: unexpected tour worker count ''", $render->getErrorOutput());
        $this->assertStringNotContainsString('artisan failed', $render->getErrorOutput(), 'the count call\'s stderr is discarded');
        $this->assertSame($this->expand($this->studioConfig(), $app, $runtime), file_get_contents($runtime.'/supervisord.conf'));
    }

    public function test_a_stopped_tour_program_does_not_abort_the_installer(): void
    {
        [$app, $runtime] = $this->layout(withTourJob: true);
        $bin = $this->dir.'/bin';
        mkdir($bin);
        // supervisorctl status exits 3 for a STOPPED program (the runbook's pause state).
        $this->stub($bin.'/supervisorctl', 'echo "supervisorctl $*" >> "$STUB_LOG"; case "$*" in *repro-lab-tour*) exit 3 ;; esac; exit 0');
        $this->stub($bin.'/sleep', 'exit 0');
        $this->stub($bin.'/php', 'echo "php $*" >> "$STUB_LOG"; exit 0');
        $log = $this->dir.'/tail.log';
        foreach (["\n[program:repro-lab-tour]", ''] as $tourProgram) {
            @unlink($log);
            $script = "set -Eeuo pipefail\napp=".escapeshellarg($app)."\nruntime=".escapeshellarg($runtime)."\ntour_program=".escapeshellarg($tourProgram)."\n".$this->tail($this->script());
            file_put_contents($this->dir.'/tail.sh', $script);
            $tail = new Process(['bash', $this->dir.'/tail.sh'], null, ['PATH' => $bin.':'.getenv('PATH'), 'STUB_LOG' => $log]);
            $tail->run();
            $this->assertTrue($tail->isSuccessful(), $tail->getErrorOutput());
            $calls = file($log, FILE_IGNORE_NEW_LINES);
            $expected = ["supervisorctl -c $runtime/supervisord.conf reread", "supervisorctl -c $runtime/supervisord.conf update",
                "supervisorctl -c $runtime/supervisord.conf status repro-studio"];
            if ($tourProgram !== '') $expected[] = "supervisorctl -c $runtime/supervisord.conf status repro-lab-tour:*";
            $expected[] = "php $app/scripts/studio-runtime-check.php";
            $this->assertSame($expected, $calls, 'the runtime check still runs after a stopped tour program');
        }
    }

    private function script(): string
    {
        return (string) file_get_contents(base_path('scripts/install-studio-runtime.sh'));
    }

    /** From the worker.sh rewrite through the supervisord.conf rewrite (the tour block sits between them). */
    private function renderBlock(): string
    {
        $block = $this->between($this->script(), 'cat > "$runtime/worker.sh" <<EOF', 'cat > "$runtime/ensure-supervisor.sh" <<EOF', true);
        $this->assertSame(1, substr_count($block, self::SG_COUNT));

        return str_replace(self::SG_COUNT, "\"\$TOUR_SG\" www-data -c '/usr/bin/php artisan iguide-lab:tour-workers --count'", $block);
    }

    /** From the Supervisor reread to the end of the script. */
    private function tail(string $script): string
    {
        $start = strpos($script, 'supervisorctl -c "$runtime/supervisord.conf" reread');
        $this->assertNotFalse($start);

        return substr($script, $start);
    }

    private function between(string $text, string $from, string $to, bool $includeFrom = false): string
    {
        $start = strpos($text, $from);
        $this->assertNotFalse($start, $from);
        $end = strpos($text, $to, $start + strlen($from));
        $this->assertNotFalse($end, $to);

        return substr($text, $includeFrom ? $start : $start + strlen($from), $end - ($includeFrom ? $start : $start + strlen($from)));
    }

    /** @return array{0: string, 1: string} app and runtime directories */
    private function layout(bool $withTourJob): array
    {
        $app = $this->dir.'/app root';
        $runtime = $this->dir.'/runtime';
        mkdir($app.'/app/Jobs', 0700, true);
        mkdir($runtime.'/logs', 0700, true);
        touch($app.'/app/Jobs/ProcessStudioWorkspace.php');
        if ($withTourJob) touch($app.'/app/Jobs/ProcessIguideLabTourSurvey.php');

        return [$app, $runtime];
    }

    private function render(string $app, string $runtime, string $count, bool $failing = false): Process
    {
        @unlink($this->dir.'/sg.log');
        @unlink($runtime.'/lab-tour-worker.sh');
        $this->stub($this->dir.'/sg', 'printf "%s|" "$PWD" "$@" > "$STUB_SG_LOG"; if [ "$STUB_FAIL" = 1 ]; then echo "artisan failed" >&2; exit 1; fi; printf "%s" "$STUB_COUNT"');
        $script = "set -Eeuo pipefail\nunset CDPATH\numask 002\napp=".escapeshellarg($app)."\nruntime=".escapeshellarg($runtime)."\n".$this->renderBlock();
        file_put_contents($this->dir.'/render.sh', $script);
        $process = new Process(['bash', $this->dir.'/render.sh'], $this->dir, ['TOUR_SG' => $this->dir.'/sg', 'STUB_SG_LOG' => $this->dir.'/sg.log',
            'STUB_COUNT' => $count, 'STUB_FAIL' => $failing ? '1' : '0']);
        $process->run();

        return $process;
    }

    /** Run a copy of the rendered worker whose final exec only prints a marker. @return array{0: int, 1: string} */
    private function runWorker(string $runtime): array
    {
        $text = (string) file_get_contents($runtime.'/lab-tour-worker.sh');
        $this->assertSame(1, preg_match_all('/^exec \/usr\/bin\/sg www-data .*$/m', $text));
        file_put_contents($this->dir.'/worker-probe.sh', preg_replace('/^exec \/usr\/bin\/sg www-data .*$/m', 'echo reached-exec', $text));
        $worker = new Process(['sh', $this->dir.'/worker-probe.sh']);
        $worker->run();

        return [(int) $worker->getExitCode(), trim($worker->getOutput())];
    }

    private function stub(string $path, string $body): void
    {
        file_put_contents($path, "#!/bin/sh\n".$body."\n");
        chmod($path, 0755);
    }

    private function expand(string $template, string $app, string $runtime): string
    {
        return str_replace(['RUNTIME', 'APP'], [$runtime, $app], $template);
    }

    /** supervisord.conf as rendered before D1 (scripts/install-studio-runtime.sh:99-132 at e48564ed). */
    private function studioConfig(): string
    {
        return <<<'CONF'
[unix_http_server]
file=RUNTIME/supervisor.sock
chmod=0700

[supervisord]
logfile=RUNTIME/logs/supervisord.log
logfile_maxbytes=10MB
logfile_backups=3
pidfile=RUNTIME/supervisord.pid
childlogdir=RUNTIME/logs
umask=002

[rpcinterface:supervisor]
supervisor.rpcinterface_factory=supervisor.rpcinterface:make_main_rpcinterface

[supervisorctl]
serverurl=unix://RUNTIME/supervisor.sock

[program:repro-studio]
command=RUNTIME/worker.sh
directory=APP
autostart=true
autorestart=true
startsecs=5
startretries=10
stopasgroup=true
killasgroup=true
stopwaitsecs=7260
redirect_stderr=true
stdout_logfile=RUNTIME/logs/worker.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5

CONF;
    }

    /** worker.sh as rendered before D1 (scripts/install-studio-runtime.sh:88-97 at e48564ed). */
    private function studioWorker(): string
    {
        return <<<'SH'
#!/bin/sh
set -eu
cd "APP"
test -f app/Jobs/ProcessStudioWorkspace.php
test ! -f "RUNTIME/paused"
umask 002
export PATH="RUNTIME/bin:/usr/local/bin:/usr/bin:/bin"
exec /usr/bin/sg www-data -c 'exec /usr/bin/php -d memory_limit=1024M artisan queue:work studio --queue=studio --sleep=2 --tries=3 --timeout=7200 --memory=768 --max-time=3600'

SH;
    }

    private function tourWorker(): string
    {
        return <<<'SH'
#!/bin/sh
set -eu
cd "APP"
test -f app/Jobs/ProcessIguideLabTourSurvey.php
test ! -f "RUNTIME/paused"
test ! -f "RUNTIME/paused-lab-tour"
umask 002
export PATH="RUNTIME/bin:/usr/local/bin:/usr/bin:/bin"
exec /usr/bin/sg www-data -c 'exec /usr/bin/php -d memory_limit=1024M artisan queue:work iguide-lab-tour --queue=iguide-lab-tour --sleep=2 --tries=3 --timeout=660 --memory=768 --max-time=3600'

SH;
    }

    private function tourConfig(int $workers): string
    {
        return <<<CONF

[program:repro-lab-tour]
command=RUNTIME/lab-tour-worker.sh
process_name=%(program_name)s_%(process_num)02d
numprocs=$workers
directory=APP
autostart=true
autorestart=true
startsecs=5
startretries=10
stopasgroup=true
killasgroup=true
stopwaitsecs=720
redirect_stderr=true
stdout_logfile=RUNTIME/logs/lab-tour-worker-%(process_num)02d.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5

CONF;
    }
}
