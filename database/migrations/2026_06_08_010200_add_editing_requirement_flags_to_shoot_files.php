<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoot_files', function (Blueprint $table) {
            if (!Schema::hasColumn('shoot_files', 'is_extra')) {
                $table->boolean('is_extra')->default(false)->after('is_hidden');
            }
            if (!Schema::hasColumn('shoot_files', 'required_for_editing')) {
                $table->boolean('required_for_editing')->default(false)->after('is_extra');
            }
        });

        if (Schema::hasColumn('shoot_files', 'is_extra')) {
            DB::table('shoot_files')
                ->where(function ($query) {
                    $query->where('media_type', 'extra')
                        ->orWhere('path', 'like', '%/extra/%')
                        ->orWhere('storage_path', 'like', '%/extra/%')
                        ->orWhere('dropbox_path', 'like', '%/extra/%');
                })
                ->update(['is_extra' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('shoot_files', function (Blueprint $table) {
            foreach (['required_for_editing', 'is_extra'] as $column) {
                if (Schema::hasColumn('shoot_files', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
