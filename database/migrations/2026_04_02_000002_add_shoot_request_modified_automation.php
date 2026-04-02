<?php

use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $modifiedTemplate = MessageTemplate::query()->where('slug', 'shoot-request-modified')->first();
        $approvedTemplate = MessageTemplate::query()->where('slug', 'shoot-request-approved')->first();

        if (!$modifiedTemplate && $approvedTemplate) {
            $modifiedTemplate = MessageTemplate::query()->create([
                'channel' => $approvedTemplate->channel,
                'name' => 'Shoot Scheduled (Request Verified/Modified Approved)',
                'slug' => 'shoot-request-modified',
                'description' => 'Request approved with modifications',
                'category' => $approvedTemplate->category,
                'subject' => $approvedTemplate->subject,
                'body_html' => $approvedTemplate->body_html,
                'body_text' => $approvedTemplate->body_text,
                'variables_json' => $approvedTemplate->variables_json,
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ]);
        }

        AutomationRule::query()->updateOrCreate(
            [
                'trigger_type' => 'SHOOT_REQUEST_MODIFIED',
                'name' => 'Shoot Request Modified',
            ],
            [
                'description' => 'Notify client when a shoot request is approved with modifications',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'recipients_json' => ['client'],
                'template_id' => $modifiedTemplate?->id,
            ]
        );
    }

    public function down(): void
    {
        AutomationRule::query()
            ->where('trigger_type', 'SHOOT_REQUEST_MODIFIED')
            ->where('name', 'Shoot Request Modified')
            ->delete();
    }
};
