<?php

// This profile is deliberately restricted to the separately permissioned test DB.
if (getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_DATABASE') !== 'repro_mysql_test' || getenv('DB_USERNAME') !== 'repro_test') {
    throw new RuntimeException('MySQL regression tests require the isolated repro_mysql_test database and test-only account.');
}

require dirname(__DIR__).'/vendor/autoload.php';

// Run migrate:fresh with the schema-only database/schema/mysql-schema.sql baseline
// and this test-only account before this profile. No customer rows are loaded.
// RefreshDatabase then wraps each test in a rollback transaction. It must not
// replay historical one-off production data repairs to create a fresh schema.
Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = true;
