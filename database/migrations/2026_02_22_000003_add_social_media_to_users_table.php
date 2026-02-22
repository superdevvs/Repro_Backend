<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('facebook_url', 500)->nullable()->after('about');
            $table->string('twitter_url', 500)->nullable()->after('facebook_url');
            $table->string('linkedin_url', 500)->nullable()->after('twitter_url');
            $table->string('pinterest_url', 500)->nullable()->after('linkedin_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'facebook_url', 'twitter_url', 'linkedin_url', 'pinterest_url']);
        });
    }
};
