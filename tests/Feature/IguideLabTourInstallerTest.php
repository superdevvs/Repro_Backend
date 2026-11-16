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
    private const INFO_NOT_DEDICATED = 'Info: the tour queue is not the dedicated iguide-lab-tour target; the tour worker program is not installed';
    private const GUARD_CHECK = '[ -f /usr/local/sbin/repro-storage-guard ] && [ -x /usr/local/sbin/repro-storage-guard ]';
    private const GUARD_LINE = "/usr/local/sbin/repro-storage-guard --live
";
    // Script markers: the read of hand-added Supervisor sections (before any change) and the block that rewrites files.
    private const KEEP_START = '# Hand-added Supervisor sections';
    private const KEEP_END = '# Flysystem creates private directories';
    private const RENDER_START = '# Timestamped copies';
    private const RENDER_END = 'cat > "$runtime/ensure-supervisor.sh" <<EOF';

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
        // The hand-added sections are read before anything on the host changes (storage, worker.sh, the conf, cron).
        $keep = strpos($script, self::KEEP_START);
        $this->assertNotFalse($keep);
        foreach (["\nprepare_shared_studio_storage\n", self::RENDER_START, 'cat > "$runtime/supervisord.conf" <<EOF', 'crontab "$cron_next"'] as $later) {
            $this->assertGreaterThan($keep, strpos($script, $later), $later);
        }
        $this->assertSame(1, substr_count($script, self::GUARD_CHECK));

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
        // '0' is not here: it is the expected answer for a target that is not dedicated (see the next test).
        foreach (['00', '9', '10', '', 'abc', '3 4', "3\n4", ' 3', '-1', '0 1'] as $count) {
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

    public function test_a_zero_count_skips_the_tour_program_with_an_info_line(): void
    {
        // Deploy 1.1: iguide-lab:tour-workers --count prints 0 unless the tour queue target is the dedicated one. That is
        // the normal Studio-only state, so it is an info line on stdout, not a warning, and only Studio is rendered.
        [$app, $runtime] = $this->layout(withTourJob: true);
        foreach (["0\n", '0'] as $pass => $count) {
            $render = $this->render($app, $runtime, $count);
            $this->assertTrue($render->isSuccessful(), $render->getErrorOutput());
            $this->assertSame('', $render->getErrorOutput(), 'no warning');
            if ($pass === 0) $this->assertSame(self::INFO_NOT_DEDICATED."\n", $render->getOutput(), 'first install: nothing to back up');
            $this->assertSame([self::INFO_NOT_DEDICATED], $this->outputWithoutBackups($render), 'only the info line besides the backup paths');
            $this->assertSame($app.'|www-data|-c|/usr/bin/php artisan iguide-lab:tour-workers --count|', file_get_contents($this->dir.'/sg.log'));
            $this->assertSame($this->expand($this->studioConfig(), $app, $runtime), file_get_contents($runtime.'/supervisord.conf'), 'byte-identical to the pre-D1 file');
            $this->assertSame($this->expand($this->studioWorker(), $app, $runtime), file_get_contents($runtime.'/worker.sh'));
            $this->assertFileDoesNotExist($runtime.'/lab-tour-worker.sh');
        }
        $this->assertStringContainsString(self::INFO_NOT_DEDICATED, $this->script());
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

    public function test_hand_added_voice_programs_survive_a_reinstall(): void
    {
        // Production (16 Nov): the same supervisord.conf holds three hand-added repro-voice-* programs after the
        // installer's sections. A reinstall used to rewrite the file from scratch, and supervisorctl update stopped them.
        [$app, $runtime] = $this->layout(withTourJob: true);
        $voice = $this->voicePrograms();
        file_put_contents($runtime.'/supervisord.conf', $this->expand($this->studioConfig().$this->tourConfig(3), $app, $runtime).$voice);

        // Studio-only (count 0): the old tour program goes, the voice programs stay byte-identical, after Studio.
        $render = $this->render($app, $runtime, '0');
        $this->assertTrue($render->isSuccessful(), $render->getErrorOutput());
        $this->assertSame('', $render->getErrorOutput());
        $this->assertSame($this->expand($this->studioConfig(), $app, $runtime).$voice, file_get_contents($runtime.'/supervisord.conf'));

        // Dedicated tour target: Studio, then the tour program, then the voice programs in their original order.
        foreach (['3', '3', '8'] as $count) {
            $render = $this->render($app, $runtime, $count);
            $this->assertTrue($render->isSuccessful(), $render->getErrorOutput());
            $conf = (string) file_get_contents($runtime.'/supervisord.conf');
            $this->assertSame($this->expand($this->studioConfig().$this->tourConfig((int) $count), $app, $runtime).$voice, $conf, "count $count");
            foreach (['realtime', 'notifications', 'transcripts'] as $kind) {
                $this->assertSame(1, substr_count($conf, "[program:repro-voice-$kind]\n"), $kind);
            }
        }
    }

    public function test_groups_includes_and_their_comments_are_carried_over_in_order(): void
    {
        [$app, $runtime] = $this->layout(withTourJob: true);
        $studio = $this->expand($this->studioConfig(), $app, $runtime);
        $realtime = "; Repro voice calls (added by hand)\n[program:repro-voice-realtime]\ncommand=/home/maverick/.local/share/repro-calls/voice-worker.sh realtime\n";
        $group = "[group:repro-voice]\nprograms=repro-voice-realtime\n";
        $include = "[include]\nfiles=/home/maverick/.local/share/repro-calls/*.conf\n";
        // Kept sections between and after the installer's own ones, a stale tour program in the middle, extra blank lines.
        file_put_contents($runtime.'/supervisord.conf', $studio."\n".$realtime."\n\n".$this->expand($this->tourConfig(2), $app, $runtime)
            ."\n".$group."\n".$include."\n\n");

        $render = $this->render($app, $runtime, '0');
        $this->assertTrue($render->isSuccessful(), $render->getErrorOutput());
        $this->assertSame($studio."\n".$realtime."\n".$group."\n".$include, file_get_contents($runtime.'/supervisord.conf'));
    }

    public function test_a_conf_without_extra_sections_renders_exactly_as_before(): void
    {
        [$app, $runtime] = $this->layout(withTourJob: true);
        $studio = $this->expand($this->studioConfig(), $app, $runtime);
        $tour = $this->expand($this->studioConfig().$this->tourConfig(3), $app, $runtime);
        foreach ([[$studio, '0', $studio], [$studio, '3', $tour], [$tour, '3', $tour], [$tour, '0', $studio], ['', '0', $studio]] as [$before, $count, $after]) {
            file_put_contents($runtime.'/supervisord.conf', $before);
            $render = $this->render($app, $runtime, $count);
            $this->assertTrue($render->isSuccessful(), $render->getErrorOutput());
            $this->assertSame('', $render->getErrorOutput());
            $this->assertSame($after, file_get_contents($runtime.'/supervisord.conf'), "count $count");
            $this->assertSame($this->expand($this->studioWorker(), $app, $runtime), file_get_contents($runtime.'/worker.sh'));
        }
    }

    public function test_worker_runs_the_storage_guard_only_when_the_host_has_it(): void
    {
        [$app, $runtime] = $this->layout(withTourJob: false);
        $guard = $this->dir.'/repro-storage-guard';
        $studioWorker = $this->expand($this->studioWorker(), $app, $runtime);
        $withGuard = "#!/bin/sh\nset -eu\n".self::GUARD_LINE.substr($studioWorker, strlen("#!/bin/sh\nset -eu\n"));

        // Absent: byte-identical to today's worker.
        $render = $this->render($app, $runtime, '0', guard: $guard);
        $this->assertTrue($render->isSuccessful(), $render->getErrorOutput());
        $this->assertSame($studioWorker, file_get_contents($runtime.'/worker.sh'), 'absent');

        // Present but not executable: left out.
        file_put_contents($guard, "#!/bin/sh\nexit 0\n");
        chmod($guard, 0644);
        $render = $this->render($app, $runtime, '0', guard: $guard);
        $this->assertTrue($render->isSuccessful(), $render->getErrorOutput());
        $this->assertSame($studioWorker, file_get_contents($runtime.'/worker.sh'), 'not executable');

        // Present and executable: line 3, before cd and the exec line (production's order).
        chmod($guard, 0755);
        $render = $this->render($app, $runtime, '0', guard: $guard);
        $this->assertTrue($render->isSuccessful(), $render->getErrorOutput());
        $this->assertSame($withGuard, file_get_contents($runtime.'/worker.sh'), 'present');
        $this->assertSame('0755', sprintf('%04o', fileperms($runtime.'/worker.sh') & 07777));
        $this->assertSame(self::GUARD_LINE, explode("\n", $withGuard)[2]."\n");

        // A directory of that name is not the guard.
        unlink($guard);
        mkdir($guard);
        $render = $this->render($app, $runtime, '0', guard: $guard);
        $this->assertTrue($render->isSuccessful(), $render->getErrorOutput());
        $this->assertSame($studioWorker, file_get_contents($runtime.'/worker.sh'), 'directory');
    }

    public function test_previous_conf_and_worker_are_backed_up_with_a_timestamp(): void
    {
        [$app, $runtime] = $this->layout(withTourJob: false);
        $render = $this->render($app, $runtime, '0');
        $this->assertTrue($render->isSuccessful(), $render->getErrorOutput());
        $this->assertSame([], glob($runtime.'/*.before-*'), 'nothing to back up on a first install');

        $oldConf = $this->expand($this->studioConfig(), $app, $runtime).$this->voicePrograms();
        file_put_contents($runtime.'/supervisord.conf', $oldConf);
        file_put_contents($runtime.'/worker.sh', "#!/bin/sh\nold worker\n");
        $render = $this->render($app, $runtime, '0');
        $this->assertTrue($render->isSuccessful(), $render->getErrorOutput());
        $confBackups = glob($runtime.'/supervisord.conf.before-*');
        $workerBackups = glob($runtime.'/worker.sh.before-*');
        $this->assertCount(1, $confBackups);
        $this->assertCount(1, $workerBackups);
        $this->assertMatchesRegularExpression('/\.before-\d{8}-\d{6}$/', $confBackups[0]);
        $this->assertSame($oldConf, file_get_contents($confBackups[0]));
        $this->assertSame("#!/bin/sh\nold worker\n", file_get_contents($workerBackups[0]));
        $this->assertStringContainsString($confBackups[0], $render->getOutput(), 'the backup paths are printed');
        $this->assertStringContainsString($workerBackups[0], $render->getOutput());
    }

    public function test_an_unparsable_conf_stops_the_installer_and_changes_nothing(): void
    {
        [$app, $runtime] = $this->layout(withTourJob: true);
        $studio = $this->expand($this->studioConfig(), $app, $runtime);
        $cases = [
            'setting before any section' => "command=/bin/true\n".$studio,
            'unclosed header' => $studio."\n[program:repro-voice-realtime\ncommand=/bin/true\n",
            'header with trailing text' => $studio."\n[program:repro-voice-realtime] extra\ncommand=/bin/true\n",
            'nested brackets' => $studio."\n[program:[x]]\ncommand=/bin/true\n",
            'empty header' => $studio."\n[]\ncommand=/bin/true\n",
            'indented header' => $studio."\n  [program:repro-voice-realtime]\ncommand=/bin/true\n",
            'unsupported section' => $studio."\n[eventlistener:repro-voice]\ncommand=/bin/true\n",
            'duplicate section' => $studio.$this->voicePrograms().$this->voicePrograms(),
            'duplicate installer section' => $studio."\n".$studio,
            'program name with a space' => $studio."\n[program:repro voice]\ncommand=/bin/true\n",
            'CRLF line endings' => str_replace("\n", "\r\n", $studio.$this->voicePrograms()),
        ];
        foreach ($cases as $case => $conf) {
            file_put_contents($runtime.'/supervisord.conf', $conf);
            file_put_contents($runtime.'/worker.sh', "#!/bin/sh\nold worker\n");
            $render = $this->render($app, $runtime, '3');
            $this->assertFalse($render->isSuccessful(), $case);
            $this->assertStringContainsString("Refusing to rewrite $runtime/supervisord.conf", $render->getErrorOutput(), $case);
            $this->assertStringContainsString('nothing was changed', $render->getErrorOutput(), $case);
            $this->assertSame($conf, file_get_contents($runtime.'/supervisord.conf'), $case);
            $this->assertSame("#!/bin/sh\nold worker\n", file_get_contents($runtime.'/worker.sh'), $case);
            $this->assertFileDoesNotExist($runtime.'/lab-tour-worker.sh', $case);
            $this->assertFileDoesNotExist($this->dir.'/sg.log', $case.': stopped before the tour count');
            $this->assertSame([], glob($runtime.'/*.before-*'), $case.': no backups');
        }
    }

    /** The three hand-added programs found on production (16 Nov), each after a blank line as appended by hand. */
    private function voicePrograms(): string
    {
        $text = '';
        foreach (['realtime', 'notifications', 'transcripts'] as $kind) {
            $text .= "\n[program:repro-voice-$kind]\ncommand=/home/maverick/.local/share/repro-calls/voice-worker.sh $kind\n"
                ."directory=/home/maverick/.local/share/repro-calls\nautostart=true\nautorestart=true\nstopasgroup=true\nkillasgroup=true\n"
                ."environment=VOICE_ROLE=\"$kind\",HOME=\"/home/maverick\"\nredirect_stderr=true\n"
                ."stdout_logfile=/home/maverick/.local/share/repro-calls/logs/%(program_name)s.log\n";
        }

        return $text;
    }

    /** @return list<string> stdout lines other than the "Backed up ..." ones */
    private function outputWithoutBackups(Process $render): array
    {
        $lines = explode("\n", rtrim($render->getOutput(), "\n"));

        return array_values(array_filter($lines, fn (string $line) => ! str_starts_with($line, 'Backed up ')));
    }

    private function script(): string
    {
        return (string) file_get_contents(base_path('scripts/install-studio-runtime.sh'));
    }

    /** The read of hand-added Supervisor sections, then the block from the backups through the supervisord.conf rewrite. */
    private function renderBlock(): string
    {
        $script = $this->script();
        $keep = $this->between($script, self::KEEP_START, self::KEEP_END, true);
        $block = $this->between($script, self::RENDER_START, self::RENDER_END, true);
        $this->assertSame(1, substr_count($block, self::SG_COUNT));
        $this->assertSame(1, substr_count($block, self::GUARD_CHECK));

        return $keep.str_replace([self::SG_COUNT, self::GUARD_CHECK], ["\"\$TOUR_SG\" www-data -c '/usr/bin/php artisan iguide-lab:tour-workers --count'",
            '[ -f "$STORAGE_GUARD" ] && [ -x "$STORAGE_GUARD" ]'], $block);
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

    private function render(string $app, string $runtime, string $count, bool $failing = false, ?string $guard = null): Process
    {
        @unlink($this->dir.'/sg.log');
        @unlink($runtime.'/lab-tour-worker.sh');
        $this->stub($this->dir.'/sg', 'printf "%s|" "$PWD" "$@" > "$STUB_SG_LOG"; if [ "$STUB_FAIL" = 1 ]; then echo "artisan failed" >&2; exit 1; fi; printf "%s" "$STUB_COUNT"');
        $script = "set -Eeuo pipefail\nunset CDPATH\numask 002\napp=".escapeshellarg($app)."\nruntime=".escapeshellarg($runtime)."\n".$this->renderBlock();
        file_put_contents($this->dir.'/render.sh', $script);
        $process = new Process(['bash', $this->dir.'/render.sh'], $this->dir, ['TOUR_SG' => $this->dir.'/sg', 'STUB_SG_LOG' => $this->dir.'/sg.log',
            'STUB_COUNT' => $count, 'STUB_FAIL' => $failing ? '1' : '0', 'STORAGE_GUARD' => $guard ?? $this->dir.'/no-storage-guard']);
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
