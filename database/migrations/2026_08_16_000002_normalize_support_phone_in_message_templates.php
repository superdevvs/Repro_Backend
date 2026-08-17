<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PHONE_DISPLAY = '(202) 868-1663';

    private const PHONE_E164 = '+12028681663';

    public function up(): void
    {
        if (! Schema::hasTable('message_templates') || ! Schema::hasColumn('message_templates', 'id')) {
            return;
        }

        $contentColumns = array_values(array_filter(
            ['subject', 'body_html', 'body_text', 'description'],
            fn (string $column): bool => Schema::hasColumn('message_templates', $column)
        ));

        if ($contentColumns === []) {
            return;
        }

        DB::table('message_templates')
            ->select(array_merge(['id'], $contentColumns))
            ->orderBy('id')
            ->chunkById(100, function ($templates) use ($contentColumns): void {
                foreach ($templates as $template) {
                    $updates = [];

                    foreach ($contentColumns as $column) {
                        $original = $template->{$column};
                        if (! is_string($original) || $original === '') {
                            continue;
                        }

                        $normalized = $this->normalizeSupportPhoneReferences($original);
                        if ($normalized !== $original) {
                            $updates[$column] = $normalized;
                        }
                    }

                    if ($updates !== []) {
                        DB::table('message_templates')
                            ->where('id', $template->id)
                            ->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        // Intentionally irreversible: restoring an obsolete support number in
        // customer-facing templates would be unsafe and user edits are retained.
    }

    private function normalizeSupportPhoneReferences(string $content): string
    {
        $normalized = preg_replace(
            '/(?<!\d)(?:\+?1[\s.\-]*)?\(?202\)?[\s.\-]*868[\s.\-]*(?:1113|1663)(?!\d)/',
            self::PHONE_DISPLAY,
            $content
        ) ?? $content;

        return preg_replace(
            '/tel:\s*\(202\)\s*868-1663/i',
            'tel:'.self::PHONE_E164,
            $normalized
        ) ?? $normalized;
    }
};
