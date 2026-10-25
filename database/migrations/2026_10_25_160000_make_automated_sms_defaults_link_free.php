<?php

use App\Models\AutomationRule;
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

        $replacements = [];
        foreach (SmsTemplateContent::all() as $slug => $body) {
            if (! str_starts_with($slug, 'automation-') && ! in_array($slug, ['property-contact-reminder-sms', 'shoot-payment-reminder-sms'], true)) {
                continue;
            }
            $previous = $slug === 'automation-account-created-sms'
                ? 'R/E Pro Photos: Account ready. Check your email for setup. Sign in: {{portal_url}}'
                : str_replace(
                    ['R/E Pro Photos: ', 'Check your email or Dashboard for details and links.'],
                    ['', 'Details: {{dashboard_link}}'],
                    $body,
                );
            $replacements[$previous] = $body;

            // Exact, atomic match: preserve authored copy, disabled state and
            // concurrent edits. Email/manual templates are outside this scope.
            LockedWrite::run(fn () => MessageTemplate::where('channel', 'SMS')->where('scope', 'SYSTEM')
                ->where('slug', $slug)->where('body_text', $previous)
                ->where(fn ($q) => $q->whereNull('body_html')->orWhere('body_html', '')->orWhere('body_html', $previous))
                ->update(['body_text' => $body, 'body_html' => '', 'variables_json' => json_encode(SmsTemplateContent::variables($body), JSON_THROW_ON_ERROR)]));
        }

        if (! Schema::hasTable('automation_rules') || ! Schema::hasColumn('automation_rules', 'workflow_definition_json')) {
            return;
        }
        foreach (AutomationRule::where('scope', 'SYSTEM')->get() as $rule) {
            $workflow = $rule->workflow_definition_json;
            if (! is_array($workflow['nodes'] ?? null)) {
                continue;
            }
            $changed = false;
            foreach ($workflow['nodes'] as &$node) {
                if (($node['type'] ?? '') !== 'action.sms' || ! empty($node['config']['templateId']) || ! empty($node['config']['bodyHtml'])) {
                    continue;
                }
                $previous = $node['config']['bodyText'] ?? '';
                if (isset($replacements[$previous])) {
                    $node['config']['bodyText'] = $replacements[$previous];
                    $changed = true;
                }
            }
            unset($node);
            if ($changed) {
                LockedWrite::run(fn () => AutomationRule::whereKey($rule->id)
                    ->where('workflow_definition_json', $rule->getRawOriginal('workflow_definition_json'))
                    ->update(['workflow_definition_json' => json_encode($workflow, JSON_THROW_ON_ERROR)]));
            }
        }
    }

    public function down(): void
    {
        // Do not silently restore filtered links or overwrite subsequent edits.
        // Restoring links requires a reviewed follow-up after provider clearance.
    }
};
