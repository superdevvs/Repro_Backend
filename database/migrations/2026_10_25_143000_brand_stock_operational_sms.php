<?php

use App\Models\MessageTemplate;
use App\Services\Messaging\SmsTemplateContent;
use App\Support\LockedWrite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasTable('message_templates')) {
            return;
        }
        foreach (SmsTemplateContent::all() as $slug => $body) {
            $prefix = 'R/E Pro Photos: ';
            $suffix = "\nReply STOP to opt out.";
            if (! str_starts_with($body, $prefix) || ! str_ends_with($body, $suffix)) {
                continue;
            }
            $withoutOptOut = substr($body, 0, -strlen($suffix));
            $previousBodies = [$withoutOptOut, substr($withoutOptOut, strlen($prefix))];
            foreach ($previousBodies as $previous) {
                // Compare the saved stock copy atomically and preserve authored HTML.
                LockedWrite::run(fn () => MessageTemplate::where('channel', 'SMS')->where('scope', 'SYSTEM')->where('slug', $slug)
                    ->where('body_text', $previous)
                    ->where(fn ($query) => $query->whereNull('body_html')->orWhere('body_html', '')->orWhere('body_html', $previous))
                    ->update(['body_text' => $body, 'body_html' => '']), 'sms.brand_stock_template');
            }
        }
    }

    public function down(): void
    {
        // Do not overwrite subsequent operator edits or remove sender identity.
    }
};
