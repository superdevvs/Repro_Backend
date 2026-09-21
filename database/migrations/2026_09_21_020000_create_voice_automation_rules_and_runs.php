<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_automation_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('trigger_type', 50)->index();
            $table->boolean('enabled')->default(false);
            $table->json('conditions');
            $table->unsignedInteger('delay_minutes')->default(60);
            $table->json('quiet_hours');
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->unsignedInteger('retry_delay_minutes')->default(60);
            $table->string('action_type', 30);
            $table->json('action_config');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('creation_key')->nullable();
            $table->string('creation_request_hash', 64)->nullable();
            $table->unique(['created_by_user_id', 'creation_key'], 'voice_automation_rule_creation');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('voice_automation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voice_automation_rule_id')->constrained('voice_automation_rules');
            $table->string('source_event_key', 160);
            $table->string('trigger_type', 50);
            $table->string('status', 30);
            $table->string('reason', 255)->nullable();
            $table->json('rule_snapshot');
            $table->json('context');
            $table->timestamp('scheduled_at')->nullable();
            $table->foreignId('scheduled_voice_call_id')->nullable()->constrained('scheduled_voice_calls')->nullOnDelete();
            $table->timestamps();
            $table->unique(['voice_automation_rule_id', 'source_event_key'], 'voice_automation_run_event');
        });
        Schema::table('voice_follow_up_tasks', function (Blueprint $table): void {
            $table->dropUnique(['voice_call_id']);
            $table->unsignedBigInteger('voice_call_id')->nullable()->change();
            $table->foreignId('automation_run_id')->nullable()->unique()->constrained('voice_automation_runs')->nullOnDelete();
            $table->foreignId('related_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
        });
        // Preserve one operator wrap-up task per call while allowing distinct rule tasks.
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('CREATE UNIQUE INDEX voice_follow_up_manual_call ON voice_follow_up_tasks (voice_call_id) WHERE automation_run_id IS NULL');
        }
    }

    public function down(): void
    {
        // Automation tasks without a call cannot be represented by the old schema.
        DB::table('voice_follow_up_tasks')->whereNotNull('automation_run_id')->delete();
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX IF EXISTS voice_follow_up_manual_call');
        }
        // SQLite cannot drop a column while its explicit unique index remains.
        Schema::table('voice_follow_up_tasks', function (Blueprint $table): void {
            $table->dropUnique(['automation_run_id']);
        });
        Schema::table('voice_follow_up_tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('automation_run_id');
            $table->dropConstrainedForeignId('related_invoice_id');
            $table->unsignedBigInteger('voice_call_id')->nullable(false)->change();
            $table->unique('voice_call_id');
        });
        Schema::dropIfExists('voice_automation_runs');
        Schema::dropIfExists('voice_automation_rules');
    }
};
