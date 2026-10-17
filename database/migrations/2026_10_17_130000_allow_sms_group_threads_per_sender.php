<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropUnique('message_threads_sms_group_id_unique');
            $table->index(['sms_group_id', 'last_message_at'], 'message_threads_sms_group_sender_lookup');
        });
    }

    public function down(): void
    {
        if (DB::table('message_threads')->whereNotNull('sms_group_id')->groupBy('sms_group_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore one thread per SMS group while multiple sender histories exist. All histories have been preserved.');
        }
        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropIndex('message_threads_sms_group_sender_lookup');
            $table->unique('sms_group_id');
        });
    }
};
