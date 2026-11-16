<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Why a recovery schedule stopped early, e.g. a protected iGUIDE view without an access token. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_asset_recovery_attempts', function (Blueprint $table) {
            $table->string('outcome', 32)->nullable()->after('gave_up_at');
        });
    }

    public function down(): void
    {
        Schema::table('provider_asset_recovery_attempts', function (Blueprint $table) {
            $table->dropColumn('outcome');
        });
    }
};
