<?php

use App\Jobs\DispatchScheduledMessages;
use App\Database\MySqlConnection;
use App\Queue\MySqlDatabaseQueue;
use App\Services\Crm\CrmWebsiteIntakeReceipt;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OpenGrantWorkflowTest extends TestCase
{
    public function test_write_workflows_pass_with_exactly_open_grants(): void
    {
        $connection = DB::connection();
        $this->assertInstanceOf(MySqlConnection::class, $connection);
        $this->assertTrue($connection->writerPolicyEnabled());
        $this->assertSame('/fixture/socket/run/mysql.sock', $connection->getConfig('unix_socket'));
        $this->assertSame(['repro_app@localhost', 'repro_dashboard', getenv('REPRO_MYSQL_EXPECTED_SERVER_UUID')],
            $connection->getPdo()->query('SELECT CURRENT_USER(), DATABASE(), @@server_uuid')->fetch(PDO::FETCH_NUM));
        // Real commit boundaries: another session must observe queue work and encrypted CRM intake.
        $name = 'c10-synthetic';
        $this->assertSame(0, DB::transactionLevel());
        $queue = $this->app['queue']->connection('database');
        $this->assertInstanceOf(MySqlDatabaseQueue::class, $queue);
        $observer = new PDO('mysql:unix_socket=/fixture/socket/run/mysql.sock;dbname=repro_dashboard',
            'repro_app', getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $rows = fn () => (int) $observer->query("SELECT COUNT(*) FROM jobs WHERE queue='c10-synthetic'")->fetchColumn();
        $this->assertSame(0, $rows());
        $id = $queue->push(new DispatchScheduledMessages, '', $name);
        $this->assertSame(1, $rows());
        $job = $queue->pop($name);
        $this->assertNotNull($job);
        $this->assertSame((string) $id, (string) $job->getJobId());
        $this->assertSame(1, (int) $observer->query("SELECT attempts FROM jobs WHERE queue='c10-synthetic'")->fetchColumn());
        $job->release(0);
        $job = $queue->pop($name);
        $this->assertNotNull($job);
        $this->assertSame(2, $job->attempts());
        $job->delete();
        $this->assertSame(0, $rows());
        $intake = app(CrmWebsiteIntakeReceipt::class);
        $reference = 'c10-synthetic-inquiry';
        $body = ['first_name' => 'Synthetic', 'email' => 'fixture@example.test'];
        $intake->register('inquiry', $reference, $body);
        $intake->register('inquiry', $reference, ['first_name' => 'Duplicate']);
        $query = $observer->prepare('SELECT payload FROM crm_website_intake_receipts WHERE kind=? AND reference=?');
        $query->execute(['inquiry', $reference]);
        $receipts = $query->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(1, $receipts);
        $this->assertSame($body, json_decode(Crypt::decryptString($receipts[0]), true, flags: JSON_THROW_ON_ERROR));
        DB::table('crm_website_intake_receipts')->where('kind', 'inquiry')->where('reference', $reference)->delete();
        // The exact open grants allow ordinary DML, not fixtures/migrations elevated to the app account.
        try {
            $observer->exec('CREATE TEMPORARY TABLE c10_disallowed (id INT)');
            $this->fail('Application unexpectedly has CREATE TEMPORARY TABLES');
        } catch (PDOException $error) {
            $this->assertSame(1044, (int) $error->errorInfo[1]);
        }
    }
}
