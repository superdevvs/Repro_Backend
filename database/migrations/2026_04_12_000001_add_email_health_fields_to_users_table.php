<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_status', 32)->nullable()->after('email_verified_at');
            $table->timestamp('verification_sent_at')->nullable()->after('email_status');
            $table->timestamp('email_last_delivery_attempt_at')->nullable()->after('verification_sent_at');
            $table->timestamp('email_last_bounced_at')->nullable()->after('email_last_delivery_attempt_at');
            $table->text('email_bounce_reason')->nullable()->after('email_last_bounced_at');
            $table->string('email_warning_code', 64)->nullable()->after('email_bounce_reason');
            $table->text('email_warning_message')->nullable()->after('email_warning_code');
            $table->string('email_suggested_correction')->nullable()->after('email_warning_message');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'email_status',
                'verification_sent_at',
                'email_last_delivery_attempt_at',
                'email_last_bounced_at',
                'email_bounce_reason',
                'email_warning_code',
                'email_warning_message',
                'email_suggested_correction',
            ]);
        });
    }
};
