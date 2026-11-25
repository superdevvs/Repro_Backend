<?php

namespace Tests\Feature;

use App\Support\SchemaFacts;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SchemaFacts answers Schema::hasTable / hasColumn from a cache keyed by the release, so a request
 * does not ask the database about its own layout every time. Only "it exists" answers are cached:
 * a table or column added later by a migration is seen at once.
 */
class SchemaFactsTest extends TestCase
{
    use RefreshDatabase;

    private int $schemaQueries = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.schema_cache.enabled' => true,
            'database.schema_cache.store' => 'array',
            'database.schema_cache.release' => 'release-a',
        ]);

        DB::listen(function ($query): void {
            if (preg_match('/sqlite_master|pragma_table|information_schema/i', $query->sql)) {
                $this->schemaQueries++;
            }
        });
    }

    public function test_an_existing_table_is_answered_from_the_cache_the_next_time(): void
    {
        $this->assertTrue(SchemaFacts::hasTable('users'));
        $this->assertSame(1, $this->schemaQueries);

        $this->assertTrue(SchemaFacts::hasTable('users'));
        $this->assertSame(1, $this->schemaQueries, 'The second answer must come from the cache.');
    }

    public function test_an_existing_column_is_answered_from_the_cache_the_next_time(): void
    {
        $this->assertTrue(SchemaFacts::hasColumn('users', 'email'));
        $afterFirst = $this->schemaQueries;
        $this->assertGreaterThan(0, $afterFirst);

        $this->assertTrue(SchemaFacts::hasColumn('users', 'email'));
        $this->assertSame($afterFirst, $this->schemaQueries, 'The second answer must come from the cache.');
    }

    public function test_a_missing_answer_is_kept_only_for_the_short_negative_ttl(): void
    {
        config(['database.schema_cache.negative_ttl' => 60]);
        $this->freezeTime();

        $this->assertFalse(SchemaFacts::hasTable('schema_facts_probe'));
        $this->assertFalse(SchemaFacts::hasColumn('users', 'schema_facts_missing'));
        $afterFirst = $this->schemaQueries;
        $this->assertFalse(SchemaFacts::hasTable('schema_facts_probe'));
        $this->assertFalse(SchemaFacts::hasColumn('users', 'schema_facts_missing'));
        $this->assertSame($afterFirst, $this->schemaQueries, 'A repeated "missing" answer comes from the cache.');

        Schema::create('schema_facts_probe', function (Blueprint $table): void {
            $table->id();
        });
        $this->travel(61)->seconds();

        $this->assertTrue(SchemaFacts::hasTable('schema_facts_probe'));
    }

    public function test_a_negative_ttl_of_zero_never_caches_a_missing_answer(): void
    {
        config(['database.schema_cache.negative_ttl' => 0]);

        $this->assertFalse(SchemaFacts::hasTable('schema_facts_probe'));

        Schema::create('schema_facts_probe', function (Blueprint $table): void {
            $table->id();
        });
        $this->assertTrue(SchemaFacts::hasTable('schema_facts_probe'));

        $this->assertFalse(SchemaFacts::hasColumn('schema_facts_probe', 'label'));
        Schema::table('schema_facts_probe', function (Blueprint $table): void {
            $table->string('label')->nullable();
        });
        $this->assertTrue(SchemaFacts::hasColumn('schema_facts_probe', 'label'));
    }

    public function test_a_new_release_asks_the_database_again(): void
    {
        $this->assertTrue(SchemaFacts::hasTable('users'));
        config(['database.schema_cache.release' => 'release-b']);

        $this->assertTrue(SchemaFacts::hasTable('users'));

        $this->assertSame(2, $this->schemaQueries);
    }

    public function test_answers_are_kept_per_connection(): void
    {
        config(['database.connections.schema_facts_other' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        Schema::connection('schema_facts_other')->create('only_on_other', function (Blueprint $table): void {
            $table->id();
        });

        $this->assertTrue(SchemaFacts::hasTable('only_on_other', 'schema_facts_other'));
        $this->assertFalse(SchemaFacts::hasTable('only_on_other'));
    }

    public function test_when_disabled_every_call_asks_the_database(): void
    {
        config(['database.schema_cache.enabled' => false]);

        SchemaFacts::hasTable('users');
        SchemaFacts::hasTable('users');

        $this->assertSame(2, $this->schemaQueries);
    }

    public function test_an_unusable_cache_store_falls_back_to_the_database(): void
    {
        config(['database.schema_cache.store' => 'schema-facts-missing-store']);

        $this->assertTrue(SchemaFacts::hasTable('users'));
        $this->assertTrue(SchemaFacts::hasColumn('users', 'email'));
    }

    public function test_the_cache_is_off_by_default_and_keyed_by_the_migration_set(): void
    {
        $defaults = require config_path('database.php');

        $this->assertFalse($defaults['schema_cache']['enabled'], 'The cache is off until the owner enables it.');
        $this->assertSame(60, $defaults['schema_cache']['negative_ttl']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16,}$/', $defaults['schema_cache']['release']);
    }
}
