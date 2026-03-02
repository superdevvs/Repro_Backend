<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_branding', function (Blueprint $table) {
            $table->string('banner')->nullable()->after('logo');
            $table->text('about')->nullable()->after('custom_domain');
        });
    }

    public function down(): void
    {
        Schema::table('user_branding', function (Blueprint $table) {
            $table->dropColumn(['banner', 'about']);
        });
    }
};
