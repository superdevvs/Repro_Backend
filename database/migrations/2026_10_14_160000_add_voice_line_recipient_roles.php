<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_line_policies', fn (Blueprint $table) => $table->json('recipient_roles')->nullable());
    }

    public function down(): void
    {
        Schema::table('voice_line_policies', fn (Blueprint $table) => $table->dropColumn('recipient_roles'));
    }
};
