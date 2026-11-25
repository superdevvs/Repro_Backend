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
            $this->admin->exec('DROP TRIGGER IF EXISTS _writer_policy_route_delay');
            $this->admin->exec('DROP TABLE IF EXISTS _writer_policy_effects');
            $this->admin->exec('DELETE FROM scheduling_route_usage');
        }
        $this->tearDownWriterFixture();
        parent::tearDown();
    }

    /**
     * The reservation is one INSERT ... SELECT that checks the rolling total. Under
     * READ COMMITTED its check is a plain read, so two racers that both check before
     * either commits would both reserve. That window is microseconds, so a test-only
     * trigger holds each insert for half a second: with the token the racers still
     * reserve once; with MYSQL_WRITER_PROOF_TOKEN=off several reserve.
     */
    public function test_eight_processes_racing_for_the_last_route_unit_reserve_it_once(): void
    {
        $this->admin->exec('DROP TRIGGER IF EXISTS _writer_policy_route_delay');
        $this->admin->exec('CREATE TRIGGER _writer_policy_route_delay BEFORE INSERT ON scheduling_route_usage
            FOR EACH ROW BEGIN DO SLEEP(0.5); END');
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

    public function test_status_webhooks_in_random_order_keep_the_final_status(): void
    {
        $this->admin->exec("DELETE FROM messages WHERE provider_message_id = 'writer-policy-outbound'");
        $message = \App\Models\Message::create([
            'channel' => 'SMS', 'direction' => 'OUTBOUND', 'provider' => 'TELNYX',
            'provider_message_id' => 'writer-policy-outbound', 'from_address' => '+12025550102',
            'to_address' => '+12025550188', 'body_text' => 'Probe', 'status' => 'QUEUED',
        ]);
        // Earlier carrier events arrive at the same moment as the final delivery.
        $events = [
            'message.sent||2026-11-25T10:00:01Z', 'message.sent||2026-11-25T10:00:01Z',
            'message.sent||2026-11-25T10:00:02Z', 'message.finalized|delivered|2026-11-25T10:00:05Z',
            'message.finalized|delivery_unconfirmed|2026-11-25T10:00:04Z', 'message.sent||2026-11-25T10:00:03Z',
        ];
        shuffle($events);
        $senders = [];
        foreach ($events as $slot => $event) {
            $senders[] = $this->worker('telnyx-status', [(string) $slot, $event], 240);
        }
        foreach (array_keys($events) as $slot) {
            $this->awaitMarker('ready-'.$slot, 120);
        }
        $this->mark('go');

        foreach ($senders as $sender) {
            $this->assertSame('updated', $this->workerResult($sender)['result']);
        }
        $final = $this->admin->query('SELECT status, delivered_at, metadata FROM messages WHERE id = '.(int) $message->id)->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('DELIVERED', $final['status']);
        $this->assertNotNull($final['delivered_at']);
        $this->assertSame('2026-11-25T10:00:05Z', json_decode($final['metadata'], true)['telnyx_status_occurred_at']);
        $this->assertFalse($this->tokenIsHeld());
    }

    /**
     * Not a serialization proof. Queue claims skip the token by design (D3) and rely
     * on row locks with SKIP LOCKED, so this passes with MYSQL_WRITER_PROOF_TOKEN=off
     * too. It shows that the exemption keeps each job to one run while the job
     * bodies and enqueue take the token, and that workers do not queue behind it.
     */
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
                '--sleep=0', '--tries=1', '--timeout=120'], base_path(),
                $this->workerEnvironment(['QUEUE_CONNECTION' => 'database', 'LOG_CHANNEL' => 'stderr']));
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
