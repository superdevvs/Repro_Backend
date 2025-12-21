<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $primaryEditorId = 377;

        // Demote all other editor accounts to client (single-editor system)
        DB::table('users')
            ->where('role', 'editor')
            ->where('id', '!=', $primaryEditorId)
            ->update(['role' => 'client']);

        if (!Schema::hasColumn('shoots', 'editor_id')) {
            return;
        }

        // Backfill existing shoots in editing/review with the primary editor
        DB::table('shoots')
            ->whereNull('editor_id')
            ->whereIn('workflow_status', ['editing', 'review'])
            ->update(['editor_id' => $primaryEditorId]);

        DB::table('shoots')
            ->whereNull('editor_id')
            ->whereIn('status', ['editing', 'review'])
            ->update(['editor_id' => $primaryEditorId]);
    }

    public function down(): void
    {
        // One-way enforcement migration
    }
};
