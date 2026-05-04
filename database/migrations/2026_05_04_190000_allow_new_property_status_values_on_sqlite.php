<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('shoots', 'property_status')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $this->updateSqlitePropertyStatusConstraint(['available', 'coming_soon', 'pending', 'sold', 'rented']);
    }

    public function down(): void
    {
        if (!Schema::hasColumn('shoots', 'property_status')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::table('shoots')
            ->whereIn('property_status', ['coming_soon', 'pending'])
            ->update(['property_status' => 'available']);

        $this->updateSqlitePropertyStatusConstraint(['available', 'sold', 'rented']);
    }

    private function updateSqlitePropertyStatusConstraint(array $allowedValues): void
    {
        $row = DB::selectOne("select sql from sqlite_master where type = 'table' and name = 'shoots'");
        $sql = is_string($row->sql ?? null) ? $row->sql : null;

        if ($sql === null) {
            return;
        }

        $allowedSql = implode(', ', array_map(
            static fn (string $value): string => "'" . str_replace("'", "''", $value) . "'",
            $allowedValues
        ));

        $updatedSql = preg_replace(
            '/"property_status"\s+varchar\s+check\s*\(\s*"property_status"\s+in\s*\([^)]+\)\s*\)\s+not\s+null\s+default\s*(?:\(\s*\'available\'\s*\)|\'available\')/i',
            "\"property_status\" varchar check (\"property_status\" in ($allowedSql)) not null default 'available'",
            $sql,
            1,
            $replacements
        );

        if ($updatedSql === null || $replacements === 0) {
            return;
        }

        $schemaVersionRow = DB::selectOne('PRAGMA schema_version');
        $schemaVersion = (int) ($schemaVersionRow->schema_version ?? 0);

        DB::statement('PRAGMA writable_schema = ON');
        try {
            DB::update("update sqlite_master set sql = ? where type = 'table' and name = 'shoots'", [$updatedSql]);
            DB::statement('PRAGMA schema_version = ' . ($schemaVersion + 1));
        } finally {
            DB::statement('PRAGMA writable_schema = OFF');
        }

        $integrityRow = DB::selectOne('PRAGMA integrity_check');
        $integrityValues = $integrityRow ? (array) $integrityRow : [];
        $integrity = reset($integrityValues);

        if ($integrity !== 'ok') {
            throw new \RuntimeException('SQLite integrity check failed after updating property_status constraint.');
        }
    }
};
