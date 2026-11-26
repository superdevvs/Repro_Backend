<?php

use App\Support\MigrationForeignId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_device_preferences', function (Blueprint $table) {
            // A native sign-in token belongs to one phone. Never key preferences
            // by the account alone: the same administrator can use many phones.
            MigrationForeignId::matching($table, 'personal_access_token_id', 'personal_access_tokens')->primary()->constrained('personal_access_tokens')->cascadeOnDelete();
            MigrationForeignId::matching($table, 'user_id', 'users')->constrained()->cascadeOnDelete();
            $table->uuid('installation_id');
            $table->unsignedBigInteger('sequence');
            $table->json('preferences');
            $table->json('enabled_after');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_device_preferences');
    }
};
