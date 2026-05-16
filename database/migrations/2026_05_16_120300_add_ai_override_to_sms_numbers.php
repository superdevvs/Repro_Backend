<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_numbers', function (Blueprint $table): void {
            if (!Schema::hasColumn('sms_numbers', 'sms_ai_enabled')) {
                $table->boolean('sms_ai_enabled')->nullable()->after('is_default');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sms_numbers', function (Blueprint $table): void {
            if (Schema::hasColumn('sms_numbers', 'sms_ai_enabled')) {
                $table->dropColumn('sms_ai_enabled');
            }
        });
    }
};
