<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'address')) {
                $table->string('address')->nullable()->after('company_name');
            }
            if (!Schema::hasColumn('users', 'city')) {
                $table->string('city', 120)->nullable()->after('address');
            }
            if (!Schema::hasColumn('users', 'state')) {
                $table->string('state', 50)->nullable()->after('city');
            }
            if (!Schema::hasColumn('users', 'zip')) {
                $table->string('zip', 20)->nullable()->after('state');
            }
            if (!Schema::hasColumn('users', 'license_number')) {
                $table->string('license_number', 120)->nullable()->after('zip');
            }
            if (!Schema::hasColumn('users', 'company_notes')) {
                $table->text('company_notes')->nullable()->after('license_number');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = ['company_notes', 'license_number', 'zip', 'state', 'city', 'address'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
