<?php

namespace Tests\Feature\Crm;

use App\Models\Shoot;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Producer replay (P2-06): the Dashboard half of the operator ledger. Given a
 * database copy and the website's list of unconfirmed references, report what
 * the Dashboard holds for each one, read-only, so only the right ones replay.
 */
class CrmWebsiteLedgerTest extends CrmTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/website-ledger-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function seedLedger(): array
    {
        $refs = ['confirmed' => (string) Str::uuid(), 'missing' => (string) Str::uuid(), 'reserved' => (string) Str::uuid(),
            'uncertain' => (string) Str::uuid(), 'unreceived' => (string) Str::uuid(), 'failed' => (string) Str::uuid(),
            'elsewhere' => (string) Str::uuid()];
        // Booking identity is (source, external_reference): another integration may hold the same reference.
        $partner = Shoot::factory()->create(['external_booking_payload' => ['source' => 'partner.example', 'external_reference' => $refs['confirmed']]]);
        $partnerOnly = Shoot::factory()->create(['external_booking_payload' => ['source' => 'partner.example', 'external_reference' => $refs['elsewhere']]]);
        $shoot = Shoot::factory()->create(['external_booking_payload' => ['source' => 'reprophotos.com', 'external_reference' => $refs['confirmed']]]);
        $orphan = Shoot::factory()->create(['external_booking_payload' => ['source' => 'reprophotos.com']]);
        DB::table('external_booking_submissions')->insert([
            ['source' => 'partner.example', 'external_reference' => $refs['confirmed'], 'request_hash' => str_repeat('f', 64),
                'shoot_id' => $partner->id, 'response_payload' => '{"shoot_id":'.$partner->id.'}', 'created_at' => now(), 'updated_at' => now()],
            ['source' => 'partner.example', 'external_reference' => $refs['elsewhere'], 'request_hash' => str_repeat('0', 64),
                'shoot_id' => $partnerOnly->id, 'response_payload' => '{"shoot_id":'.$partnerOnly->id.'}', 'created_at' => now(), 'updated_at' => now()],
            ['source' => 'reprophotos.com', 'external_reference' => $refs['confirmed'], 'request_hash' => str_repeat('a', 64),
                'shoot_id' => $shoot->id, 'response_payload' => '{"shoot_id":'.$shoot->id.'}', 'created_at' => now(), 'updated_at' => now()],
            ['source' => 'reprophotos.com', 'external_reference' => $refs['reserved'], 'request_hash' => str_repeat('b', 64),
                'shoot_id' => null, 'response_payload' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('website_email_deliveries')->insert([
            ['type' => 'booking', 'reference' => $refs['confirmed'], 'payload_hash' => str_repeat('c', 64), 'status' => 'delivered', 'message_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['type' => 'contact', 'reference' => $refs['uncertain'], 'payload_hash' => str_repeat('d', 64), 'status' => 'uncertain', 'message_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['type' => 'contact', 'reference' => $refs['failed'], 'payload_hash' => str_repeat('e', 64), 'status' => 'failed', 'message_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('crm_website_intake_receipts')->insert([
            ['kind' => 'booking', 'reference' => $refs['confirmed'], 'payload' => null, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['kind' => 'inquiry', 'reference' => $refs['uncertain'], 'payload' => 'encrypted', 'completed_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['kind' => 'booking', 'reference' => 'shoot-'.$orphan->id, 'payload' => null, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        return [$refs, $shoot, $orphan];
    }

    /**
     * The database the command is given: a file holding the seeded tables it
     * reads, copied row by row (the in-memory test database stays untouched).
     */
    private function copy(): string
    {
        $path = $this->directory.'/dashboard-copy.sqlite';
        $file = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        foreach (['shoots', 'external_booking_submissions', 'website_email_deliveries', 'crm_website_intake_receipts'] as $table) {
            $file->exec(DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])->sql);
            foreach (DB::table($table)->get() as $row) {
                $row = (array) $row;
                $columns = implode(', ', array_map(fn ($column) => '"'.$column.'"', array_keys($row)));
                $file->prepare('INSERT INTO "'.$table.'" ('.$columns.') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')')
                    ->execute(array_values($row));
            }
        }

        return $path;
    }

    private function references(array $refs): string
    {
        $path = $this->directory.'/website-references.json';
        file_put_contents($path, json_encode(['rows' => [
            ['producer' => 'booking', 'reference' => $refs['confirmed']],
            ['producer' => 'booking', 'reference' => strtoupper($refs['missing'])],
            ['producer' => 'booking', 'reference' => $refs['reserved']],
            ['producer' => 'booking', 'reference' => $refs['elsewhere']],
            ['producer' => 'contact', 'reference' => $refs['uncertain']],
            ['producer' => 'contact', 'reference' => $refs['unreceived']],
            ['producer' => 'contact', 'reference' => $refs['failed']],
        ]]));

        return $path;
    }

    private function assertLedger(int $exit, array $report, array $refs, Shoot $shoot, Shoot $orphan): void
    {
        $this->assertSame(1, $exit, 'Anything other than confirmed or delivered needs an operator decision.');
        $rows = collect($report['rows'])->keyBy('reference_sha256');
        $row = fn (string $key) => $rows[hash('sha256', strtolower($refs[$key]))];
        $this->assertSame('in_dashboard', $row('confirmed')['disposition']);
        $this->assertSame($shoot->id, $row('confirmed')['shoot_id']);
        $this->assertSame('delivered', $row('confirmed')['email_status']);
        $this->assertSame('completed', $row('confirmed')['crm_receipt']);
        $this->assertSame('not_in_dashboard', $row('missing')['disposition']);
        $this->assertSame('submission_without_shoot', $row('reserved')['disposition']);
        // Only another integration holds this reference: neither confirmed nor safe to replay blindly.
        $this->assertSame('other_source_only', $row('elsewhere')['disposition']);
        $this->assertNull($row('elsewhere')['shoot_id']);
        $this->assertSame('uncertain', $row('uncertain')['disposition']);
        $this->assertSame('pending', $row('uncertain')['crm_receipt']);
        $this->assertSame('not_received', $row('unreceived')['disposition']);
        $this->assertSame('not_delivered', $row('failed')['disposition']);
        $this->assertSame([$orphan->id], array_column($report['manual_reconciliation'], 'shoot_id'));
        $this->assertSame(['in_dashboard' => 1, 'not_delivered' => 1, 'not_in_dashboard' => 1, 'not_received' => 1,
            'other_source_only' => 1, 'submission_without_shoot' => 1, 'uncertain' => 1], $report['counts']);
        $this->assertArrayNotHasKey('reference', $row('confirmed'));
    }

    public function test_a_sqlite_copy_is_reported_without_changing_its_bytes(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The copy fixture is built from the SQLite test database; MySQL uses the named connection test.');
        }
        [$refs, $shoot, $orphan] = $this->seedLedger();
        $copy = $this->copy();
        $before = hash_file('sha256', $copy);

        $exit = Artisan::call('crm:website-ledger', ['--sqlite' => $copy, '--references' => $this->references($refs), '--json' => true]);

        $this->assertSame($before, hash_file('sha256', $copy));
        $this->assertLedger($exit, json_decode(Artisan::output(), true), $refs, $shoot, $orphan);
        $this->assertSame(0, (int) DB::selectOne('PRAGMA query_only')->query_only);
    }

    public function test_a_named_connection_is_read_in_a_read_only_session_and_restored(): void
    {
        [$refs, $shoot, $orphan] = $this->seedLedger();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $exit = Artisan::call('crm:website-ledger', ['--connection' => DB::getDefaultConnection(), '--references' => $this->references($refs), '--json' => true]);

        $this->assertSame([], $writes);
        $this->assertLedger($exit, json_decode(Artisan::output(), true), $refs, $shoot, $orphan);
        // The session is writable again afterwards.
        DB::table('website_email_deliveries')->where('reference', $refs['failed'])->update(['updated_at' => now()]);
    }

    public function test_it_refuses_to_run_without_a_database_or_references(): void
    {
        [$refs] = $this->seedLedger();

        $this->assertSame(2, Artisan::call('crm:website-ledger', ['--references' => $this->references($refs)]));
        $this->assertSame(2, Artisan::call('crm:website-ledger', ['--connection' => DB::getDefaultConnection()]));
    }

    public function test_an_unreadable_since_is_bad_input_and_opens_no_read_only_session(): void
    {
        [$refs] = $this->seedLedger();
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        $exit = Artisan::call('crm:website-ledger', ['--connection' => DB::getDefaultConnection(),
            '--references' => $this->references($refs), '--since' => 'not a time']);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('--since', Artisan::output());
        $this->assertSame([], $statements, 'Bad input is refused before the database is touched.');
    }
}
