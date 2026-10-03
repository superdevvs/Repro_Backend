<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'office_phone')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('office_phone', 50)->nullable()->after('phone');
            });
        }

        if (! Schema::hasColumn('users', 'show_office_phone_on_tour')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('show_office_phone_on_tour')->default(false)->after('office_phone');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'show_office_phone_on_tour')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('show_office_phone_on_tour');
            });
        }

        if (Schema::hasColumn('users', 'office_phone')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('office_phone');
            });
        }
    }
};
