<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_mobile_sessions', function (Blueprint $table) {
            // Existing sessions remain unbound and therefore ineligible. Never
            // guess which login created a historical provider credential.
            $table->foreignId('personal_access_token_id')->nullable()
                ->constrained('personal_access_tokens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('voice_mobile_sessions', fn (Blueprint $table) => $table->dropConstrainedForeignId('personal_access_token_id'));
    }
};
