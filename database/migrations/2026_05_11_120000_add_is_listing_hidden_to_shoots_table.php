<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->boolean('is_listing_hidden')->default(false)->after('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropColumn('is_listing_hidden');
        });
    }
};
