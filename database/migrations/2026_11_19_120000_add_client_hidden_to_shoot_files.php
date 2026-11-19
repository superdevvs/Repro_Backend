<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-only "Hidden" marking. Unlike `is_hidden` (a tour/curation flag the
 * client can toggle and still sees in their own gallery), a client-hidden file
 * is withheld from every client surface and only staff can restore it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoot_files', function (Blueprint $table) {
            if (! Schema::hasColumn('shoot_files', 'is_client_hidden')) {
                $table->boolean('is_client_hidden')->default(false)->after('is_hidden');
            }
            if (! Schema::hasColumn('shoot_files', 'client_hidden_at')) {
                $table->timestamp('client_hidden_at')->nullable()->after('is_client_hidden');
            }
            if (! Schema::hasColumn('shoot_files', 'client_hidden_by')) {
                $table->unsignedBigInteger('client_hidden_by')->nullable()->after('client_hidden_at');
            }
        });
        Schema::table('shoot_files', function (Blueprint $table) {
            $table->index(['shoot_id', 'is_client_hidden'], 'shoot_files_shoot_client_hidden_index');
        });
    }

    public function down(): void
    {
        Schema::table('shoot_files', function (Blueprint $table) {
            $table->dropIndex('shoot_files_shoot_client_hidden_index');
        });
        Schema::table('shoot_files', function (Blueprint $table) {
            foreach (['client_hidden_by', 'client_hidden_at', 'is_client_hidden'] as $column) {
                if (Schema::hasColumn('shoot_files', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
