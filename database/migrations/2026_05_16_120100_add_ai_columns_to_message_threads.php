<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_threads', function (Blueprint $table): void {
            if (!Schema::hasColumn('message_threads', 'ai_paused_until')) {
                $table->timestamp('ai_paused_until')->nullable();
            }
            if (!Schema::hasColumn('message_threads', 'ai_session_id')) {
                $table->unsignedBigInteger('ai_session_id')->nullable();
                $table->index('ai_session_id');
            }
            if (!Schema::hasColumn('message_threads', 'metadata')) {
                $table->json('metadata')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('message_threads', function (Blueprint $table): void {
            foreach (['metadata', 'ai_session_id', 'ai_paused_until'] as $col) {
                if (Schema::hasColumn('message_threads', $col)) {
                    if ($col === 'ai_session_id') {
                        try { $table->dropIndex(['ai_session_id']); } catch (\Throwable $e) {}
                    }
                    $table->dropColumn($col);
                }
            }
        });
    }
};
