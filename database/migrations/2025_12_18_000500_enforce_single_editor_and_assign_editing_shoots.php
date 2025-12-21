<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $primaryEditorId = 377;

        // Demote other editor accounts to client (single editor system)
        DB::table('users')
            ->where('role', 'editor')
            ->where('id', '!=', $primaryEditorId)
            ->update(['role' => 'client']);

        // Ensure any shoots currently in editing/review with no editor assigned are assigned to primary editor
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
