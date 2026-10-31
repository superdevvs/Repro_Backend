<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_mobile_sessions', function (Blueprint $table) {
            $table->string('credential_rotation_from')->nullable();
            $table->string('credential_cleanup_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('voice_mobile_sessions', fn (Blueprint $table) => $table->dropColumn(['credential_rotation_from', 'credential_cleanup_id']));
    }
};
