<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standing editor notes: an instruction written once for a client (or for every
 * client whose company name contains a brokerage keyword) that is shown to the
 * editor on each of that client's shoots without retyping.
 *
 * Shoots keep two small fields: a per-shoot "hidden" switch, and a snapshot of
 * the notes that applied when the shoot was delivered, so history stays fixed
 * after later edits to the standing note.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('standing_editor_notes')) {
            Schema::create('standing_editor_notes', function (Blueprint $table) {
                $table->id();
                $table->string('scope', 16); // client | brokerage
                // One client-scope note per client; NULL for brokerage notes.
                $table->unsignedBigInteger('client_id')->nullable()->unique();
                $table->string('company_match', 120)->nullable();
                $table->text('content');
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->index(['scope', 'is_active'], 'standing_editor_notes_scope_active_index');
            });
        }

        Schema::table('shoots', function (Blueprint $table) {
            if (! Schema::hasColumn('shoots', 'standing_editor_notes_hidden')) {
                $table->boolean('standing_editor_notes_hidden')->default(false);
            }
            if (! Schema::hasColumn('shoots', 'standing_editor_notes_snapshot')) {
                $table->json('standing_editor_notes_snapshot')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            foreach (['standing_editor_notes_snapshot', 'standing_editor_notes_hidden'] as $column) {
                if (Schema::hasColumn('shoots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::dropIfExists('standing_editor_notes');
    }
};
