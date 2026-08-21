<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('override_health_status', 30)->nullable();
            $table->string('override_health_message')->nullable();
            $table->timestamp('override_health_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn(['override_health_status', 'override_health_message', 'override_health_checked_at']);
        });
    }
};
