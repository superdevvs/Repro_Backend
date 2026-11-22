<?php
declare(strict_types=1);
/** Synthetic fixtures only; no MySQL connection or production files. */
require __DIR__.'/verify-fields.php';

use ReproMysqlFields\Refusal;
use function ReproMysqlFields\decimalString;
use function ReproMysqlFields\equivalent;
use function ReproMysqlFields\legacyText;
use function ReproMysqlFields\openSnapshot;
use function ReproMysqlFields\sortSql;
use function ReproMysqlFields\validatePlan;

ini_set('serialize_precision', '-1');
$checks = 0;
function check(bool $condition): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException('fixture assertion failed at check '.$checks);
    }
}
function refuses(callable $operation): void
{
    try {
        $operation();
    } catch (Refusal) {
        check(true);
        return;
    }
    check(false);
}

foreach ([['00012.340000', '12.34'], ['1.2e-7', '0.00000012'], ['-0.000', '0'],
    ['1.25e3', '1250'], ['-9223372036854775808', '-9223372036854775808'],
    ['99999999999999999999999999999999999.123456789012345678901234567890',
     '99999999999999999999999999999999999.12345678901234567890123456789']] as [$raw, $expected]) {
    check(decimalString($raw) === $expected);
}
foreach (['NaN', 'Infinity', '1e999', '1,2', '', '1.2junk'] as $bad) {
    refuses(fn () => decimalString($bad));
}
check(equivalent(PHP_INT_MIN, '-9223372036854775808', 'integer', SQLITE3_INTEGER));
check(equivalent(PHP_INT_MAX, '9223372036854775807', 'integer', SQLITE3_INTEGER));
check(!equivalent(PHP_INT_MAX, '9223372036854775806', 'integer', SQLITE3_INTEGER));
refuses(fn () => equivalent(PHP_INT_MAX, (float) PHP_INT_MAX, 'integer', SQLITE3_INTEGER));
check(equivalent(47.73, '47.730000000000000000000000000000', 'decimal', SQLITE3_FLOAT));
check(equivalent(0.30000000000000004, '0.300000000000000040000000000000', 'decimal', SQLITE3_FLOAT));
check(!equivalent(0.30000000000000004, '0.300000000000000000000000000000', 'decimal', SQLITE3_FLOAT));
check(equivalent(1.0e-7, '0.000000100000000000000000000000', 'decimal', SQLITE3_FLOAT));
refuses(fn () => equivalent(47.73, 47.73, 'decimal', SQLITE3_FLOAT));
check(equivalent(0.1, 0.1, 'float', SQLITE3_FLOAT));
check(!equivalent(0.1, 0.10000000000000002, 'float', SQLITE3_FLOAT));
check(!equivalent(-0.0, 0.0, 'float', SQLITE3_FLOAT));
refuses(fn () => equivalent(9007199254740993, '9007199254740993', 'float', SQLITE3_INTEGER));
check(equivalent(null, null, 'text', SQLITE3_NULL));
check(!equivalent(null, '', 'text', SQLITE3_NULL));
check(!equivalent('', null, 'text', SQLITE3_TEXT));
check(equivalent('2026-11-22T02:03:04.123456-05:00', '2026-11-22T02:03:04.123456-05:00', 'temporal_text', SQLITE3_TEXT));
check(!equivalent('2026-11-22', '2026-11-22 00:00:00', 'temporal_text', SQLITE3_TEXT));
check(equivalent("A\0z\xff", "A\0z\xff", 'blob', SQLITE3_BLOB));
refuses(fn () => equivalent("A\0z", "A\0z", 'blob', SQLITE3_TEXT));
check(!equivalent('A ', 'A', 'text', SQLITE3_TEXT));
foreach ([[2, '2'], [2.0, '2.0'], ['02', '02'], ['next day', 'next day'],
    [1.0e20, '1e+20'], [1.0e16, '1e+16'], [1.0e15, '1000000000000000.0'],
    [1.234e-5, '1.234e-05'], [1.0e-4, '0.0001'], [-0.0, '-0.0'],
    [123456789.12345679, '123456789.12345679']] as [$value, $expected]) {
    check(legacyText($value) === $expected);
}
check(!equivalent('02', '2', 'legacy_text', SQLITE3_TEXT));

