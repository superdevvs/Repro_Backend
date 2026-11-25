<?php

namespace App\Console\Commands;

use App\Jobs\CutoverSmokeJob;
use App\Models\Shoot;
use App\Models\User;
use App\Support\Cutover\CutoverEngineProbe;
use App\Support\Cutover\CutoverRouteValues;
use App\Support\Cutover\CutoverSmokeFixture as Fixture;
use App\Support\LockedWrite;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The MySQL cutover smoke (design v2, 5.3). Exactly one mode per run:
 *
 *  --engine-only  engine, database, server identity and the raw route values of
 *                 this process's live config. Run in every wrapper profile.
 *  --read-only    the same, then the marked test client and test shoot are read
 *                 through the app's models, the client's encrypted value must
 *                 decrypt, and nothing may wait on database:cutover-smoke. Every
 *                 read runs in a session the database keeps from writing.
 *  --write        the read-only checks, then one field is edited on the test
 *                 client and the test shoot with the query builder (no model
 *                 event), read back, and one CutoverSmokeJob is queued on
 *                 database:cutover-smoke and run in this process with
 *                 queue:work --once. It writes only users, shoots, aryeo_changes
 *                 (the shoot triggers) and jobs.
 *
 * Exit 0 when every check passed, 1 otherwise, 2 for a usage error.
 */
class CutoverSmoke extends Command
{
    protected $signature = 'cutover:smoke
        {--engine-only : Print the engine, the database, the server identity and the raw route values; read only}
        {--read-only : Also read the marked test records, decrypt their encrypted value and check the smoke queue; read only}
        {--write : Also edit one field on the test records, read it back and run one no-op job on database:cutover-smoke}
        {--json : Print one JSON object}';

    protected $description = 'Cutover smoke: engine and route values, read-only checks of the test records, or the write smoke';

