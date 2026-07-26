<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudioPersistenceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_studio_tables_expose_scope_ownership_version_and_activity_columns(): void
    {
        $expectedColumns = [
            'projects' => ['id', 'team_id', 'created_by', 'shoot_id', 'name', 'address', 'source_type', 'workflow_id', 'status', 'version', 'created_at', 'updated_at'],
            'project_media' => ['id', 'project_id', 'team_id', 'created_by', 'media_ref', 'kind', 'version', 'created_at', 'updated_at'],
            'templates' => ['id', 'team_id', 'created_by', 'name', 'workflow_id', 'config', 'version', 'created_at', 'updated_at'],
            'brand_state' => ['team_id', 'created_by', 'updated_by', 'settings', 'version', 'created_at', 'updated_at'],
            'generated_assets' => ['id', 'team_id', 'created_by', 'instruction_index', 'instruction_text', 'asset_path', 'placement', 'alt_text', 'status', 'version', 'created_at', 'updated_at'],
        ];

        foreach ($expectedColumns as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertTrue(Schema::hasColumns($table, $columns), "{$table} is missing required Studio columns.");
        }
    }

    public function test_studio_records_default_to_first_optimistic_lock_version(): void
    {
        $user = User::factory()->create();
        $projectId = (string) Str::uuid();
        $templateId = (string) Str::uuid();
        $common = ['team_id' => 42, 'created_by' => $user->id];

        DB::table('projects')->insert($common + ['id' => $projectId, 'name' => 'Example', 'source_type' => 'upload', 'workflow_id' => 'photo-enhancement']);
        DB::table('project_media')->insert($common + ['project_id' => $projectId, 'media_ref' => 'studio/source.jpg', 'kind' => 'source']);
        DB::table('templates')->insert($common + ['id' => $templateId, 'name' => 'Bright', 'workflow_id' => 'photo-enhancement', 'config' => '{}']);
        DB::table('brand_state')->insert($common + ['updated_by' => $user->id, 'settings' => '{}']);
        DB::table('generated_assets')->insert($common + ['instruction_index' => 1, 'instruction_text' => 'Exterior', 'placement' => 'hero', 'status' => 'failed']);

        foreach (['projects', 'project_media', 'templates', 'brand_state', 'generated_assets'] as $table) {
            $this->assertSame(1, (int) DB::table($table)->value('version'));
            $this->assertSame(42, (int) DB::table($table)->value('team_id'));
        }
    }
}
