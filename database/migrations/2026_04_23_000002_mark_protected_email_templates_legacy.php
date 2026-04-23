<?php

use App\Services\SystemEmails\ProtectedAutomationEmailMap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $map = app(ProtectedAutomationEmailMap::class);

        DB::table('automation_rules')
            ->whereIn('trigger_type', $map->protectedTriggers())
            ->update(['template_id' => null]);

        DB::table('message_templates')
            ->whereIn('slug', $map->legacyProtectedTemplateSlugs())
            ->get()
            ->each(function ($template): void {
                $description = trim((string) ($template->description ?? ''));
                $legacyNote = 'Legacy protected system template. Canonical HTML is now code-owned.';

                DB::table('message_templates')
                    ->where('id', $template->id)
                    ->update([
                        'category' => 'SYSTEM_LEGACY_PROTECTED',
                        'description' => str_contains($description, $legacyNote)
                            ? $description
                            : trim($legacyNote . ' ' . $description),
                    ]);
            });
    }

    public function down(): void
    {
        DB::table('message_templates')
            ->where('category', 'SYSTEM_LEGACY_PROTECTED')
            ->update(['category' => 'SYSTEM']);
    }
};
