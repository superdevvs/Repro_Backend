<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_assistant_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_id');
            $table->string('action', 64);
            $table->string('state', 24)->default('prepared');
            $table->string('request_hash', 64);
            $table->string('scope_hash', 64);
            $table->string('target_version', 64)->nullable();
            // Encrypted casts keep dictated content and private parameters out of plain database text.
            $table->text('proposal');
            $table->text('result')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'request_id']);
            $table->index(['user_id', 'state', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_assistant_actions');
    }
};
