<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->string('marketing_consent_email', 190)->nullable();
            $table->timestamp('marketing_consent_at')->nullable();
            $table->string('marketing_consent_source', 32)->nullable();
            $table->string('sms_consent_phone', 32)->nullable();
        });
        Schema::table('crm_event_calendar_links', function (Blueprint $table) {
            $table->boolean('push_pending')->default(false);
        });
        Schema::create('crm_sms_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('user_id');
            $table->uuid('request_id');
            $table->string('payload_hash', 64);
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('status', 24)->default('pending');
            $table->timestamps();
            $table->unique(['user_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_sms_requests');
        Schema::table('crm_event_calendar_links', fn (Blueprint $table) => $table->dropColumn('push_pending'));
        Schema::table('crm_contacts', fn (Blueprint $table) => $table->dropColumn([
            'marketing_consent_email', 'marketing_consent_at', 'marketing_consent_source', 'sms_consent_phone',
        ]));
    }
};
