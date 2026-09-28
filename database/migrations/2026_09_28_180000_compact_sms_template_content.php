<?php

use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use App\Services\Messaging\SmsTemplateContent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('message_templates')) {
            return;
        }

        // Match previous shipped copy exactly: templates are editable operational
        // data. Do not replace authored messages or reactivate disabled templates.
        $previous = [
            'ACCOUNT_CREATED' => 'Welcome to R/E Pro Photos, {{recipient_first_name}}. Your account is ready. Sign in: {{portal_url}}',
            'PHOTOGRAPHER_SHOOT_REMINDER' => 'Shoot {{shoot_date}} {{shoot_time}}: {{shoot_address}}. Map: {{map_link}}. Contact: {{property_contact_name}} {{property_contact_phone}}. Access: {{access_instructions}}. Services: {{services_provided}}. Details: {{dashboard_link}}',
            'SHOOT_SCHEDULED' => 'Booking confirmed: {{shoot_date}} {{shoot_time}}, {{shoot_address}}. Review or manage: {{dashboard_link}}',
            'SHOOT_BOOKED' => 'Shoot assigned: {{shoot_date}} {{shoot_time}}, {{shoot_address}}. Services: {{services_provided}}. Details: {{dashboard_link}}',
            'PHOTOGRAPHER_ASSIGNED' => 'Shoot assigned: {{shoot_date}} {{shoot_time}}, {{shoot_address}}. Services: {{services_provided}}. Details: {{dashboard_link}}',
            'SHOOT_REQUEST_APPROVED' => 'Booking confirmed: {{shoot_date}} {{shoot_time}}, {{shoot_address}}. Review or manage: {{dashboard_link}}',
            'SHOOT_REQUEST_MODIFIED' => 'Booking updated: {{shoot_date}} {{shoot_time}}, {{shoot_address}}. {{shoot_change_summary}} Review: {{dashboard_link}}',
            'SHOOT_UPDATED' => 'Shoot updated at {{shoot_address}}: {{shoot_change_summary}}. Schedule: {{shoot_date}} {{shoot_time}}. Review: {{dashboard_link}}',
            'PHOTOGRAPHER_CHANGED' => '{{assignment_message}} Shoot: {{shoot_date}} {{shoot_time}}, {{shoot_address}}. Details: {{dashboard_link}}',
            'SHOOT_COMPLETED' => 'Your media is ready for {{shoot_address}}. Balance: {{remaining_balance}}. View gallery and downloads: {{dashboard_link}}',
            'SHOOT_CANCELED' => 'Shoot cancelled: {{shoot_date}} {{shoot_time}}, {{shoot_address}}. {{cancellation_reason}} Details: {{dashboard_link}}',
            'SHOOT_CANCELLED' => 'Shoot cancelled: {{shoot_date}} {{shoot_time}}, {{shoot_address}}. {{cancellation_reason}} Details: {{dashboard_link}}',
        ];
        $replacements = [];
        foreach ($previous as $trigger => $body) {
            $replacements['automation-'.strtolower(str_replace('_', '-', $trigger)).'-sms'] = [$body];
        }
        $property = 'REPRO: Action required for shoot at {{shoot_location}} on {{shoot_date}} at {{shoot_time}}. Please provide property access details (who will be at property or lockbox info). Update: {{portal_url}}';
        $replacements['property-contact-reminder-sms'] = [
            $property,
            $property."\n\n{{access_warning}} Update: {{dashboard_link}}",
            str_replace(['{{', '}}'], ['[', ']'], $property),
        ];
        $replacements['shoot-payment-reminder-sms'] = [
            'Payment reminder for {{shoot_location}}. Balance: {{remaining_balance}}. Pay or review: {{payment_link}}',
        ];

        foreach ($replacements as $slug => $bodies) {
            $body = SmsTemplateContent::forSlug($slug);
            foreach (MessageTemplate::where('channel', 'SMS')->where('scope', 'SYSTEM')->where('slug', $slug)->get() as $template) {
                if (! in_array($template->body_text, $bodies, true)) {
                    continue;
                }
                $attributes = ['body_text' => $body, 'variables_json' => SmsTemplateContent::variables($body)];
                if (in_array($template->body_html, $bodies, true)) {
                    $attributes['body_html'] = '';
                }
                $template->update($attributes);
            }
        }

        if (! Schema::hasTable('automation_rules') || ! Schema::hasColumn('automation_rules', 'workflow_definition_json')) {
            return;
        }
        // Some installations saved the factory text inline in the visual editor.
        // Change only that text; preserve recipients, timing, branches and state.
        foreach (AutomationRule::where('scope', 'SYSTEM')->get() as $rule) {
            $workflow = $rule->workflow_definition_json;
            if (! is_array($workflow) || ! is_array($workflow['nodes'] ?? null)) {
                continue;
            }
            $changed = false;
            foreach ($workflow['nodes'] as &$node) {
                if (($node['type'] ?? '') !== 'action.sms' || ! empty($node['config']['templateId'])) {
                    continue;
                }
                foreach ($replacements as $slug => $bodies) {
                    if (in_array($node['config']['bodyText'] ?? null, $bodies, true)) {
                        $node['config']['bodyText'] = SmsTemplateContent::forSlug($slug);
                        $changed = true;
                        break;
                    }
                }
            }
            unset($node);
            if ($changed) {
                $rule->update(['workflow_definition_json' => $workflow]);
            }
        }
    }

    public function down(): void
    {
        // Restoring verbose copy would overwrite subsequent operator edits.
    }
};
