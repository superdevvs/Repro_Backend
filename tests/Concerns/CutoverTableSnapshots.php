<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Whole-database before/after comparison for the cutover smoke tests: catches
 * writes the query log cannot show, such as trigger rows.
 */
trait CutoverTableSnapshots
{
    /** @return array<string, string> table => digest of its rows */
    private function tableSnapshot(): array
    {
        $tables = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"))
            ->pluck('name');
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = hash('sha256', json_encode(DB::table($table)->orderByRaw('rowid')->get(), JSON_THROW_ON_ERROR));
        }

        return $snapshot;
    }

    /** @return list<string> */
    private function changedTables(array $before, array $after): array
    {
        $changed = array_keys(array_diff_assoc($after, $before) + array_diff_key($before, $after));
        sort($changed);

        return $changed;
    }
}
