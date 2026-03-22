<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_dispatches', function (Blueprint $table) {
            if (!Schema::hasColumn('automation_dispatches', 'automation_rule_id')) {
                $table->foreignId('automation_rule_id')->nullable()->after('id')->constrained('automation_rules')->nullOnDelete();
            }
            if (!Schema::hasColumn('automation_dispatches', 'trigger_type')) {
                $table->string('trigger_type')->nullable()->after('automation_rule_id');
            }
            if (!Schema::hasColumn('automation_dispatches', 'period_key')) {
                $table->string('period_key')->nullable()->after('trigger_type');
            }
            if (!Schema::hasColumn('automation_dispatches', 'scheduled_for')) {
                $table->timestamp('scheduled_for')->nullable()->after('period_key');
            }
            if (!Schema::hasColumn('automation_dispatches', 'command')) {
                $table->string('command')->nullable()->after('scheduled_for');
            }
            if (!Schema::hasColumn('automation_dispatches', 'status')) {
                $table->string('status')->default('pending')->after('command');
            }
            if (!Schema::hasColumn('automation_dispatches', 'output')) {
                $table->longText('output')->nullable()->after('status');
            }
            if (!Schema::hasColumn('automation_dispatches', 'error_message')) {
                $table->text('error_message')->nullable()->after('output');
            }
            if (!Schema::hasColumn('automation_dispatches', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('error_message');
            }
            if (!Schema::hasColumn('automation_dispatches', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('started_at');
            }
        });

        Schema::table('automation_dispatches', function (Blueprint $table) {
            $table->index(['automation_rule_id', 'period_key'], 'automation_dispatches_rule_period_idx');
            $table->index(['trigger_type', 'scheduled_for'], 'automation_dispatches_trigger_schedule_idx');
        });
    }

    public function down(): void
    {
        Schema::table('automation_dispatches', function (Blueprint $table) {
            if (Schema::hasColumn('automation_dispatches', 'automation_rule_id')) {
                $table->dropConstrainedForeignId('automation_rule_id');
            }

            $columns = [
                'trigger_type',
                'period_key',
                'scheduled_for',
                'command',
                'status',
                'output',
                'error_message',
                'started_at',
                'completed_at',
            ];

            $existing = array_values(array_filter($columns, fn ($column) => Schema::hasColumn('automation_dispatches', $column)));
            if (!empty($existing)) {
                $table->dropColumn($existing);
            }
        });
    }
};
