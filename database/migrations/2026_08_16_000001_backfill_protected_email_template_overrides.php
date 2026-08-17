<?php

use App\Services\SystemEmails\ProtectedAutomationEmailMap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('message_templates')) {
            return;
        }

        foreach (['slug', 'is_system', 'email_type', 'override_enabled', 'updated_by'] as $column) {
            if (! Schema::hasColumn('message_templates', $column)) {
                return;
            }
        }

        $slugToAlias = app(ProtectedAutomationEmailMap::class)
            ->canonicalTemplateSlugToAlias();

        foreach ($slugToAlias as $slug => $alias) {
            $template = DB::table('message_templates')
                ->where('slug', $slug)
                ->where('is_system', true)
                ->first(['id', 'email_type', 'override_enabled', 'updated_by']);

            if ($template === null) {
                continue;
            }

            $currentAlias = trim((string) ($template->email_type ?? ''));

            // Template edit routes are admin-only and stamp updated_by. This
            // activates only templates that were deliberately edited. An
            // untouched default stays completely unmapped so the editor can
            // distinguish it from an explicit saved opt-out. Existing mappings
            // are also left alone, including an admin's disabled override.
            if ($template->updated_by === null || $currentAlias !== '') {
                continue;
            }

            DB::table('message_templates')
                ->where('id', $template->id)
                ->update([
                    'email_type' => $alias,
                    'override_enabled' => true,
                ]);
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive. An admin may have edited or changed an
        // override after this migration ran, so rollback must preserve it.
    }
};
