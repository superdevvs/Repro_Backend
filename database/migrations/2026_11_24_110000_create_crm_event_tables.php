<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales CRM, follow-ups: event types, scheduled events, and the link from an
 * event to its copy in the user's Google Calendar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_event_types')) {
            Schema::create('crm_event_types', function (Blueprint $table) {
                $table->id();
                $table->string('key', 40)->unique('crm_event_types_key_uq');
                $table->string('label', 80);
                $table->string('icon', 32)->default('calendar');
                $table->boolean('counts_as_contact')->default(true);
                $table->boolean('is_system')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort')->default(100);
                $table->timestamps();
            });

            $now = now();
            $types = [
                ['call', 'Call', 'phone', true, 10],
                ['email', 'Email', 'mail', true, 20],
                ['checkin_call', 'Check-in call', 'phone', true, 30],
                ['checkin_email', 'Check-in email', 'mail', true, 40],
                ['meeting', 'Meeting', 'users', true, 50],
                ['shoot', 'Shoot', 'camera', false, 60],
                ['interview', 'Interview', 'video', false, 70],
                ['other', 'Other', 'calendar', false, 90],
            ];
            DB::table('crm_event_types')->insert(array_map(fn ($t) => [
                'key' => $t[0], 'label' => $t[1], 'icon' => $t[2], 'counts_as_contact' => $t[3], 'is_system' => true,
                'is_active' => true, 'sort' => $t[4], 'created_at' => $now, 'updated_at' => $now,
            ], $types));
        }

        if (! Schema::hasTable('crm_events')) {
            Schema::create('crm_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('contact_id')->nullable();
                $table->unsignedBigInteger('applicant_id')->nullable();
                $table->unsignedBigInteger('type_id');
                $table->timestamp('starts_at');
                $table->unsignedSmallInteger('duration_min')->default(15);
                $table->unsignedBigInteger('assigned_user_id');
                $table->text('note')->nullable();
                $table->string('status', 16)->default('planned');
                $table->text('outcome_note')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->unsignedBigInteger('next_event_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->boolean('via_robbie')->default(false);
                $table->timestamps();
                $table->index(['assigned_user_id', 'status', 'starts_at'], 'crm_events_user_due_idx');
                $table->index(['contact_id', 'status', 'starts_at'], 'crm_events_contact_idx');
                $table->index(['applicant_id', 'starts_at'], 'crm_events_applicant_idx');
            });
        }

        if (! Schema::hasTable('crm_event_calendar_links')) {
            Schema::create('crm_event_calendar_links', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('event_id');
                $table->unsignedBigInteger('user_id');
                $table->string('calendar_id', 190)->default('primary');
                $table->string('google_event_id', 190)->nullable();
                $table->string('fingerprint', 64)->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->string('last_error', 255)->nullable();
                $table->timestamps();
                $table->unique(['event_id', 'user_id'], 'crm_event_cal_links_uq');
                $table->index(['user_id', 'google_event_id'], 'crm_event_cal_links_google_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['crm_event_calendar_links', 'crm_events', 'crm_event_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
