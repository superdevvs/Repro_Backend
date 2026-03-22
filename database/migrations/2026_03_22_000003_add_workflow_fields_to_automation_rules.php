<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_rules', function (Blueprint $table) {
            if (!Schema::hasColumn('automation_rules', 'editor_mode')) {
                $table->string('editor_mode')->default('visual')->after('trigger_type');
            }
            if (!Schema::hasColumn('automation_rules', 'engine_version')) {
                $table->unsignedInteger('engine_version')->default(2)->after('editor_mode');
            }
            if (!Schema::hasColumn('automation_rules', 'workflow_definition_json')) {
                $table->longText('workflow_definition_json')->nullable()->after('schedule_json');
            }
            if (!Schema::hasColumn('automation_rules', 'entry_trigger_json')) {
                $table->text('entry_trigger_json')->nullable()->after('workflow_definition_json');
            }
            if (!Schema::hasColumn('automation_rules', 'is_system_locked')) {
                $table->boolean('is_system_locked')->default(false)->after('entry_trigger_json');
            }
        });
    }

    public function down(): void
    {
        Schema::table('automation_rules', function (Blueprint $table) {
            $columns = [
                'editor_mode',
                'engine_version',
                'workflow_definition_json',
                'entry_trigger_json',
                'is_system_locked',
            ];

            $existing = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn('automation_rules', $column)));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
