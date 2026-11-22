<?php

declare(strict_types=1);

// SQLite records no-op driver-specific migrations too. Reapply only the
// reviewed, idempotent repairs on the new target, without editing that ledger.
ini_set('display_errors', '0');

try {
    $options = getopt('', ['app:', 'expected-server-uuid:', 'writers-stopped']);
    $root = realpath($options['app'] ?? dirname(__DIR__, 2));
    $expected = $options['expected-server-uuid'] ?? '';
    if (! array_key_exists('writers-stopped', $options) || ! preg_match('/\A[a-f0-9-]{36}\z/', $expected)
        || getenv('DB_CONNECTION') !== 'mysql' || ! preg_match('/\Arepro_[A-Za-z0-9_]{1,50}\z/', (string) getenv('DB_DATABASE'))
        || ! $root || ! is_file($root.'/vendor/autoload.php') || ! is_file($root.'/bootstrap/app.php')) {
        throw new RuntimeException('Explicit isolated MySQL configuration, source identity and writer-freeze attestation are required.');
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = Illuminate\Support\Facades\DB::connection();
    if ($db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== getenv('DB_DATABASE') || $db->transactionLevel() !== 0) {
        throw new RuntimeException('Actual booted connection differs from the explicit maintenance configuration.');
    }
    $identity = $db->selectOne('SELECT VERSION() AS version,@@server_uuid AS uuid,@@foreign_key_checks AS fk,@@innodb_flush_log_at_trx_commit AS durable,@@sync_binlog AS binlog');
    if (explode('-', $identity->version)[0] !== '8.4.13' || ! hash_equals($expected, $identity->uuid)
        || (int) $identity->fk !== 1 || (int) $identity->durable !== 1 || (int) $identity->binlog !== 1) {
        throw new RuntimeException('Target identity, validated version or enforcement/durability changed.');
    }
    $ledger = static fn (): string => hash('sha256', json_encode($db->table('migrations')->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR));
    $before = $ledger();
    $migrations = [
        '2026_11_04_180000_restore_optional_messaging_columns',
        '2026_11_04_180100_preserve_aryeo_cascade_change_feed',
        '2026_11_04_190000_index_mysql_invoice_effective_period',
        '2026_11_04_200000_restore_optional_equipment_assignment',
        '2026_11_04_210000_restore_mysql_nullable_invoice_role',
    ];
    foreach ($migrations as $name) {
        $migration = require $root.'/database/migrations/'.$name.'.php';
        $migration->up();
    }
    $schema = Illuminate\Support\Facades\Schema::class;
    foreach (['contacts' => ['phones_json', 'tags_json', 'comment'], 'message_threads' => ['assigned_to_user_id', 'status', 'tags_json']] as $table => $columns) {
        if (! $schema::hasColumns($table, $columns)) {
            throw new RuntimeException('Optional messaging fields are incomplete.');
        }
    }
    foreach (['photographer_equipments' => 'photographer_id', 'invoices' => 'role'] as $table => $column) {
        $definition = collect($schema::getColumns($table))->firstWhere('name', $column);
        if (! $definition || ! $definition['nullable']) {
            throw new RuntimeException('The reviewed nullable-field contract is incomplete.');
        }
    }
    $assignment = collect($schema::getForeignKeys('message_threads'))->first(fn ($key) => $key['columns'] === ['assigned_to_user_id']
        && $key['foreign_table'] === 'users' && $key['foreign_columns'] === ['id']
        && preg_replace('/[^a-z]/', '', strtolower($key['on_delete'])) === 'setnull');
    if (! $assignment) {
        throw new RuntimeException('The reviewed messaging assignment constraint is missing.');
    }
    if (! $schema::hasIndex('invoices', 'invoices_effective_period_sort')) {
        throw new RuntimeException('The reviewed invoice index is missing.');
    }
    $definitions = json_decode(file_get_contents($root.'/database/schema/mysql-cascade-triggers.json'), true, flags: JSON_THROW_ON_ERROR);
    $count = $db->table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', $db->getDatabaseName())->whereIn('TRIGGER_NAME', array_column($definitions, 'name'))->count();
    if ($count !== count($definitions) || $before !== $ledger()) {
        throw new RuntimeException('Cascade coverage or migration-ledger preservation failed.');
    }
    echo json_encode(['verified' => true, 'server_uuid' => $identity->uuid, 'driver_repairs_reapplied' => count($migrations),
        'mysql_cascade_triggers' => $count, 'migration_ledger_unchanged' => true, 'application_configuration_changed' => false,
        'cutover_ready' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['stopped' => true, 'error_type' => get_class($error),
        'details' => 'Schema completion failed. Keep writers stopped and investigate the private maintenance context; no application switch was made.'], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
