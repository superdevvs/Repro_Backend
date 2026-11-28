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

        // InnoDB checks case, run, composite and mask references during each
        // cascade. Remove only this project's restricting children first.
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

        $body = 'BEGIN '.implode("\n", $cleanup).' END';
        $existing = DB::selectOne(
            "SELECT ACTION_STATEMENT AS body, ACTION_TIMING AS timing, EVENT_MANIPULATION AS trigger_event, EVENT_OBJECT_TABLE AS table_name
             FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'iguide_lab_project_delete_dependencies'"
        );
        if ($existing) {
            $normalize = static fn (string $sql): string => preg_replace('/\s+/', ' ', trim($sql));
            if ($existing->timing !== 'BEFORE' || $existing->trigger_event !== 'DELETE' || $existing->table_name !== 'iguide_lab_projects'
                || $normalize($existing->body) !== $normalize($body)) {
                throw new RuntimeException('The existing iGUIDE project cleanup trigger has a different definition.');
            }

            return;
        }

        DB::unprepared('CREATE TRIGGER iguide_lab_project_delete_dependencies BEFORE DELETE ON iguide_lab_projects FOR EACH ROW '.$body);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS iguide_lab_project_delete_dependencies');
        }
    }
};
