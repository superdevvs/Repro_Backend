<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_numbers', function (Blueprint $table): void {
            if (!Schema::hasColumn('sms_numbers', 'voice_ai_enabled')) {
                $table->boolean('voice_ai_enabled')->nullable()->after('sms_ai_enabled');
            }

            if (!Schema::hasColumn('sms_numbers', 'voice_assistant_id_override')) {
                $table->string('voice_assistant_id_override')->nullable()->after('voice_ai_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sms_numbers', function (Blueprint $table): void {
            if (Schema::hasColumn('sms_numbers', 'voice_assistant_id_override')) {
                $table->dropColumn('voice_assistant_id_override');
            }

            if (Schema::hasColumn('sms_numbers', 'voice_ai_enabled')) {
                $table->dropColumn('voice_ai_enabled');
            }
        });
    }
};
