<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DatabaseSql;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DatabaseSqlPortabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_raw_timestamp_shape_preserves_real_fractional_precision(): void
    {
        $value = '2026-11-04 09:53:21';
        $padded = $value.'.000000';
        $this->assertSame(DB::getDriverName() === 'mysql' ? $value : $padded, DatabaseSql::timestampString($padded));
        $this->assertSame($value.'.123456', DatabaseSql::timestampString($value.'.123456'));
        $this->assertSame($value, DatabaseSql::timestampString($value));
        $this->assertNull(DatabaseSql::timestampString(null));
    }

    public function test_json_missing_null_text_and_numeric_assignments_keep_their_meaning(): void
    {
        foreach ([[[], null], [['rep' => null], null], [['rep' => 'null'], 'null'], [['rep' => '123'], '123'], [['rep' => 123], '123'], [['rep' => 'Élodie'], 'Élodie']] as [$metadata, $expected]) {
            $literal = DB::getPdo()->quote(json_encode($metadata));
            $expression = DatabaseSql::jsonScalar($literal, '$.rep');
            $actual = DB::selectOne('SELECT '.$expression.' AS scalar')->scalar;
            $expected === null ? $this->assertNull($actual) : $this->assertSame($expected, (string) $actual);
        }
    }

    public function test_searches_ignore_case_while_equality_keeps_identifier_case(): void
    {
        $user = User::factory()->create(['name' => 'MiXeD Search Fixture']);
        $this->assertSame($user->id, User::where('name', 'like', '%mixed search%')->value('id'));
        $this->assertSame($user->id, User::whereLike('name', '%MIXED SEARCH%')->value('id'));
        $this->assertFalse(User::where('name', 'mixed search fixture')->exists());
        if (DB::getDriverName() === 'mysql') {
            $this->assertFalse(User::whereLike('name', '%mixed search%', caseSensitive: true)->exists());
        }
    }
}
