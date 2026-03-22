<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->string('trigger_type')->nullable();
            $table->string('status')->default('pending');
            $table->longText('context_json')->nullable();
            $table->unsignedBigInteger('related_shoot_id')->nullable();
            $table->unsignedBigInteger('related_account_id')->nullable();
            $table->unsignedBigInteger('related_invoice_id')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['automation_rule_id', 'status']);
            $table->index(['trigger_type', 'created_at']);
            $table->index(['scheduled_for', 'status']);
        });

        Schema::create('automation_run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_run_id')->constrained('automation_runs')->cascadeOnDelete();
            $table->foreignId('automation_rule_id')->nullable()->constrained('automation_rules')->nullOnDelete();
            $table->string('node_id');
            $table->string('node_type');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->longText('input_json')->nullable();
            $table->longText('output_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['automation_run_id', 'status']);
            $table->index(['scheduled_for', 'status']);
            $table->index(['node_id', 'node_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_run_steps');
        Schema::dropIfExists('automation_runs');
    }
};