    public function handle(): int
    {
        $modes = array_keys(array_filter([
            'engine-only' => (bool) $this->option('engine-only'),
            'read-only' => (bool) $this->option('read-only'),
            'write' => (bool) $this->option('write'),
        ]));
        if (count($modes) !== 1) {
            $this->error('Choose exactly one of --engine-only, --read-only or --write.');

            return 2;
        }
        $mode = $modes[0];

        $report = ['version' => 1, 'mode' => $mode, 'ok' => false] + CutoverEngineProbe::measure($this->laravel);
        $problems = $report['problems'];
        if ($mode !== 'engine-only') {
            $db = $this->laravel['db']->connection();
            try {
                $report['checks'] = CutoverEngineProbe::readOnly($db, function () use ($db, &$problems) {
                    return $this->readChecks($db, $problems);
                });
                if ($mode === 'write' && $problems === []) {
                    $report['checks'] += $this->write($db, $report['checks'], $problems);
                }
            } catch (Throwable $error) {
                $problems[] = CutoverEngineProbe::message($error);
            }
        }
        $report['problems'] = $problems;
        $report['ok'] = $problems === [];

        $this->report($report);

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function readChecks(Connection $db, array &$problems): array
    {
        $checks = ['client_id' => null, 'shoot_id' => null, 'client_read' => false, 'shoot_read' => false, 'encrypted_value_decrypts' => false,
            'smoke_queue' => Fixture::QUEUE_CONNECTION.':'.Fixture::QUEUE, 'smoke_queue_pending' => null, 'smoke_queue_paused' => null];

        $found = Fixture::find($db);
        array_push($problems, ...$found['problems']);
        if ($found['client'] !== null && $found['shoot'] !== null) {
            $checks['client_id'] = (int) $found['client']->id;
            $checks['shoot_id'] = (int) $found['shoot']->id;
            array_push($problems, ...Fixture::inertnessProblems($db, $found['client'], $found['shoot']));

            // Through the app's models: connection, casts and global scopes as every process uses them.
            $client = User::query()->find($checks['client_id']);
            $shoot = Shoot::query()->find($checks['shoot_id']);
            $checks['client_read'] = $client !== null && $client->email === Fixture::CLIENT_EMAIL;
            $checks['shoot_read'] = $shoot !== null && (int) $shoot->client_id === $checks['client_id'];
            try {
                $checks['encrypted_value_decrypts'] = $client?->two_factor_secret === Fixture::SECRET_PLAINTEXT;
            } catch (DecryptException) {
                $checks['encrypted_value_decrypts'] = false;
            }
            foreach (['client_read' => 'the test client could not be read through the User model', 'shoot_read' => 'the test shoot could not be read through the Shoot model',
                'encrypted_value_decrypts' => 'the test client\'s encrypted value does not decrypt to the expected text with this app key'] as $check => $problem) {
                if (! $checks[$check]) {
                    $problems[] = $problem;
                }
            }
        }

        $checks['smoke_queue_pending'] = $this->smokeJobs()->count();
        $checks['smoke_queue_paused'] = (bool) $this->laravel['queue']->isPaused(Fixture::QUEUE_CONNECTION, Fixture::QUEUE);
        if ($checks['smoke_queue_pending'] !== 0) {
            $problems[] = "{$checks['smoke_queue_pending']} jobs are pending on {$checks['smoke_queue']}";
        }

        return $checks;
    }

    /** @return array<string, mixed> */
    private function write(Connection $db, array $checks, array &$problems): array
    {
        if ($checks['smoke_queue_paused']) {
            $problems[] = "{$checks['smoke_queue']} is paused; resume it before the write smoke";

            return [];
        }

        $token = bin2hex(random_bytes(8));
        $value = 'cutover smoke '.$token.' '.Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z');
        LockedWrite::run(fn () => $db->transaction(function () use ($db, $checks, $value) {
            $clients = $db->table('users')->where('id', $checks['client_id'])->where('email', Fixture::CLIENT_EMAIL)
                ->update([Fixture::SMOKE_FIELD => $value]);
            $shoots = $db->table('shoots')->where('id', $checks['shoot_id'])->where('client_id', $checks['client_id'])
                ->where('shoot_type', Shoot::SHOOT_TYPE_INTERNAL_TEST)->update([Fixture::SMOKE_FIELD => $value]);
            if ($clients !== 1 || $shoots !== 1) {
                throw new RuntimeException("the smoke edit matched {$clients} client and {$shoots} shoot rows, expected one each");
            }
        }), 'cutover.smoke');
        $readBack = $db->table('users')->where('id', $checks['client_id'])->value(Fixture::SMOKE_FIELD) === $value
            && $db->table('shoots')->where('id', $checks['shoot_id'])->value(Fixture::SMOKE_FIELD) === $value;
        if (! $readBack) {
            $problems[] = 'the smoke edit did not read back';
        }

        $id = $this->laravel['queue']->connection(Fixture::QUEUE_CONNECTION)->pushOn(Fixture::QUEUE, new CutoverSmokeJob($token));
        $payload = json_decode((string) $this->smokeJobs()->where('id', $id)->value('payload'), true);
        $exit = $this->callSilently('queue:work', ['connection' => Fixture::QUEUE_CONNECTION, '--queue' => Fixture::QUEUE, '--once' => true,
            '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--force' => true]);
        $job = [
            'queued' => $id !== null && is_array($payload),
            'ran' => CutoverSmokeJob::ran($token),
            'row_left' => $id !== null && $this->smokeJobs()->where('id', $id)->exists(),
            'failed' => is_string($payload['uuid'] ?? null) && $this->laravel['queue.failer']->find($payload['uuid']) !== null,
        ];
        if ($exit !== 0 || ! $job['queued'] || ! $job['ran'] || $job['row_left'] || $job['failed']) {
            $problems[] = 'the smoke job did not run cleanly in this process (worker exit '.$exit.', '.json_encode($job).')';
        }

        return ['value' => $value, 'read_back' => $readBack, 'job' => $job, 'smoke_tables' => Fixture::SMOKE_TABLES];
    }

    /** The smoke queue's rows, on the database the queue connection uses. */
    private function smokeJobs(): \Illuminate\Database\Query\Builder
    {
        $config = $this->laravel['config']->get('queue.connections.'.Fixture::QUEUE_CONNECTION);

        return $this->laravel['db']->connection($config['connection'] ?? null)->table($config['table'] ?? 'jobs')->where('queue', Fixture::QUEUE);
    }

    private function report(array $report): void
    {
        $report['route_values'] = CutoverRouteValues::forJson($report['route_values']);
        if ($this->option('json')) {
            $this->output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), OutputInterface::OUTPUT_RAW);

            return;
        }
        $this->output->writeln(sprintf('%s: engine %s, connection %s, database %s', $report['mode'], $report['engine'] ?? '-',
            $report['connection'] ?? '-', $report['database'] ?? '-'), OutputInterface::OUTPUT_RAW);
        foreach ($report['checks'] ?? [] as $check => $value) {
            $this->output->writeln($check.': '.json_encode($value, JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
        }
        foreach ($report['problems'] as $problem) {
            $this->output->writeln('problem: '.$problem, OutputInterface::OUTPUT_RAW);
        }
        $this->output->writeln($report['ok'] ? 'ok' : 'failed', OutputInterface::OUTPUT_RAW);
    }
}
