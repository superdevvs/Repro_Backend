<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel rebuilds the SQLite constraint while retaining rows, keys and indexes.
        Schema::table('shoot_notes', function (Blueprint $table): void {
            $table->enum('type', ['shoot', 'company', 'photographer', 'editing', 'approval'])
                ->default('shoot')->change();
        });
        Schema::table('shoots', function (Blueprint $table): void {
            $table->text('approval_annotation')->nullable();
        });
        // Approval annotations are new records. Historical decisions/logs are not rewritten.
    }

    public function down(): void
    {
        // Never silently delete annotations to fit the old CHECK constraint.
        if (DB::table('shoot_notes')->where('type', 'approval')->exists()
            || DB::table('shoots')->whereNotNull('approval_annotation')->exists()) {
            throw new RuntimeException('Export approval annotations before rolling back this migration.');
        }
        Schema::table('shoot_notes', function (Blueprint $table): void {
            $table->enum('type', ['shoot', 'company', 'photographer', 'editing'])
                ->default('shoot')->change();
        });
        Schema::table('shoots', fn (Blueprint $table) => $table->dropColumn('approval_annotation'));
    }
};
