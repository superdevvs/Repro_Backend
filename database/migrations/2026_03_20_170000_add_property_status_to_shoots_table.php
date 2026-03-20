<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('shoots', 'property_status')) {
            return;
        }

        Schema::table('shoots', function (Blueprint $table) {
            $table->enum('property_status', ['available', 'sold', 'rented'])->default('available')->after('listing_type');
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropColumn('property_status');
        });
    }
};
