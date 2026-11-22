<?php

namespace Tests\MySql;

use App\Database\MySqlConnector;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ConnectionIdentityTest extends TestCase
{
    public function test_a_pinned_connection_rejects_missing_and_wrong_server_identities(): void
    {
        $connection = DB::connection();
        $config = $connection->getConfig();
        $this->assertSame('repro_mysql_test', $connection->getDatabaseName());
        $this->assertSame('repro_test', $config['username']);
        $config['repro_require_server_identity'] = true;

        if ($config['unix_socket'] ?? false) {
            $identity = $connection->selectOne('SELECT @@server_uuid AS uuid, @@skip_networking AS local_only');
            if ((int) $identity->local_only === 1) {
                $config['repro_expected_server_uuid'] = $identity->uuid;
                $verified = (new MySqlConnector)->connect($config);
                $this->assertSame($identity->uuid, $verified->query('SELECT @@server_uuid')->fetchColumn());
                $config['repro_expected_server_uuid'] = ($identity->uuid[0] === '0' ? '1' : '0').substr($identity->uuid, 1);
                try {
                    (new MySqlConnector)->connect($config);
                    $this->fail('A different server UUID was accepted.');
                } catch (\RuntimeException $exception) {
                    $this->assertStringContainsString('approved socket-only server', $exception->getMessage());
                }
            }
        }

        $config['repro_expected_server_uuid'] = null;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('approved MySQL UUID');
        (new MySqlConnector)->connect($config);
    }
}
