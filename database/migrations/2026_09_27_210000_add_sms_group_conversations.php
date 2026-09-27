<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->foreignId('sms_group_id')->nullable()->unique()->constrained('sms_groups')->nullOnDelete();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('hidden_from_inbox')->default(false);
            $table->index('hidden_from_inbox');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['hidden_from_inbox']);
            $table->dropColumn('hidden_from_inbox');
        });

        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sms_group_id');
        });
    }
};
