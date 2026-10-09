<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('copilot_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->json('redirect_uris');
            $table->timestamps();
        });
        Schema::create('copilot_auth_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id')->index();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('redirect_uri');
            $table->string('state', 1024);
            $table->string('resource');
            $table->string('scopes');
            $table->string('challenge', 43);
            $table->string('code_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
        Schema::create('copilot_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_id')->index();
            $table->string('scopes');
            $table->string('resource');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('copilot_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('grant_id')->index();
            $table->string('access_hash', 64)->unique();
            $table->string('refresh_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('refresh_expires_at');
            $table->timestamp('refresh_used_at')->nullable();
            $table->timestamps();
        });
        Schema::create('copilot_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('grant_id')->index();
            $table->string('kind');
            $table->json('payload');
            $table->json('review');
            $table->string('review_hash', 64);
            $table->string('record_revision', 64)->nullable();
            $table->string('status')->default('prepared');
            $table->json('result')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('copilot_watches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('grant_id')->index();
            $table->foreignId('shoot_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->string('last_hash', 64);
            $table->json('last_snapshot');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['grant_id', 'shoot_id']);
        });
        Schema::create('copilot_watch_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('grant_id')->index();
            $table->foreignId('shoot_id')->constrained()->cascadeOnDelete();
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->index(['user_id', 'created_at']);
        });
        Schema::table('shoots', function (Blueprint $table) {
            $table->uuid('copilot_operation_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('shoots', fn (Blueprint $table) => $table->dropColumn('copilot_operation_id'));
        foreach (['copilot_watch_events', 'copilot_watches', 'copilot_drafts', 'copilot_tokens', 'copilot_grants', 'copilot_auth_requests', 'copilot_clients'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