$columns = [
    ['name' => 'label', 'kind' => 'text', 'target_type' => 'LONGTEXT'],
    ['name' => 'legacy', 'kind' => 'legacy_text', 'target_type' => 'LONGTEXT'],
    ['name' => 'bytes', 'kind' => 'blob', 'target_type' => 'LONGBLOB'],
    ['name' => 'amount', 'kind' => 'integer', 'target_type' => 'BIGINT'],
];
$table = ['name' => 'fixture', 'columns' => $columns, 'primary' => []];
check(validatePlan([$table]) === [$table]);
$bad = $table;
$bad['columns'][0]['kind'] = 'timestamp';
refuses(fn () => validatePlan([$bad]));
$bad = $table;
$bad['name'] = 'fixture;DROP';
refuses(fn () => validatePlan([$bad]));
check(str_contains(sortSql($table, true), 'repro_verify_legacy(`legacy`)'));
check(str_contains(sortSql($table, false), 'SHA2(CAST(`legacy` AS BINARY),256)'));

// Matching deterministic ordering despite source NOCASE and mixed legacy types.
// The simulated destination uses SQLite text columns for this ordering fixture;
// it does not claim validation of PDO/MySQL behavior.
$db = new SQLite3(':memory:');
$db->enableExceptions(true);
$db->createFunction('repro_verify_legacy', legacyText(...), 1, SQLITE3_DETERMINISTIC);
$db->createFunction('repro_verify_sha256', static fn ($value) => $value === null ? null : hash('sha256', $value), 1, SQLITE3_DETERMINISTIC);
$db->exec('CREATE TABLE fixture(label TEXT COLLATE NOCASE,legacy,bytes BLOB,amount INTEGER)');
$db->exec('CREATE TABLE destination(label TEXT,legacy TEXT,bytes BLOB,amount INTEGER)');
$rows = [['a', 2, "a\0b", PHP_INT_MAX], ['A', 10, "a\0c", 2], ['a', 10.0, "a\0b", 2],
    ['a', 2, "a\0b", PHP_INT_MAX], [null, null, null, null], ['a', '02', "z\xff", 0]];
foreach ($rows as $row) {
    foreach (['fixture' => $row, 'destination' => [$row[0], legacyText($row[1]), $row[2], $row[3]]] as $name => $values) {
        $statement = $db->prepare('INSERT INTO '.$name.' VALUES(?,?,?,?)');
        foreach ($values as $index => $value) {
            $type = $value === null ? SQLITE3_NULL : ($index === 2 ? SQLITE3_BLOB :
                (is_int($value) ? SQLITE3_INTEGER : (is_float($value) ? SQLITE3_FLOAT : SQLITE3_TEXT)));
            $statement->bindValue($index + 1, $value, $type);
        }
        $statement->execute()->finalize();
        $statement->close();
    }
}
$targetPlan = $table;
$targetPlan['columns'][1]['kind'] = 'text';
$left = $db->query('SELECT * FROM fixture ORDER BY '.sortSql($table, true));
$right = $db->query('SELECT * FROM destination ORDER BY '.sortSql($targetPlan, true));
$count = 0;
while (($a = $left->fetchArray(SQLITE3_NUM)) !== false) {
    $b = $right->fetchArray(SQLITE3_NUM);
    check($b !== false);
    foreach ($columns as $i => $column) {
        check(equivalent($a[$i], $b[$i], $column['kind'], $left->columnType($i)));
    }
    ++$count;
}
check($count === count($rows) && $right->fetchArray(SQLITE3_NUM) === false);
$left->finalize();
$right->finalize();
$db->close();

if (PHP_OS_FAMILY === 'Linux') {
    $snapshot = tempnam(sys_get_temp_dir(), 'repro-wal-source-');
    try {
        $writer = new SQLite3($snapshot);
        check($writer->querySingle('PRAGMA journal_mode=WAL') === 'wal');
        $writer->exec('CREATE TABLE sample(id INTEGER)');
        $writer->exec('INSERT INTO sample VALUES (11)');
        $writer->close();
        $before = hash_file('sha256', $snapshot);
        $source = openSnapshot($snapshot);
        check($source->querySingle('SELECT id FROM frozen.sample') === 11);
        $source->exec('PRAGMA query_only=OFF');
        try {
            $source->exec('UPDATE frozen.sample SET id=12');
            $openedReadOnly = false;
        } catch (Exception) {
            $openedReadOnly = true;
        }
        check($openedReadOnly);
        check(!file_exists($snapshot.'-wal') && !file_exists($snapshot.'-shm'));
        $source->close();
        check(hash_file('sha256', $snapshot) === $before);
    } finally {
        unlink($snapshot);
    }
}
echo json_encode(['result' => 'fixtures-pass', 'checks' => $checks, 'mysql_tested' => false])."\n";
