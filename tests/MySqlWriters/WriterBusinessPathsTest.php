<?php

namespace Tests\MySqlWriters;

use Symfony\Component\Process\Process;
use Tests\MySqlWriters\Concerns\WriterFixture;
use Tests\MySqlWriters\Jobs\EffectJob;
use Tests\TestCase;

/** Business paths with several real processes and the writer token on. */
final class WriterBusinessPathsTest extends TestCase
{
    use WriterFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWriterFixture();
        $this->admin->exec('DELETE FROM jobs');
        $this->admin->exec('DELETE FROM failed_jobs');
        $this->admin->exec('DELETE FROM scheduling_route_usage');
        $this->admin->exec('DROP TABLE IF EXISTS _writer_policy_effects');
        $this->admin->exec('CREATE TABLE _writer_policy_effects (job_no INT UNSIGNED NOT NULL PRIMARY KEY,
            worker_pid INT UNSIGNED NOT NULL) ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            $this->admin->exec('DROP TABLE IF EXISTS _writer_policy_effects');
            $this->admin->exec('DELETE FROM scheduling_route_usage');
        }
        $this->tearDownWriterFixture();
        parent::tearDown();
    }

    public function test_eight_processes_racing_for_the_last_route_unit_reserve_it_once(): void
    {
        $racers = [];
        foreach (range(1, 8) as $racer) {
            $racers[] = $this->worker('route-reserve', [(string) $racer], 240);
        }
        foreach (range(1, 8) as $racer) {
            $this->awaitMarker('ready-'.$racer, 120);
        }
        $this->mark('go');

        $results = array_map(fn (Process $racer) => $this->workerResult($racer)['result'], $racers);

        $this->assertSame(1, count(array_filter($results)), 'Only one reservation may take the last unit.');
        $this->assertSame(1, (int) $this->admin->query('SELECT COALESCE(SUM(elements), 0) FROM scheduling_route_usage')->fetchColumn());
        $this->assertFalse($this->tokenIsHeld());
    }

    public function test_eight_first_texts_from_a_new_number_give_one_contact_one_thread_and_eight_messages(): void
    {
        $this->admin->exec("DELETE FROM messages WHERE from_address = '+12025550199'");
        $this->admin->exec("DELETE FROM message_threads WHERE contact_id IN (SELECT id FROM contacts WHERE phone = '+12025550199')");
        $this->admin->exec("DELETE FROM contacts WHERE phone = '+12025550199'");
        $this->admin->exec("DELETE FROM sms_numbers WHERE phone_number IN ('+12025550101', '+12025550102')");
        $this->admin->exec("INSERT INTO sms_numbers (phone_number, provider, is_default, sms_ai_enabled, created_at, updated_at)
            VALUES ('+12025550101', 'TELNYX', 1, 0, NOW(), NOW()), ('+12025550102', 'TELNYX', 0, 0, NOW(), NOW())");
        $senders = [];
        foreach (range(1, 8) as $sender) {
            $senders[] = $this->worker('telnyx-inbound', [(string) $sender], 240);
        }
        foreach (range(1, 8) as $sender) {
            $this->awaitMarker('ready-'.$sender, 120);
        }
        $this->mark('go');

        $statuses = array_map(fn (Process $sender) => $this->workerResult($sender)['result'], $senders);

        $this->assertSame(array_fill(0, 8, 'received'), $statuses);
        $contacts = $this->admin->query("SELECT id FROM contacts WHERE phone = '+12025550199'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(1, $contacts);
        $this->assertSame(1, (int) $this->admin->query('SELECT COUNT(*) FROM message_threads WHERE contact_id = '.(int) $contacts[0])->fetchColumn());
        $this->assertSame(8, (int) $this->admin->query("SELECT COUNT(*) FROM messages WHERE from_address = '+12025550199'")->fetchColumn());
        $this->assertFalse($this->tokenIsHeld());
    }

    public function test_twelve_workers_take_a_thousand_jobs_and_each_runs_exactly_once(): void
    {
        $queue = app('queue')->connection('database');
        foreach (array_chunk(range(1, 1000), 250) as $chunk) {
            $queue->bulk(array_map(fn (int $number) => new EffectJob($number), $chunk));
        }
        $this->assertSame(1000, (int) $this->admin->query('SELECT COUNT(*) FROM jobs')->fetchColumn());

        $started = microtime(true);
        $workers = [];
        foreach (range(1, 12) as $ignored) {
            $worker = new Process([PHP_BINARY, base_path('artisan'), 'queue:work', 'database', '--stop-when-empty',
                '--sleep=0', '--tries=1', '--timeout=120'], base_path(), ['QUEUE_CONNECTION' => 'database', 'LOG_CHANNEL' => 'stderr']);
            $worker->setTimeout(300);
            $worker->start();
            $workers[] = $worker;
        }
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
        }

        $effects = $this->admin->query('SELECT COUNT(*), COUNT(DISTINCT job_no), MIN(job_no), MAX(job_no), COUNT(DISTINCT worker_pid)
            FROM _writer_policy_effects')->fetch(\PDO::FETCH_NUM);
        $this->assertSame([1000, 1000, 1, 1000], array_map('intval', array_slice($effects, 0, 4)));
        $this->assertGreaterThan(1, (int) $effects[4], 'Several workers must have shared the backlog.');
        $this->assertSame(0, (int) $this->admin->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
        $this->assertSame(0, (int) $this->admin->query('SELECT COUNT(*) FROM failed_jobs')->fetchColumn());
        $this->assertFalse($this->tokenIsHeld());
        fwrite(STDERR, sprintf("\n12 workers drained 1000 jobs in %.1f s across %d processes.\n", microtime(true) - $started, $effects[4]));
    }
}
