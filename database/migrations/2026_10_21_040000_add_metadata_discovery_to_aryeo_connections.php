<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aryeo_connections', function (Blueprint $table) {
            // Request metadata may be discovered before a client is authorized
            // for media access. This flag never expands client_ids or delivery.
            $table->boolean('discover_all_clients')->default(false);
        });
        Schema::table('aryeo_requests', fn (Blueprint $table) => $table->json('match_verification')->nullable());
    }

    public function down(): void
    {
        Schema::table('aryeo_requests', fn (Blueprint $table) => $table->dropColumn('match_verification'));
        Schema::table('aryeo_connections', fn (Blueprint $table) => $table->dropColumn('discover_all_clients'));
    }
};
