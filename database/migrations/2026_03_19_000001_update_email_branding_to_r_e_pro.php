<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('message_templates')) {
            return;
        }

        DB::table('message_templates')
            ->where('channel', 'EMAIL')
            ->orderBy('id')
            ->get()
            ->each(function ($template) {
                DB::table('message_templates')
                    ->where('id', $template->id)
                    ->update([
                        'subject' => $this->replaceBranding($template->subject),
                        'description' => $this->replaceBranding($template->description),
                        'body_html' => $this->replaceBranding($template->body_html),
                        'body_text' => $this->replaceBranding($template->body_text),
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        if (!Schema::hasTable('message_templates')) {
            return;
        }

        DB::table('message_templates')
            ->where('channel', 'EMAIL')
            ->orderBy('id')
            ->get()
            ->each(function ($template) {
                DB::table('message_templates')
                    ->where('id', $template->id)
                    ->update([
                        'subject' => $this->restoreBranding($template->subject),
                        'description' => $this->restoreBranding($template->description),
                        'body_html' => $this->restoreBranding($template->body_html),
                        'body_text' => $this->restoreBranding($template->body_text),
                        'updated_at' => now(),
                    ]);
            });
    }

    private function replaceBranding(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return str_replace(
            ['RE Pro', 'REPro Photos', 'REPRO Photos'],
            ['R/E Pro', 'R/E Pro Photos', 'R/E Pro Photos'],
            $value
        );
    }

    private function restoreBranding(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return str_replace(
            ['R/E Pro Photos', 'R/E Pro'],
            ['REPRO Photos', 'RE Pro'],
            $value
        );
    }
};
