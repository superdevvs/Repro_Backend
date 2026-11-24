<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales CRM, email: signatures, approved branded templates (versioned), every
 * email sent with its exact content, delivery events, and a global suppression
 * list that blocks campaign email to anyone who unsubscribed, bounced or complained.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_signatures')) {
            Schema::create('crm_signatures', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedInteger('version')->default(1);
                $table->json('fields');
                $table->string('headshot_path', 255)->nullable();
                $table->string('sender_email', 190);
                $table->string('status', 16)->default('pending');
                $table->string('review_comment', 500)->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->boolean('is_current_approved')->default(false);
                $table->timestamps();
                $table->index(['user_id', 'status'], 'crm_signatures_user_idx');
            });
        }

        if (! Schema::hasTable('crm_email_templates')) {
            Schema::create('crm_email_templates', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 16)->default('one_off');
                $table->string('name', 160);
                $table->string('status', 16)->default('draft');
                $table->unsignedBigInteger('approved_version_id')->nullable();
                $table->unsignedBigInteger('latest_version_id')->nullable();
                $table->unsignedBigInteger('owner_id')->nullable();
                $table->timestamps();
                $table->index(['kind', 'status'], 'crm_email_templates_kind_idx');
            });
        }

        if (! Schema::hasTable('crm_email_template_versions')) {
            Schema::create('crm_email_template_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('template_id');
                $table->unsignedInteger('version_no');
                $table->string('subject', 200);
                $table->string('preheader', 200)->nullable();
                $table->text('body_html');
                $table->string('image_url', 500)->nullable();
                $table->string('cta_label', 80)->nullable();
                $table->string('cta_url', 500)->nullable();
                $table->string('status', 16)->default('draft');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('review_comment', 1000)->nullable();
                $table->json('content_check')->nullable();
                $table->timestamps();
                $table->unique(['template_id', 'version_no'], 'crm_tpl_versions_uq');
            });
        }

        if (! Schema::hasTable('crm_email_sends')) {
            Schema::create('crm_email_sends', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('contact_id')->nullable();
                $table->unsignedBigInteger('applicant_id')->nullable();
                $table->unsignedBigInteger('sender_user_id')->nullable();
                $table->string('source_type', 16);
                $table->unsignedBigInteger('source_id')->nullable();
                $table->unsignedBigInteger('template_version_id')->nullable();
                $table->unsignedBigInteger('signature_id')->nullable();
                $table->string('from_email', 190);
                $table->string('from_name', 160)->nullable();
                $table->string('reply_to', 190)->nullable();
                $table->string('to_email', 190);
                $table->string('subject', 200);
                $table->longText('html');
                $table->text('text');
                $table->string('transport', 16);
                $table->string('provider_message_id', 120)->nullable();
                $table->string('status', 16)->default('queued');
                $table->string('error', 500)->nullable();
                $table->string('idempotency_key', 120)->nullable();
                $table->string('reply_token', 40)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('first_opened_at')->nullable();
                $table->unsignedInteger('open_count')->default(0);
                $table->unsignedInteger('click_count')->default(0);
                $table->timestamp('bounced_at')->nullable();
                $table->timestamp('complained_at')->nullable();
                $table->timestamp('replied_at')->nullable();
                $table->timestamps();
                $table->unique('provider_message_id', 'crm_email_sends_provider_uq');
                $table->unique('idempotency_key', 'crm_email_sends_idem_uq');
                $table->unique('reply_token', 'crm_email_sends_reply_uq');
                $table->index(['contact_id', 'created_at'], 'crm_email_sends_contact_idx');
                $table->index(['sender_user_id', 'created_at'], 'crm_email_sends_sender_idx');
                $table->index(['source_type', 'source_id'], 'crm_email_sends_source_idx');
            });
        }

        if (! Schema::hasTable('crm_email_events')) {
            Schema::create('crm_email_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('send_id');
                $table->string('provider_event_id', 120)->nullable();
                $table->string('type', 24);
                $table->string('link', 500)->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();
                $table->unique('provider_event_id', 'crm_email_events_provider_uq');
                $table->index(['send_id', 'type'], 'crm_email_events_send_idx');
            });
        }

        if (! Schema::hasTable('email_suppressions')) {
            Schema::create('email_suppressions', function (Blueprint $table) {
                $table->id();
                $table->string('email_normalized', 190);
                $table->string('reason', 24);
                $table->string('source', 40)->nullable();
                $table->unsignedBigInteger('contact_id')->nullable();
                $table->timestamps();
                $table->unique('email_normalized', 'email_suppressions_email_uq');
            });
        }
    }

    public function down(): void
    {
        foreach (['email_suppressions', 'crm_email_events', 'crm_email_sends', 'crm_email_template_versions', 'crm_email_templates', 'crm_signatures'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
