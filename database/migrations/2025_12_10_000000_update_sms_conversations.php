<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->foreignId('assigned_to_user_id')->nullable()->after('contact_id')->constrained('users')->nullOnDelete();
            $table->string('status')->nullable()->after('last_snippet');
            $table->json('tags_json')->nullable()->after('status');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->json('phones_json')->nullable()->after('phone');
            $table->json('tags_json')->nullable()->after('phones_json');
            $table->text('comment')->nullable()->after('tags_json');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropForeign(['assigned_to_user_id']);
            $table->dropColumn(['assigned_to_user_id', 'status', 'tags_json']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['phones_json', 'tags_json', 'comment']);
        });
    }
};

