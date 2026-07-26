<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiJobStudioColumnsMigrationTest extends TestCase
{
    public function test_job_tables_receive_nullable_project_and_request_identifiers_safely(): void
    {
        Schema::create('projects', fn ($table) => $table->uuid('id')->primary());
        foreach (['ai_editing_jobs', 'ai_listing_video_jobs'] as $tableName) {
            Schema::create($tableName, fn ($table) => $table->id());
            DB::table($tableName)->insert(['id' => 1]);
        }

        $migration = require database_path(
            'migrations/2026_07_02_000100_add_project_and_request_ids_to_ai_job_tables.php'
        );
        $migration->up();

        foreach (['ai_editing_jobs', 'ai_listing_video_jobs'] as $tableName) {
            $this->assertTrue(Schema::hasColumns($tableName, ['project_id', 'request_id']));
            $columns = collect(Schema::getColumns($tableName))->keyBy('name');
            $this->assertTrue($columns['project_id']['nullable']);
            $this->assertTrue($columns['request_id']['nullable']);
            $this->assertSame(1, DB::table($tableName)->whereNull('project_id')->whereNull('request_id')->count());

            $foreignKey = collect(Schema::getForeignKeys($tableName))
                ->first(fn (array $key) => $key['columns'] === ['project_id']);
            $this->assertNotNull($foreignKey);
            $this->assertSame('projects', $foreignKey['foreign_table']);
            $this->assertSame('set null', strtolower($foreignKey['on_delete']));

            $requestIndex = collect(Schema::getIndexes($tableName))
                ->first(fn (array $index) => $index['columns'] === ['request_id']);
            $this->assertNotNull($requestIndex);
            $this->assertFalse($requestIndex['unique']);
        }

        $migration->down();
        foreach (['ai_editing_jobs', 'ai_listing_video_jobs'] as $tableName) {
            $this->assertFalse(Schema::hasColumn($tableName, 'project_id'));
            $this->assertFalse(Schema::hasColumn($tableName, 'request_id'));
            $this->assertSame(1, DB::table($tableName)->count());
        }
    }
}
