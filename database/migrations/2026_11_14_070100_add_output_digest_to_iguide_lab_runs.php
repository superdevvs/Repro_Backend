<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iguide_lab_runs', fn (Blueprint $table) => $table->string('output_sha256', 64)->nullable());
    }

    public function down(): void
    {
        Schema::table('iguide_lab_runs', fn (Blueprint $table) => $table->dropColumn('output_sha256'));
    }
};
