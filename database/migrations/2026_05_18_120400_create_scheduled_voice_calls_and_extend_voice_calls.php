<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_voice_calls', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('scheduled')->index();
            $table->string('automation_type')->nullable()->index();
            $table->string('reason')->nullable()->index();
            $table->string('target_phone', 32)->index();
            $table->string('from_phone', 32)->nullable();
            $table->foreignId('caller_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('caller_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('related_shoot_id')->nullable()->constrained('shoots')->nullOnDelete();
            $table->foreignId('related_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('original_voice_call_id')->nullable()->constrained('voice_calls')->nullOnDelete();
            $table->foreignId('result_voice_call_id')->nullable()->constrained('voice_calls')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->json('quiet_hours')->nullable();
            $table->text('summary')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
        });

        Schema::table('voice_calls', function (Blueprint $table): void {
            if (!Schema::hasColumn('voice_calls', 'intent')) {
                $table->string('intent')->nullable()->after('disposition')->index();
            }
            if (!Schema::hasColumn('voice_calls', 'menu_digit')) {
                $table->string('menu_digit', 4)->nullable()->after('intent')->index();
            }
            if (!Schema::hasColumn('voice_calls', 'escalation_reason')) {
                $table->string('escalation_reason')->nullable()->after('menu_digit')->index();
            }
            if (!Schema::hasColumn('voice_calls', 'callback_status')) {
                $table->string('callback_status')->nullable()->after('escalation_reason')->index();
            }
            if (!Schema::hasColumn('voice_calls', 'callback_requested_at')) {
                $table->timestamp('callback_requested_at')->nullable()->after('callback_status');
            }
            if (!Schema::hasColumn('voice_calls', 'preferred_callback_at')) {
                $table->timestamp('preferred_callback_at')->nullable()->after('callback_requested_at');
            }
            if (!Schema::hasColumn('voice_calls', 'scheduled_voice_call_id')) {
                $table->foreignId('scheduled_voice_call_id')->nullable()->after('preferred_callback_at')->constrained('scheduled_voice_calls')->nullOnDelete();
            }
            if (!Schema::hasColumn('voice_calls', 'last_telnyx_command_status')) {
                $table->json('last_telnyx_command_status')->nullable()->after('scheduled_voice_call_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('voice_calls', function (Blueprint $table): void {
            if (Schema::hasColumn('voice_calls', 'scheduled_voice_call_id')) {
                $table->dropConstrainedForeignId('scheduled_voice_call_id');
            }

            foreach ([
                'last_telnyx_command_status',
                'preferred_callback_at',
                'callback_requested_at',
                'callback_status',
                'escalation_reason',
                'menu_digit',
                'intent',
            ] as $column) {
                if (Schema::hasColumn('voice_calls', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('scheduled_voice_calls');
    }
};
