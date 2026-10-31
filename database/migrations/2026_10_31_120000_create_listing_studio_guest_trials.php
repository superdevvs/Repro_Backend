<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_studio_guest_sessions', function (Blueprint $table) {
            $table->string('session_hash', 64)->primary();
            $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('listing_studio_guest_trials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('session_hash', 64)->index();
            $table->string('ip_hash', 64)->index();
            $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('service', 32);
            $table->string('status', 24)->default('queued');
            $table->string('idempotency_key', 64);
            $table->string('request_hash', 64);
            $table->string('source_path');
            $table->string('source_mime', 32);
            $table->string('source_name');
            $table->json('options')->nullable();
            $table->uuid('workspace_id')->nullable()->index();
            $table->string('output_path')->nullable();
            $table->string('preview_path')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('media_purged_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['session_hash', 'idempotency_key'], 'studio_guest_idempotency');
            $table->index(['session_hash', 'service', 'status'], 'studio_guest_service_status');
            $table->index(['client_id', 'service', 'status'], 'studio_guest_client_service');
        });
        Schema::create('listing_studio_guest_claim_tokens', function (Blueprint $table) {
            $table->string('token_hash', 64)->primary();
            $table->uuid('trial_id')->index();
            $table->string('session_hash', 64)->index();
            $table->timestamp('expires_at');
            $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
        });
        // Acquire this write first, before quota reads. SQLite's deferred read transactions
        // cannot serialize global limits; this also supplies a row lock on other engines.
        Schema::create('listing_studio_trial_guard', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('version')->default(0);
        });
        DB::table('listing_studio_trial_guard')->insert(['id' => 1, 'version' => 0]);
        Schema::table('studio_workspaces', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable()->change();
            $table->unsignedBigInteger('created_by')->nullable()->change();
            $table->uuid('listing_studio_trial_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        // Guest workspaces must not become ordinary workspaces with null ownership.
        DB::table('studio_workspaces')->whereNotNull('listing_studio_trial_id')->delete();
        Schema::table('studio_workspaces', function (Blueprint $table) {
            $table->dropColumn('listing_studio_trial_id');
            $table->unsignedBigInteger('team_id')->nullable(false)->change();
            $table->unsignedBigInteger('created_by')->nullable(false)->change();
        });
        Schema::dropIfExists('listing_studio_guest_claim_tokens');
        Schema::dropIfExists('listing_studio_guest_trials');
        Schema::dropIfExists('listing_studio_guest_sessions');
        Schema::dropIfExists('listing_studio_trial_guard');
    }
};
