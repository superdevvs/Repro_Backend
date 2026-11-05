<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Match imported signed IDs and newly created unsigned Laravel IDs. */
final class MigrationForeignId
{
    public static function matching(Blueprint $table, string $name, string $parent, string $key = 'id'): ForeignIdColumnDefinition
    {
        $column = $table->foreignId($name);
        if (DB::getDriverName() === 'mysql') {
            $type = strtolower(Schema::getColumnType($parent, $key, fullDefinition: true));
            if (! preg_match('/\Abigint(?:\(\d+\))?(?: unsigned)?\z/', $type)) {
                throw new \RuntimeException('A foreign ID requires a matching BIGINT parent column.');
            }
            $column->unsigned(str_contains($type, 'unsigned'));
        }

        return $column;
    }
}
