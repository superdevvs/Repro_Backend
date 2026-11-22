<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // InnoDB checks case, run, composite and mask references during each
        // cascade. Remove only this project's restricting children first.
        // The SQLite trigger also travels with the snapshot to the importer.
        $forEachRow = DB::getDriverName() === 'mysql' ? ' FOR EACH ROW' : '';
        $cleanup = [
            'DELETE FROM iguide_lab_compositions WHERE project_id = OLD.id;',
            'DELETE FROM iguide_lab_pipelines WHERE project_id = OLD.id;',
            'DELETE FROM iguide_lab_masks WHERE project_id = OLD.id;',
            'DELETE FROM iguide_lab_sections WHERE project_id = OLD.id;',
            'DELETE FROM iguide_lab_workflow_items WHERE project_id = OLD.id;',
            'DELETE FROM iguide_lab_tour_run_targets WHERE project_id = OLD.id;',
            'DELETE FROM iguide_lab_tour_captures WHERE project_id = OLD.id;',
            'DELETE FROM iguide_lab_tour_surveys WHERE project_id = OLD.id;',
        ];

        DB::unprepared('CREATE TRIGGER iguide_lab_project_delete_dependencies BEFORE DELETE ON iguide_lab_projects'
            .$forEachRow.' BEGIN '.implode("\n", $cleanup).' END');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS iguide_lab_project_delete_dependencies');
    }
};
