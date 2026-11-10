<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aryeo_api_installations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('environment');
            $t->uuid('company_id');
            $t->uuid('provider_installation_id')->nullable();
            $t->string('name');
            $t->string('status')->default('disconnected');
            $t->boolean('paused')->default(true);
            $t->json('paused_workflows')->nullable();
            $t->boolean('enrolled')->default(false);
            $t->json('allowed_client_ids')->nullable();
            $t->json('allowed_shoot_ids')->nullable();
            $t->json('requirements')->nullable();
            $t->json('capabilities')->nullable();
            $t->text('credentials')->nullable();
            $t->timestamp('token_expires_at')->nullable();
            $t->timestamp('refresh_started_at')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->unsignedInteger('authorization_generation')->default(0);
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->unique(['environment', 'company_id']);
        });
        Schema::create('aryeo_api_oauth_states', function (Blueprint $t) {
            $t->string('state_hash', 64)->primary();
            $t->uuid('installation_id')->index();
            $t->unsignedBigInteger('actor_id');
            $t->string('browser_hash', 64);
            $t->string('kind')->default('delegate');
            $t->unsignedBigInteger('auth_token_id')->nullable();
            $t->text('verifier');
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
        });
        Schema::create('aryeo_api_destinations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('installation_id')->index();
            $t->unsignedBigInteger('shoot_id')->index();
            $t->unsignedBigInteger('unit_id')->nullable();
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('request_record_id')->nullable();
            $t->string('workflow');
            $t->string('binding_key', 64)->unique();
            $t->uuid('provider_job_id')->nullable();
            $t->uuid('provider_place_id')->nullable();
            $t->uuid('legacy_listing_id')->nullable();
            $t->string('listing_url', 2048)->nullable();
            $t->json('identity');
            $t->json('recipients');
            $t->json('address');
            $t->json('requirements');
            $t->json('consent');
            $t->boolean('on_hold')->default(false);
            $t->string('association_status')->default('awaiting_listing');
            $t->uuid('active_export_id')->nullable();
            $t->string('executor')->nullable();
            $t->uuid('executor_token')->nullable();
            $t->timestamp('executor_expires_at')->nullable();
            $t->unsignedBigInteger('verified_by');
            $t->timestamp('verified_at');
            $t->timestamps();
        });
        Schema::create('aryeo_api_exports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('destination_id')->index();
            $t->string('revision', 64);
            $t->json('snapshot');
            $t->json('remote_baseline')->nullable();
            $t->string('status')->default('draft')->index();
            $t->string('phase')->default('review');
            $t->json('outcomes');
            $t->string('blocker')->nullable();
            $t->uuid('provider_delivery_id')->nullable();
            $t->json('receipt')->nullable();
            $t->json('metrics')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('editing_window_until')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamps();
            $t->index(['destination_id', 'revision']);
        });
        Schema::create('aryeo_api_assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('export_id')->index();
            $t->string('source_key');
            $t->json('source');
            $t->uuid('ingest_id')->nullable();
            $t->uuid('provider_asset_id')->nullable();
            $t->uuid('place_asset_id')->nullable();
            $t->string('status')->default('pending');
            $t->string('ownership')->default('unresolved');
            $t->json('presentation')->nullable();
            $t->text('source_url')->nullable();
            $t->string('grant_hash', 64)->nullable()->unique();
            $t->timestamp('grant_expires_at')->nullable();
            $t->timestamp('grant_revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['export_id', 'source_key']);
        });
        Schema::create('aryeo_api_operations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('export_id')->index();
            $t->string('operation_key');
            $t->string('method');
            $t->string('path');
            $t->string('request_hash', 64);
            $t->text('request');
            $t->text('baseline')->nullable();
            $t->text('result')->nullable();
            $t->string('status')->default('prepared')->index();
            $t->string('provider_request_id')->nullable();
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->string('error_code')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->unique(['export_id', 'operation_key']);
        });
        Schema::create('aryeo_api_evidence', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('export_id')->index();
            $t->string('feature');
            $t->string('status');
            $t->string('listing_url', 2048);
            $t->string('evidence_url', 2048);
            $t->string('revision', 64);
            $t->unsignedBigInteger('verified_by');
            $t->timestamp('observed_at');
            $t->timestamps();
        });
        Schema::create('aryeo_api_cases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('export_id')->index();
            $t->string('kind');
            $t->string('status')->default('open');
            $t->text('reason');
            $t->string('owner')->default('Shubham');
            $t->string('next_action')->default('Reconcile provider state before further writes');
            $t->string('resolution_url', 2048)->nullable();
            $t->json('resolution')->nullable();
            $t->timestamp('escalate_at');
            $t->timestamp('resolved_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('aryeo_api_webhooks', function (Blueprint $t) {
            $t->string('event_id')->primary();
            $t->uuid('installation_id')->index();
            $t->string('type');
            $t->string('body_hash', 64);
            $t->text('payload');
            $t->timestamp('received_at');
            $t->timestamp('processed_at')->nullable();
        });
        Schema::create('aryeo_api_attempts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('export_id')->index();
            $t->string('result')->default('pending');
            $t->string('blocker')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
        });
        Schema::create('aryeo_api_alerts', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->uuid('export_id')->nullable();
            $t->string('owner');
            $t->string('message');
            $t->timestamp('created_at');
            $t->timestamp('acknowledged_at')->nullable();
            $t->unsignedBigInteger('acknowledged_by')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['alerts', 'attempts', 'webhooks', 'cases', 'evidence', 'operations', 'assets', 'exports', 'destinations', 'oauth_states', 'installations'] as $table) {
            Schema::dropIfExists('aryeo_api_'.$table);
        }
    }
};
