<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_studio_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('preparing');
            $table->string('request_key', 64);
            $table->string('request_hash', 64);
            $table->string('path');
            $table->string('preview_path')->nullable();
            $table->string('sha256', 64);
            $table->string('mime', 32);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('byte_size');
            $table->timestamp('expires_at')->index();
            $table->timestamp('pinned_at')->nullable();
            $table->timestamp('media_purged_at')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'request_key']);
        });
        Schema::create('listing_studio_revision_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_item_id')->unique()->constrained('listing_studio_order_items');
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('delivery_attempt_id')->nullable()->index();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
        Schema::create('listing_studio_revision_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained('listing_studio_revision_tasks');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_key', 64);
            $table->string('request_hash', 64);
            $table->unsignedInteger('expected_version');
            $table->string('status', 24)->default('pending')->index();
            $table->json('descriptor');
            $table->string('error_code', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'request_key']);
        });
        Schema::create('listing_studio_revision_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('task_id')->constrained('listing_studio_revision_tasks');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_key', 64);
            $table->string('request_hash', 64);
            $table->string('action', 32);
            $table->string('reason_code', 64)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'request_key']);
        });
        Schema::table('studio_workspaces', function (Blueprint $table) {
            $table->foreignUuid('listing_studio_order_item_id')->nullable()->unique()->constrained('listing_studio_order_items');
        });
    }

    public function down(): void
    {
        // Never turn private paid workspaces into ordinary team workspaces on rollback.
        \Illuminate\Support\Facades\DB::table('listing_studio_order_items')->whereNotNull('workspace_id')->update(['workspace_id' => null]);
        \Illuminate\Support\Facades\DB::table('studio_workspaces')->whereNotNull('listing_studio_order_item_id')->delete();
        Schema::table('studio_workspaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('listing_studio_order_item_id');
        });
        Schema::dropIfExists('listing_studio_revision_actions');
        Schema::dropIfExists('listing_studio_revision_deliveries');
        Schema::dropIfExists('listing_studio_revision_tasks');
        Schema::dropIfExists('listing_studio_uploads');
    }
};
