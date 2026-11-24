<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recruiting photographers and sales reps. Applicants are never deleted; a past
 * applicant can be reopened. Documents live on the private disk and are served
 * only to the super admin and people they grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recruit_job_postings')) {
            Schema::create('recruit_job_postings', function (Blueprint $table) {
                $table->id();
                $table->string('role', 16);
                $table->string('title', 160);
                $table->string('state', 40);
                $table->string('region', 120)->nullable();
                $table->string('slug', 190);
                $table->text('description')->nullable();
                $table->string('status', 16)->default('draft');
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique('slug', 'recruit_postings_slug_uq');
                $table->index(['status', 'role'], 'recruit_postings_status_idx');
            });
        }

        if (! Schema::hasTable('recruit_applicants')) {
            Schema::create('recruit_applicants', function (Blueprint $table) {
                $table->id();
                $table->string('role', 16);
                $table->string('name', 160);
                $table->string('email', 190)->nullable();
                $table->string('email_normalized', 190)->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('state', 40)->nullable();
                $table->string('stage', 24)->default('applied');
                $table->string('stage_before_exit', 24)->nullable();
                $table->string('exit_reason', 255)->nullable();
                $table->unsignedBigInteger('posting_id')->nullable();
                $table->string('source', 24)->default('other');
                $table->json('states')->nullable();
                $table->string('metros', 255)->nullable();
                $table->unsignedSmallInteger('travel_radius')->nullable();
                $table->string('portfolio_url', 500)->nullable();
                $table->json('gear')->nullable();
                $table->boolean('flies_drone')->default(false);
                $table->string('part107_number', 40)->nullable();
                $table->date('part107_expiry')->nullable();
                $table->date('insurance_expiry')->nullable();
                $table->text('sales_experience')->nullable();
                $table->string('specialty', 16)->nullable();
                $table->json('scores')->nullable();
                $table->decimal('score', 3, 1)->nullable();
                $table->unsignedBigInteger('hired_user_id')->nullable();
                $table->string('external_reference', 120)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('stage_changed_at')->nullable();
                $table->timestamps();
                $table->index(['role', 'stage'], 'recruit_applicants_role_stage_idx');
                $table->index('email_normalized', 'recruit_applicants_email_idx');
                $table->unique('external_reference', 'recruit_applicants_ext_uq');
            });
        }

        if (! Schema::hasTable('recruit_applicant_files')) {
            Schema::create('recruit_applicant_files', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('applicant_id');
                $table->string('kind', 24);
                $table->string('path', 500);
                $table->string('original_name', 255);
                $table->string('mime', 120)->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->timestamps();
                $table->index('applicant_id', 'recruit_files_applicant_idx');
            });
        }

        if (! Schema::hasTable('recruit_stage_changes')) {
            Schema::create('recruit_stage_changes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('applicant_id');
                $table->string('from_stage', 24)->nullable();
                $table->string('to_stage', 24);
                $table->string('reason', 255)->nullable();
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();
                $table->index('applicant_id', 'recruit_stage_changes_idx');
            });
        }

        if (! Schema::hasTable('recruit_notes')) {
            Schema::create('recruit_notes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('applicant_id');
                $table->unsignedBigInteger('author_id')->nullable();
                $table->string('kind', 16)->default('note');
                $table->text('body');
                $table->json('scores')->nullable();
                $table->timestamps();
                $table->index('applicant_id', 'recruit_notes_applicant_idx');
            });
        }

        if (! Schema::hasTable('recruit_roster')) {
            Schema::create('recruit_roster', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('role', 16);
                $table->unsignedBigInteger('applicant_id')->nullable();
                $table->json('states')->nullable();
                $table->string('metros', 255)->nullable();
                $table->unsignedSmallInteger('travel_radius')->nullable();
                $table->json('gear')->nullable();
                $table->string('part107_number', 40)->nullable();
                $table->date('part107_expiry')->nullable();
                $table->date('insurance_expiry')->nullable();
                $table->date('part107_reminded_for')->nullable();
                $table->date('insurance_reminded_for')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->unique('user_id', 'recruit_roster_user_uq');
            });
        }
    }

    public function down(): void
    {
        foreach (['recruit_roster', 'recruit_notes', 'recruit_stage_changes', 'recruit_applicant_files', 'recruit_applicants', 'recruit_job_postings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
