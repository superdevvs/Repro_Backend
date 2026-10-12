<?php

use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use App\Services\Messaging\AutomationWorkflowConverter;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        $converter = app(AutomationWorkflowConverter::class);
        $firstSunday = Carbon::now('America/New_York')->startOfWeek(Carbon::SUNDAY)->setTime(10, 0);
        if ($firstSunday->lte(Carbon::now('America/New_York'))) $firstSunday->addWeek();
        $rules = AutomationRule::where('scope', 'SYSTEM')->whereIn('trigger_type', ['WEEKLY_AUTOMATED_INVOICING','WEEKLY_PAYOUT_REPORT'])->get();
        foreach ($rules as $rule) {
            $workflow = $converter->getWorkflowDefinition($rule);
            if ($workflow['meta']['weekly_invoice_sunday_20261012'] ?? false) continue;
            $configured = (array) $rule->recipients_json;
            $roles = array_is_list($configured) ? $configured : ($configured['roles'] ?? []);
            if (! in_array('photographer', $roles, true)) continue;
            // Update the shipped invoice rule only. Authored separate rules remain editable.
            if ($rule->trigger_type === 'WEEKLY_AUTOMATED_INVOICING'
                && $rule->name !== 'Weekly Automated Invoicing'
                && ($workflow['meta']['system_default_key'] ?? '') !== 'Weekly Automated Invoicing') continue;
            $workflow['meta']['before_weekly_invoice_sunday_20261012'] = [
                'schedule'=>$rule->schedule_json, 'recipients'=>$rule->recipients_json,
                'conditions'=>$rule->condition_json, 'description'=>$rule->description,
            ];
            if ($rule->trigger_type === 'WEEKLY_AUTOMATED_INVOICING') {
                $schedule = array_merge((array)$rule->schedule_json, [
                    'type'=>'weekly', 'day_of_week'=>0, 'time'=>'10:00', 'timezone'=>'America/New_York',
                    'delivery_mode'=>'recipient_week', 'effective_from'=>$firstSunday->toDateString(),
                ]);
                $description = 'One weekly photographer invoice email, Sunday at 10 AM America/New_York. Includes payout lines and pending exclusions; approval and payment remain separate.';
                $conditions = array_merge((array)$rule->condition_json, ['day'=>'sunday','time'=>'10:00']);
            } else {
                $roles = array_values(array_diff($roles, ['photographer']));
                $configured = array_is_list($configured) ? $roles : array_merge($configured, ['roles'=>$roles]);
                $schedule = $rule->schedule_json;
                $description = 'Weekly payout recaps for editors and sales reps. Photographers receive their single weekly invoice email.';
                $conditions = $rule->condition_json;
            }
            foreach ($workflow['nodes'] as &$node) {
                if (($node['type'] ?? '') === 'trigger.schedule') $node['config']['schedule'] = $schedule;
                if ($rule->trigger_type === 'WEEKLY_PAYOUT_REPORT' && str_starts_with($node['type'] ?? '', 'action.')) {
                    $node['config']['recipientRoles'] = array_values(array_diff($node['config']['recipientRoles'] ?? $roles, ['photographer']));
                }
            }
            unset($node);
            $workflow['meta']['weekly_invoice_sunday_20261012'] = true;
            $workflow['meta']['legacy_summary']['schedule_json'] = $schedule;
            $workflow['meta']['legacy_summary']['recipients_json'] = $configured;
            $rule->forceFill(['schedule_json'=>$schedule,'recipients_json'=>$configured,'condition_json'=>$conditions,
                'description'=>$description,'workflow_definition_json'=>$workflow])->save();
            $rule->update(['entry_trigger_json'=>$converter->getEntryTrigger($rule)]);
        }
        // Preserve the existing invoice design and authored copy, adding only
        // the reconciliation block. Do not send or regenerate any invoice here.
        foreach (MessageTemplate::where('slug','weekly-invoice-generated')->where('channel','EMAIL')->get() as $template) {
            if (! str_contains((string)$template->body_html, '{{invoice_reconciliation_html}}')) {
                $template->body_html = str_contains((string)$template->body_html, '<p>{{approval_note}}</p>')
                    ? str_replace('<p>{{approval_note}}</p>', '{{invoice_reconciliation_html}}<p>{{approval_note}}</p>', $template->body_html)
                    : $template->body_html.'{{invoice_reconciliation_html}}';
            }
            if (! str_contains((string)$template->body_text, '{{invoice_reconciliation_text}}')) $template->body_text .= "\n\n{{invoice_reconciliation_text}}";
            $template->variables_json = array_values(array_unique(array_merge($template->variables_json ?? [], ['invoice_reconciliation_html','invoice_reconciliation_text'])));
            $template->save();
        }
    }

    public function down(): void
    {
        // Keep operator settings and historical delivery records on rollback.
    }
};
