<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales CRM, drips and campaigns.
 *
 * Drip campaigns are versioned: editing a live drip publishes a new version and
 * changes only future steps. Enrolments remember the last send day reached, so
 * contacts keep their place. A unique column keeps one active drip per contact.
 *
 * The three drips ship with placeholder steps; admins write the emails.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_drip_campaigns')) {
            Schema::create('crm_drip_campaigns', function (Blueprint $table) {
                $table->id();
                $table->string('name', 160);
                $table->string('length_key', 16);
                $table->string('status', 16)->default('draft');
                $table->unsignedBigInteger('published_version_id')->nullable();
                $table->unsignedBigInteger('draft_version_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('crm_drip_campaign_versions')) {
            Schema::create('crm_drip_campaign_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('campaign_id');
                $table->unsignedInteger('version_no');
                $table->timestamp('published_at')->nullable();
                $table->unsignedBigInteger('published_by')->nullable();
                $table->timestamps();
                $table->unique(['campaign_id', 'version_no'], 'crm_drip_versions_uq');
            });
        }

        if (! Schema::hasTable('crm_drip_steps')) {
            Schema::create('crm_drip_steps', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('campaign_version_id');
                $table->unsignedSmallInteger('send_day');
                $table->unsignedBigInteger('template_id')->nullable();
                $table->unsignedBigInteger('template_version_id')->nullable();
                $table->string('label', 160)->nullable();
                $table->timestamps();
                $table->index(['campaign_version_id', 'send_day'], 'crm_drip_steps_version_idx');
            });
        }

        if (! Schema::hasTable('crm_drip_enrollments')) {
            Schema::create('crm_drip_enrollments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('contact_id');
                $table->unsignedBigInteger('campaign_id');
                // Set only while active; unique, so a contact has at most one active drip.
                $table->unsignedBigInteger('active_contact_id')->nullable();
                $table->string('status', 16)->default('active');
                $table->timestamp('started_at');
                $table->integer('last_sent_day')->default(-1);
                $table->unsignedSmallInteger('steps_sent')->default(0);
                $table->timestamp('next_send_at')->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->string('stop_reason', 32)->nullable();
                $table->timestamp('stopped_at')->nullable();
                $table->unsignedBigInteger('enrolled_by')->nullable();
                $table->boolean('via_robbie')->default(false);
                $table->timestamps();
                $table->unique('active_contact_id', 'crm_drip_enrol_active_uq');
                $table->index(['status', 'next_send_at'], 'crm_drip_enrol_due_idx');
                $table->index(['contact_id', 'created_at'], 'crm_drip_enrol_contact_idx');
            });
        }

        if (! Schema::hasTable('crm_campaigns')) {
            Schema::create('crm_campaigns', function (Blueprint $table) {
                $table->id();
                $table->string('name', 160);
                $table->unsignedBigInteger('owner_id');
                $table->unsignedBigInteger('template_id');
                $table->unsignedBigInteger('template_version_id');
                $table->string('subject', 200);
                $table->text('body_html');
                $table->string('image_url', 500)->nullable();
                $table->json('audience');
                $table->string('status', 16)->default('draft');
                $table->json('content_check')->nullable();
                $table->string('review_comment', 1000)->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->unsignedInteger('recipient_count')->default(0);
                $table->timestamps();
                $table->index(['status', 'scheduled_at'], 'crm_campaigns_due_idx');
                $table->index('owner_id', 'crm_campaigns_owner_idx');
            });
        }

        if (! Schema::hasTable('crm_campaign_recipients')) {
            Schema::create('crm_campaign_recipients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('campaign_id');
                $table->unsignedBigInteger('contact_id');
                $table->unsignedBigInteger('send_id')->nullable();
                $table->string('status', 16)->default('pending');
                $table->string('reason', 120)->nullable();
                $table->timestamps();
                $table->unique(['campaign_id', 'contact_id'], 'crm_campaign_recipients_uq');
            });
        }

        $this->seedDrips();
    }

    private function seedDrips(): void
    {
        if (DB::table('crm_drip_campaigns')->exists()) {
            return;
        }
        $now = now();
        $drips = [
            ['12-week new agent drip', '12_weeks', [0, 7, 14, 28, 42, 56, 70, 84]],
            ['6-month nurture', '6_months', [0, 14, 30, 45, 60, 90, 120, 150, 180]],
            ['12-month stay in touch', '12_months', [0, 30, 60, 90, 120, 150, 180, 210, 240, 270, 300, 330, 360]],
        ];
        foreach ($drips as [$name, $length, $days]) {
            $campaignId = DB::table('crm_drip_campaigns')->insertGetId(['name' => $name, 'length_key' => $length, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
            $versionId = DB::table('crm_drip_campaign_versions')->insertGetId(['campaign_id' => $campaignId, 'version_no' => 1, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('crm_drip_campaigns')->where('id', $campaignId)->update(['draft_version_id' => $versionId]);
            foreach ($days as $i => $day) {
                DB::table('crm_drip_steps')->insert(['campaign_version_id' => $versionId, 'send_day' => $day, 'label' => 'Placeholder step '.($i + 1), 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        foreach (['crm_campaign_recipients', 'crm_campaigns', 'crm_drip_enrollments', 'crm_drip_steps', 'crm_drip_campaign_versions', 'crm_drip_campaigns'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
