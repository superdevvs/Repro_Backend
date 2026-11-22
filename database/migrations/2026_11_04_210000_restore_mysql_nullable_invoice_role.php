<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        $db = DB::connection();
        $column = $db->selectOne(
            'SELECT COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable,
                CHARACTER_SET_NAME AS character_set_name, COLLATION_NAME AS collation_name,
                COLUMN_DEFAULT AS column_default, EXTRA AS extra,
                GENERATION_EXPRESSION AS generation_expression, COLUMN_COMMENT AS column_comment
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$db->getDatabaseName(), $db->getTablePrefix().'invoices', 'role']
        );
        if (! $column || $column->is_nullable === 'YES') {
            return;
        }

        // Imported SQLite strings are LONGTEXT; fresh installs use VARCHAR.
        // Change nullability without narrowing either definition or reinterpreting
        // expression defaults and other attributes outside the reviewed schemas.
        if (! preg_match('/\A(?:varchar\([1-9][0-9]*\)|tinytext|text|mediumtext|longtext)\z/i', $column->column_type)
            || ! preg_match('/\A[A-Za-z0-9_]+\z/', (string) $column->character_set_name)
            || ! preg_match('/\A[A-Za-z0-9_]+\z/', (string) $column->collation_name)
            || $column->extra !== '' || (string) $column->generation_expression !== '') {
            throw new RuntimeException('Unsupported invoices.role definition; nullability was not changed.');
        }
        $default = $column->column_default === null ? 'NULL' : $db->getPdo()->quote($column->column_default);
        $comment = $db->getPdo()->quote($column->column_comment);
        if ($default === false || $comment === false) {
            throw new RuntimeException('Unable to preserve invoices.role literals; nullability was not changed.');
        }

        // The historical nullable-role migration skipped SQLite, but its
        // applied ledger was carried into MySQL. Complete that contract.
        $db->statement('ALTER TABLE '.$db->getQueryGrammar()->wrapTable('invoices')
            .' MODIFY `role` '.$column->column_type
            .' CHARACTER SET '.$column->character_set_name.' COLLATE '.$column->collation_name
            .' NULL DEFAULT '.$default.' COMMENT '.$comment);
    }

    public function down(): void
    {
        // Preserve any supported legacy invoices subsequently saved with no role.
    }
};
