<?php

namespace App\Console\Commands;

use App\Support\Cutover\CutoverEngineProbe;
use App\Support\Cutover\CutoverSmokeFixture;
use App\Support\LockedWrite;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Create, or report, the inert test client and test shoot of the MySQL cutover
 * smoke (design v2, 5.3). They must exist in production before the copy that
 * the drill or the cutover loads, so they are created with the release deploy
 * and their IDs go into the controller's host.json (`smoke`).
 */
class CutoverSmokeFixtures extends Command
{
    protected $signature = 'cutover:smoke-fixtures
        {--create : Create the test client and test shoot when they do not exist}
        {--json : Print one JSON object}';

    protected $description = 'Create or report the inert test client and test shoot of the cutover smoke';

    public function handle(): int
    {
        $db = $this->laravel['db']->connection();
        $report = ['version' => 1, 'present' => false, 'created' => false, 'client_id' => null, 'shoot_id' => null, 'ok' => false, 'problems' => []];

        try {
            $found = CutoverSmokeFixture::find($db);
            if ($found['client'] === null && $this->option('create')) {
                LockedWrite::run(fn () => $db->transaction(function () use ($db) {
                    CutoverSmokeFixture::create($db);
                    $created = CutoverSmokeFixture::find($db);
                    $problems = $created['problems'] ?: CutoverSmokeFixture::inertnessProblems($db, $created['client'], $created['shoot']);
                    if ($problems !== []) {
                        throw new RuntimeException('refused: '.implode('; ', $problems));
                    }
                }), 'cutover.smoke-fixtures');
                $report['created'] = true;
                $found = CutoverSmokeFixture::find($db);
            }
            $report['present'] = $found['client'] !== null;
            $report['client_id'] = $found['client'] ? (int) $found['client']->id : null;
            $report['shoot_id'] = $found['shoot'] ? (int) $found['shoot']->id : null;
            $report['problems'] = $found['problems'] ?: CutoverSmokeFixture::inertnessProblems($db, $found['client'], $found['shoot']);
        } catch (Throwable $error) {
            $report['problems'][] = CutoverEngineProbe::message($error);
        }
        $report['ok'] = $report['problems'] === [];

        if ($this->option('json')) {
            $this->output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
        } else {
            $this->output->writeln(sprintf('test client %s, test shoot %s%s', $report['client_id'] ?? '-', $report['shoot_id'] ?? '-',
                $report['created'] ? ' (created)' : ''), OutputInterface::OUTPUT_RAW);
            foreach ($report['problems'] as $problem) {
                $this->output->writeln('problem: '.$problem, OutputInterface::OUTPUT_RAW);
            }
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
