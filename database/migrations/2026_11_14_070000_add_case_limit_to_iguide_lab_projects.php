<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iguide_lab_projects', fn (Blueprint $table) => $table->unsignedInteger('max_cases')->default(80));
    }

    public function down(): void
    {
        Schema::table('iguide_lab_projects', fn (Blueprint $table) => $table->dropColumn('max_cases'));
    }
};
