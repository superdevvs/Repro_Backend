<?php

use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use App\Services\Messaging\ShootPaymentReminderTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('message_templates')) {
            return;
        }

        $shootTemplate = ShootPaymentReminderTemplate::installMissing();
        $invoiceTemplateId = MessageTemplate::query()
            ->where('slug', 'payment-due-reminder')
            ->where('channel', 'EMAIL')
            ->value('id');

        if (! $invoiceTemplateId || ! Schema::hasTable('automation_rules')) {
            return;
        }

        // The factory shoot reminder was pointed at the invoice template. Change
        // only that known stock selection; keep schedules, recipients, disabled
        // state, custom template choices and authored inline actions untouched.
        foreach (AutomationRule::query()
            ->where('scope', 'SYSTEM')
            ->where('trigger_type', 'SHOOT_PAYMENT_REMINDER')
            ->where('name', 'Shoot Payment Reminder')
            ->get() as $rule) {
            $workflow = $rule->workflow_definition_json;
            $hasEmailAction = false;
            $replacedStockAction = false;

            if (is_array($workflow) && is_array($workflow['nodes'] ?? null)) {
                foreach ($workflow['nodes'] as &$node) {
                    if (($node['type'] ?? null) !== 'action.email') {
                        continue;
                    }
                    $hasEmailAction = true;
                    $config = $node['config'] ?? [];
                    if (! is_array($config) || (int) ($config['templateId'] ?? 0) !== (int) $invoiceTemplateId
                        || ! empty($config['subject']) || ! empty($config['bodyHtml']) || ! empty($config['bodyText'])) {
                        continue;
                    }
                    $node['config']['templateId'] = $shootTemplate->id;
                    $replacedStockAction = true;
                }
                unset($node);
            }

            $stockLegacySelection = (int) $rule->template_id === (int) $invoiceTemplateId;
            if (! $replacedStockAction && (! $stockLegacySelection || $hasEmailAction)) {
                continue;
            }

            $changes = [];
            if ($replacedStockAction) {
                $changes['workflow_definition_json'] = $workflow;
            }
            if ($stockLegacySelection) {
                $changes['template_id'] = $shootTemplate->id;
            }
            $rule->update($changes);
        }
    }

    public function down(): void
    {
        // Reversing the selection could overwrite edits made after deployment.
    }
};
