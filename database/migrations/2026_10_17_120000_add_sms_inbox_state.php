<?php

use App\Services\Messaging\SmsInboxPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SQLite readers/workers must not wait through the historical message scan.
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->boolean('sms_inbox_active')->default(false);
            $table->boolean('sms_needs_reply')->default(false);
            $table->index(['channel', 'sms_inbox_active', 'last_message_at'], 'message_threads_sms_inbox_index');
        });
        app(SmsInboxPolicy::class)->backfill();
    }

    public function down(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropIndex('message_threads_sms_inbox_index');
            $table->dropColumn(['sms_inbox_active', 'sms_needs_reply']);
        });
    }
};
