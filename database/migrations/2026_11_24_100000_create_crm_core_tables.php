<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales CRM, core: contacts, their history and notes.
 *
 * Contacts are never deleted, so there are no soft deletes and no cascades.
 * References to users and shoots are plain indexed BIGINT columns without a
 * foreign key constraint: the MySQL cutover keeps imported signed IDs, and the
 * helper that matches constraint types (MigrationForeignId) is not on main yet.
 * Integrity is enforced by the CRM services.
 *
 * Status values are short strings with PHP constants, not enums, so the same
 * migration runs on SQLite today and MySQL after the cutover.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_contacts')) {
            Schema::create('crm_contacts', function (Blueprint $table) {
                $table->id();
                $table->string('name', 160);
                $table->string('name_normalized', 160);
                $table->string('company', 160)->nullable();
                $table->string('title', 120)->nullable();
                $table->string('email', 190)->nullable();
                $table->string('email_normalized', 190)->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('phone_digits', 20)->nullable();
                $table->string('address', 255)->nullable();
                $table->string('website', 190)->nullable();
                $table->string('market_state', 40)->nullable();
                $table->string('market_city', 120)->nullable();
                $table->string('segment', 16);
                $table->string('client_type', 16)->nullable();
                $table->string('stage', 24);
                $table->string('stage_before_lost', 24)->nullable();
                $table->string('lost_reason', 24)->nullable();
                $table->text('lost_note')->nullable();
                $table->timestamp('lost_at')->nullable();
                $table->unsignedBigInteger('assigned_rep_id')->nullable();
                $table->string('lead_source', 24)->default('other');
                $table->date('first_shoot_date')->nullable();
                $table->unsignedBigInteger('client_user_id')->nullable();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->timestamp('email_opt_out_at')->nullable();
                $table->string('email_status', 16)->nullable();
                $table->boolean('sms_consent')->default(false);
                $table->boolean('needs_review')->default(false);
                $table->json('review_reasons')->nullable();
                $table->string('health', 16)->nullable();
                $table->unsignedSmallInteger('usual_gap_days')->nullable();
                $table->timestamp('stage_changed_at')->nullable();
                $table->timestamp('last_contacted_at')->nullable();
                $table->timestamp('first_contact_due_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['assigned_rep_id', 'stage'], 'crm_contacts_rep_stage_idx');
                $table->index('stage', 'crm_contacts_stage_idx');
                $table->index('email_normalized', 'crm_contacts_email_idx');
                $table->index('phone_digits', 'crm_contacts_phone_idx');
                $table->index('name_normalized', 'crm_contacts_name_idx');
                $table->unique('client_user_id', 'crm_contacts_client_user_uq');
                $table->index('company_id', 'crm_contacts_company_idx');
                $table->index('needs_review', 'crm_contacts_review_idx');
            });
        }

        if (! Schema::hasTable('crm_stage_changes')) {
            Schema::create('crm_stage_changes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('contact_id');
                $table->string('from_stage', 24)->nullable();
                $table->string('to_stage', 24);
                $table->string('reason', 255)->nullable();
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->string('source', 16)->default('user');
                $table->timestamp('occurred_at');
                $table->timestamps();
                $table->index(['contact_id', 'occurred_at'], 'crm_stage_changes_contact_idx');
                $table->index(['to_stage', 'occurred_at'], 'crm_stage_changes_to_idx');
            });
        }

        if (! Schema::hasTable('crm_assignment_changes')) {
            Schema::create('crm_assignment_changes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('contact_id');
                $table->unsignedBigInteger('from_rep_id')->nullable();
                $table->unsignedBigInteger('to_rep_id')->nullable();
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->string('source', 16)->default('user');
                $table->timestamp('occurred_at');
                $table->timestamps();
                $table->index(['contact_id', 'occurred_at'], 'crm_assignment_contact_idx');
            });
        }

        if (! Schema::hasTable('crm_duplicate_alerts')) {
            Schema::create('crm_duplicate_alerts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('attempted_by')->nullable();
                $table->unsignedBigInteger('matched_contact_id');
                $table->string('match_type', 16);
                $table->json('payload')->nullable();
                $table->string('origin', 16)->default('form');
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->string('resolution', 24)->nullable();
                $table->timestamps();
                $table->index(['resolved_at', 'created_at'], 'crm_dup_alerts_open_idx');
            });
        }

        if (! Schema::hasTable('crm_notes')) {
            Schema::create('crm_notes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('contact_id');
                $table->unsignedBigInteger('author_id')->nullable();
                $table->text('body');
                $table->boolean('via_robbie')->default(false);
                $table->timestamp('edited_at')->nullable();
                $table->timestamps();
                $table->index(['contact_id', 'created_at'], 'crm_notes_contact_idx');
            });
        }

        if (! Schema::hasTable('crm_note_versions')) {
            Schema::create('crm_note_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('note_id');
                $table->text('body');
                $table->unsignedBigInteger('saved_by')->nullable();
                $table->timestamps();
                $table->index('note_id', 'crm_note_versions_note_idx');
            });
        }

        if (! Schema::hasTable('crm_activities')) {
            Schema::create('crm_activities', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('contact_id');
                $table->string('type', 40);
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->boolean('via_robbie')->default(false);
                $table->string('summary', 255);
                $table->json('data')->nullable();
                $table->string('subject_type', 40)->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();
                $table->index(['contact_id', 'occurred_at'], 'crm_activities_contact_idx');
            });
        }

        if (! Schema::hasTable('crm_notices')) {
            Schema::create('crm_notices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('type', 40);
                $table->string('title', 190);
                $table->string('body', 500)->nullable();
                $table->unsignedBigInteger('contact_id')->nullable();
                $table->json('data')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'read_at'], 'crm_notices_user_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['crm_notices', 'crm_activities', 'crm_note_versions', 'crm_notes', 'crm_duplicate_alerts', 'crm_assignment_changes', 'crm_stage_changes', 'crm_contacts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
