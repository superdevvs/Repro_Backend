<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (!Schema::hasColumn('messages', 'sender_user_id')) {
                $table->foreignId('sender_user_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('messages', 'sender_account_id')) {
                $table->unsignedBigInteger('sender_account_id')->nullable()->after('sender_user_id');
            }
            if (!Schema::hasColumn('messages', 'sender_role')) {
                $table->string('sender_role')->nullable()->after('sender_account_id');
            }
            if (!Schema::hasColumn('messages', 'sender_display_name')) {
                $table->string('sender_display_name')->nullable()->after('sender_role');
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'sender_display_name')) {
                $table->dropColumn('sender_display_name');
            }
            if (Schema::hasColumn('messages', 'sender_role')) {
                $table->dropColumn('sender_role');
            }
            if (Schema::hasColumn('messages', 'sender_account_id')) {
                $table->dropColumn('sender_account_id');
            }
            if (Schema::hasColumn('messages', 'sender_user_id')) {
                $table->dropConstrainedForeignId('sender_user_id');
            }
        });
    }
};
