<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('message_templates')
            || ! Schema::hasColumn('message_templates', 'email_type')
            || ! Schema::hasColumn('message_templates', 'override_enabled')) {
            return;
        }

        MessageTemplate::firstOrCreate(
            ['channel' => 'EMAIL', 'slug' => 'system-shoot-summary'],
            [
                'name' => 'Shoot Summary (automatic delivery)',
                'description' => 'Final delivery email sent when a completed shoot is paid in full. Keep the system body block to include current download and tour links.',
                'category' => 'GENERAL',
                'subject' => '{{system_subject}}',
                'body_html' => '{{system_body_html}}',
                'body_text' => '{{system_body_text}}',
                'variables_json' => ['system_subject', 'system_body_html', 'system_body_text', 'recipient_name'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
                'email_type' => 'SHOOT_SUMMARY',
                'override_enabled' => false,
            ]
        );
    }

    public function down(): void
    {
        // Keep template edits and delivery history made after deployment.
    }
};
