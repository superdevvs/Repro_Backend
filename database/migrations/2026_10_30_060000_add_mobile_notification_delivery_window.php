<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_notification_deliveries', function (Blueprint $table) {
            $table->timestamp('not_before')->nullable()->index();
            $table->unsignedTinyInteger('attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('mobile_notification_deliveries', fn (Blueprint $table) => $table->dropColumn(['not_before', 'attempts']));
    }
};
