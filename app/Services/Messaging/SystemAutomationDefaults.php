<?php

namespace App\Services\Messaging;

use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use App\Models\SmsNumber;
use App\Services\SystemEmails\DirectEmailTemplates;

/** Defaults are installed once. Reads and subsequent seeds never reset edits. */
class SystemAutomationDefaults
{
    public function __construct(private readonly AutomationWorkflowConverter $converter) {}

    public function ensure(): void
    {
        $this->ensureTemplates();
        foreach ($this->additionalRules() as $attributes) {
            if (! $attributes['template_id']) {
                continue;
            }
            $rule = AutomationRule::firstOrCreate(
                ['scope' => 'SYSTEM', 'trigger_type' => $attributes['trigger_type']],
                $attributes + ['is_active' => true, 'is_system_locked' => false, 'editor_mode' => 'visual', 'engine_version' => 2],
            );
            if ($rule->wasRecentlyCreated) {
                $workflow = $this->converter->buildLegacyWorkflow($rule);
                if ($rule->trigger_type === 'SHOOT_PAYMENT_REMINDER' && SmsNumber::where('is_default', true)->exists()) {
                    $email = collect($workflow['nodes'])->firstWhere('type', 'action.email');
                    $end = collect($workflow['nodes'])->firstWhere('type', 'end');
                    $smsId = 'payment_sms_'.$rule->id;
                    $workflow['nodes'][] = [
                        'id' => $smsId, 'type' => 'action.sms', 'position' => ['x' => 600, 'y' => 140],
                        'config' => ['templateId' => MessageTemplate::where('slug', 'shoot-payment-reminder-sms')->value('id'), 'recipientMode' => 'automation_default', 'recipientRoles' => ['client']],
                    ];
                    $workflow['edges'] = array_values(array_filter($workflow['edges'], fn ($edge) => $edge['source'] !== $email['id']));
                    $workflow['edges'][] = ['id' => $email['id'].'_'.$smsId, 'source' => $email['id'], 'target' => $smsId];
                    $workflow['edges'][] = ['id' => $smsId.'_'.$end['id'], 'source' => $smsId, 'target' => $end['id']];
                }
                $this->saveWorkflow($rule, $this->withDefaultSms($rule, $workflow));
            }
        }
    }

    /** One-time upgrade of shipped rules, without replacing authored workflows. */
    public function repair(?array $ruleIds = null): void
    {
        $this->ensureTemplates();
        $slugs = [
            'ACCOUNT_CREATED' => 'account-created', 'SHOOT_BOOKED' => 'shoot-scheduled', 'SHOOT_SCHEDULED' => 'shoot-scheduled',
            'SHOOT_REMINDER' => 'shoot-reminder', 'PHOTOGRAPHER_SHOOT_REMINDER' => 'photographer-shoot-reminder',
            'SHOOT_REQUESTED' => 'shoot-requested', 'SHOOT_REQUEST_APPROVED' => 'shoot-request-approved',
            'SHOOT_REQUEST_MODIFIED' => 'shoot-request-modified', 'SHOOT_REQUEST_DECLINED' => 'shoot-request-declined',
            'SHOOT_UPDATED' => 'shoot-updated', 'SHOOT_COMPLETED' => 'shoot-ready',
            'SHOOT_CANCELED' => 'shoot-cancelled', 'SHOOT_CANCELLED' => 'shoot-cancelled', 'SHOOT_REMOVED' => 'shoot-deleted',
            'PAYMENT_COMPLETED' => 'payment-thank-you', 'PAYMENT_REFUNDED' => 'refund-submitted',
            'PHOTOGRAPHER_ASSIGNED' => 'photographer-assigned', 'PHOTOGRAPHER_CHANGED' => 'photographer-changed',
            'INVOICE_DUE' => 'payment-due-reminder', 'INVOICE_OVERDUE' => 'payment-due-reminder',
            'WEEKLY_AUTOMATED_INVOICING' => 'weekly-invoice-generated', 'WEEKLY_SALES_REPORT' => 'weekly-sales-report',
        ];
        foreach (AutomationRule::where('scope', 'SYSTEM')
            ->when($ruleIds !== null, fn ($query) => $query->whereIn('id', $ruleIds))->get() as $rule) {
            if (! isset($slugs[$rule->trigger_type]) && $rule->trigger_type !== 'PROPERTY_CONTACT_REMINDER') {
                continue;
            }
            $workflow = $rule->workflow_definition_json;
            if (($workflow['meta']['defaults_repaired_20260927'] ?? false) === true) {
                continue;
            }
            $schedule = $this->savedSchedule($rule, (array) $workflow);
            $roles = $rule->recipients_json ?? [];
            $oldRoles = $roles;
            if ($rule->trigger_type === 'ACCOUNT_CREATED' && $roles === ['client']) {
                $roles = ['account'];
            }
            $hasAction = collect($workflow['nodes'] ?? [])->contains(fn ($node) => str_starts_with($node['type'] ?? '', 'action.'));
            $repurposePhotographerOffset = false;
            if ($rule->name === 'Photographer Shoot Reminder'
                || ($rule->trigger_type === 'SHOOT_REMINDER' && $roles === ['photographer'] && ! $rule->template_id && ! $hasAction)) {
                $rule->trigger_type = 'PHOTOGRAPHER_SHOOT_REMINDER';
                if (! isset($schedule['offset']) || $schedule['offset'] === '-24h') {
                    $schedule['offset'] = '-2h';
                    $repurposePhotographerOffset = true;
                }
                $roles = ['photographer'];
            }
            if ($rule->trigger_type === 'PROPERTY_CONTACT_REMINDER') {
                $days = (int) ($schedule['days_before'] ?? $rule->condition_json['days_before'] ?? 2);
                $schedule += ['days_before' => $days, 'time' => '09:00'];
                $sms = $this->usesSms($rule, (array) $workflow);
                $slugs['PROPERTY_CONTACT_REMINDER'] = $sms ? 'property-contact-reminder-sms' : 'property-contact-reminder';
                if (! $sms && $roles === ['client'] && $days <= 1) {
                    $roles = $days === 0 ? ['client', 'admin', 'photographer'] : ['client', 'admin'];
                }
                if ($rule->name === 'Property Contact Reminder SMS - 2 Days Before') {
                    $rule->is_active = false;
                }
            }
            if ($rule->trigger_type === 'INVOICE_DUE') {
                $schedule += ['days_before' => 0, 'time' => '09:30'];
            }
            if ($rule->trigger_type === 'INVOICE_OVERDUE') {
                $schedule += ['overdue_days' => [1, 3, 7, 14, 30], 'repeat_every_days' => 30, 'time' => '09:30'];
            }
            if ($rule->name === 'Shoot Removed Notification' && $roles === ['client', 'photographer']) {
                $roles = ['admin'];
            }
            $rule->fill([
                'is_system_locked' => false, 'engine_version' => 2, 'editor_mode' => 'visual',
                'schedule_json' => $schedule, 'recipients_json' => $roles,
                'template_id' => $rule->template_id ?: MessageTemplate::where('slug', $slugs[$rule->trigger_type])->value('id'),
            ]);
            $rule->save();
            $hasAction = collect($workflow['nodes'] ?? [])->contains(fn ($node) => str_starts_with($node['type'] ?? '', 'action.'));
            if (! $workflow || ! $hasAction) {
                $workflow = $this->converter->buildLegacyWorkflow($rule);
            } else {
                foreach ($workflow['nodes'] as &$node) {
                    if (str_starts_with($node['type'] ?? '', 'trigger.')) {
                        $node['config']['triggerType'] = $rule->trigger_type;
                        $node['config']['schedule'] = $schedule;
                        unset($node['config']['command']);
                    }
                    if ($repurposePhotographerOffset && ($node['type'] ?? '') === 'wait.datetime_offset'
                        && ($node['config']['referenceField'] ?? null) === 'shoot_datetime'
                        && ($node['config']['direction'] ?? 'before') === 'before'
                        && ($node['config']['amount'] ?? 24) == 24 && ($node['config']['unit'] ?? 'hours') === 'hours') {
                        $node['config']['amount'] = 2;
                    }
                    if (str_starts_with($node['type'] ?? '', 'action.') && $roles !== $oldRoles
                        && in_array($node['config']['recipientMode'] ?? 'automation_default', ['automation_default', 'context'], true)
                        && in_array($node['config']['recipientRoles'] ?? [], [$oldRoles, []], true)) {
                        $node['config']['recipientRoles'] = $roles;
                    }
                    if (($node['type'] ?? '') === 'action.email' && empty($node['config']['templateId'])
                        && empty($node['config']['bodyHtml']) && empty($node['config']['bodyText']) && $rule->template_id) {
                        $node['config']['templateId'] = $rule->template_id;
                    }
                }
                unset($node);
            }
            // The old shipped cancellation rule pointed at the deletion template.
            // Correct only that exact factory configuration; authored copy stays selected.
            if ($rule->name === 'Shoot Cancelled Notification'
                && in_array($rule->trigger_type, ['SHOOT_CANCELED', 'SHOOT_CANCELLED'], true)
                && $this->isFactoryWorkflow($rule, $workflow)
                && $rule->template
                && app(\Database\Seeders\MessagingSystemSeeder::class)->isFactoryShootDeletedTemplate($rule->template)) {
                $previousTemplateId = $rule->template_id;
                $replacementId = MessageTemplate::where('slug', 'shoot-cancelled')->value('id');
                if ($replacementId) {
                    $rule->template_id = $replacementId;
                    $rule->unsetRelation('template');
                    foreach ($workflow['nodes'] as &$node) {
                        if (($node['type'] ?? '') === 'action.email' && ($node['config']['templateId'] ?? null) == $previousTemplateId) {
                            $node['config']['templateId'] = $replacementId;
                        }
                    }
                    unset($node);
                }
            }
            $workflow['meta']['defaults_repaired_20260927'] = true;
            $workflow['meta']['system_default_key'] ??= $rule->name;
            unset($workflow['meta']['system_command']);
            $this->saveWorkflow($rule, $this->isFactoryWorkflow($rule, $workflow)
                ? $this->withDefaultSms($rule, $workflow) : $workflow);
        }
        $this->ensure();
    }

    /** Keep authored visual timing when old columns were not synchronized by the editor. */
    private function savedSchedule(AutomationRule $rule, array $workflow): array
    {
        $legacy = (array) $rule->schedule_json;
        $trigger = collect($workflow['nodes'] ?? [])->first(fn ($node) => str_starts_with($node['type'] ?? '', 'trigger.'));
        $visual = is_array($trigger['config']['schedule'] ?? null) ? $trigger['config']['schedule'] : [];
        $schedule = array_replace($legacy, $visual);

        if (in_array($rule->trigger_type, ['SHOOT_REMINDER', 'PHOTOGRAPHER_SHOOT_REMINDER'], true)) {
            $wait = collect($workflow['nodes'] ?? [])->first(fn ($node) => ($node['type'] ?? '') === 'wait.datetime_offset'
                && ($node['config']['referenceField'] ?? null) === 'shoot_datetime');
            $config = $wait['config'] ?? [];
            $unit = ['minutes' => 'm', 'hours' => 'h', 'days' => 'd'][$config['unit'] ?? ''] ?? null;
            $amount = (int) ($config['amount'] ?? 0);
            if ($unit && $amount > 0 && in_array($config['direction'] ?? null, ['before', 'after'], true)) {
                $offset = ($config['direction'] === 'before' ? '-' : '+').$amount.$unit;
                // A changed legacy wait wins over its unchanged trigger mirror.
                // An explicitly changed trigger schedule remains authoritative.
                if (! isset($visual['offset'])
                    || ($visual['offset'] === ($legacy['offset'] ?? null) && $offset !== ($legacy['offset'] ?? null))) {
                    $schedule['offset'] = $offset;
                }
            }
        }

        return $schedule;
    }

    private function usesSms(AutomationRule $rule, array $workflow): bool
    {
        $actionTypes = collect($workflow['nodes'] ?? [])->pluck('type');
        if ($actionTypes->contains('action.sms')) {
            return true;
        }
        if ($actionTypes->contains('action.email')) {
            return false;
        }
        if ($rule->template) {
            return $rule->template->channel === 'SMS';
        }

        return str_contains($rule->name, 'SMS');
    }

    private function saveWorkflow(AutomationRule $rule, array $workflow): void
    {
        $rule->workflow_definition_json = $workflow;
        $rule->entry_trigger_json = $this->converter->getEntryTrigger($rule);
        $rule->save();
    }

    /** Layout edits do not change factory behavior, but authored actions and branches do. */
    private function isFactoryWorkflow(AutomationRule $rule, array $workflow): bool
    {
        $expected = $this->converter->buildLegacyWorkflow($rule);
        $nodes = fn ($definition) => collect($definition['nodes'] ?? [])
            ->map(fn ($node) => ['id' => $node['id'], 'type' => $node['type'], 'config' => $node['config'] ?? []])->all();
        $edges = fn ($definition) => collect($definition['edges'] ?? [])
            ->map(fn ($edge) => ['source' => $edge['source'], 'target' => $edge['target'], 'branchKey' => $edge['branchKey'] ?? null])->all();

        return $nodes($workflow) == $nodes($expected) && $edges($workflow) == $edges($expected);
    }

    private function withDefaultSms(AutomationRule $rule, array $workflow): array
    {
        $definition = $this->smsDefinitions()[$rule->trigger_type] ?? null;
        if (! $definition
            || collect($workflow['nodes'])->contains('type', 'action.sms')) {
            return $workflow;
        }
        $number = SmsNumber::where('is_default', true)->first();
        $end = collect($workflow['nodes'])->firstWhere('type', 'end');
        if (! $number || ! $end) {
            return $workflow;
        }
        // Add the optional operational text only to a single linear factory path.
        $incoming = collect($workflow['edges'])->filter(fn ($edge) => $edge['target'] === $end['id']);
        if ($incoming->count() !== 1) {
            return $workflow;
        }
        $email = collect($workflow['nodes'])->firstWhere('type', 'action.email');
        $roles = $email['config']['recipientRoles'] ?? $rule->recipients_json;
        $id = 'operational_sms_'.$rule->id;
        $workflow['nodes'][] = [
            'id' => $id, 'type' => 'action.sms', 'position' => ['x' => 700, 'y' => 140],
            'config' => ['smsNumberId' => $number->id, 'recipientMode' => 'roles', 'recipientRoles' => $roles,
                'templateId' => MessageTemplate::where('channel', 'SMS')->where('slug', $definition['slug'])->value('id')],
        ];
        foreach ($workflow['edges'] as &$edge) {
            if ($edge['id'] === $incoming->first()['id']) {
                $edge['target'] = $id;
            }
        }
        unset($edge);
        $workflow['edges'][] = ['id' => $id.'_'.$end['id'], 'source' => $id, 'target' => $end['id']];

        return $workflow;
    }

    private function smsDefinitions(): array
    {
        return SmsTemplateContent::automationDefinitions();
    }

    private function additionalRules(): array
    {
        return [
            [
                'name' => 'Shoot Payment Reminder', 'description' => 'Follow up on unpaid delivered shoots using the saved email and SMS actions.',
                'trigger_type' => 'SHOOT_PAYMENT_REMINDER', 'template_id' => MessageTemplate::where('slug', 'payment-due-reminder')->value('id'),
                'schedule_json' => ['reminder_days' => [1, 3, 7, 14, 21, 28], 'monthly_day_of_week' => 0, 'time' => '09:00'],
                'recipients_json' => ['client'],
            ],
            [
                'name' => 'Client Booking Confirmation', 'description' => 'Confirm an immediately scheduled booking to its client.',
                'trigger_type' => 'SHOOT_SCHEDULED', 'template_id' => MessageTemplate::where('slug', 'shoot-scheduled')->value('id'),
                'recipients_json' => ['client'],
            ],
            [
                'name' => 'Photographer Shoot Reminder', 'description' => 'Operational reminder before departure for an active assigned shoot.',
                'trigger_type' => 'PHOTOGRAPHER_SHOOT_REMINDER', 'template_id' => MessageTemplate::where('slug', 'photographer-shoot-reminder')->value('id'),
                'schedule_json' => ['offset' => '-2h'], 'recipients_json' => ['photographer'],
            ],
            [
                'name' => 'Weekly Automated Invoicing', 'description' => 'Generate weekly invoices and notify payees only when a nonzero invoice exists.',
                'trigger_type' => 'WEEKLY_AUTOMATED_INVOICING', 'template_id' => MessageTemplate::where('slug', 'weekly-invoice-generated')->value('id'),
                'schedule_json' => ['type' => 'weekly', 'day_of_week' => 1, 'time' => '01:00'],
                'recipients_json' => ['photographer', 'rep'],
            ],
            [
                'name' => 'Weekly Sales Reports', 'description' => 'Send the previous completed week KPIs to each sales rep with activity.',
                'trigger_type' => 'WEEKLY_SALES_REPORT', 'template_id' => MessageTemplate::where('slug', 'weekly-sales-report')->value('id'),
                'schedule_json' => ['type' => 'weekly', 'day_of_week' => 1, 'time' => '02:00'], 'recipients_json' => ['rep'],
            ],
            [
                'name' => 'Weekly Client Invoice Summary', 'description' => 'Summarize issued client invoices from the previous completed week for each client.',
                'trigger_type' => 'INVOICE_SUMMARY', 'template_id' => MessageTemplate::where('slug', 'weekly-client-invoice-summary')->value('id'),
                'schedule_json' => ['type' => 'weekly', 'day_of_week' => 1, 'time' => '03:00'], 'recipients_json' => ['client'],
            ],
            [
                'name' => 'Weekly Rep Invoice Summary', 'description' => 'Summarize the previous completed week of client invoices assigned to each sales rep.',
                'trigger_type' => 'WEEKLY_REP_INVOICE', 'template_id' => MessageTemplate::where('slug', 'weekly-rep-invoice-summary')->value('id'),
                'schedule_json' => ['type' => 'weekly', 'day_of_week' => 1, 'time' => '03:00'], 'recipients_json' => ['rep'],
            ],
            [
                'name' => 'Weekly Payout Reports', 'description' => 'Send each photographer, editor and sales rep their own previous-week payout recap.',
                'trigger_type' => 'WEEKLY_PAYOUT_REPORT', 'template_id' => MessageTemplate::where('slug', 'payout-report')->value('id'),
                'schedule_json' => ['type' => 'weekly', 'day_of_week' => 0, 'time' => '05:00'], 'recipients_json' => ['photographer', 'editor', 'rep'],
            ],
            [
                'name' => 'Weekly Accounting Payout Digest', 'description' => 'Send accounting the previous completed week payout totals when there is activity.',
                'trigger_type' => 'WEEKLY_PAYOUT_DIGEST', 'template_id' => MessageTemplate::where('slug', 'payout-digest')->value('id'),
                'schedule_json' => ['type' => 'weekly', 'day_of_week' => 0, 'time' => '05:00', 'accounting_email' => config('mail.accounting_address', 'accounting@reprophotos.com')],
                'recipients_json' => ['accounting'],
            ],
        ];
    }

    private function ensureTemplates(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('message_templates', 'email_type')
            && \Illuminate\Support\Facades\Schema::hasColumn('message_templates', 'override_enabled')) {
            DirectEmailTemplates::installMissing();
        }
        foreach ($this->smsDefinitions() as $trigger => $definition) {
            preg_match_all('/{{([a-z_]+)}}/', $definition['body'], $matches);
            MessageTemplate::firstOrCreate(['channel' => 'SMS', 'slug' => $definition['slug']], [
                'name' => ucwords(strtolower(str_replace('_', ' ', $trigger))).' SMS',
                'scope' => 'SYSTEM', 'is_system' => true, 'is_active' => true, 'category' => 'GENERAL',
                'subject' => '', 'body_text' => $definition['body'], 'variables_json' => array_values(array_unique($matches[1])),
            ]);
        }
        $templates = [
            ['photographer-shoot-reminder', 'Photographer Shoot Reminder', 'Shoot reminder: {{shoot_time}} at {{shoot_address}}',
                '<p>Your shoot starts {{shoot_date}} at {{shoot_time}}.</p><p>{{shoot_address}} <a href="{{map_link}}">View map</a></p><p>Contact: {{property_contact_name}} {{property_contact_phone}}</p><p>Access: {{access_instructions}}</p><p>Services: {{services_provided}}</p><p>Notes: {{shoot_notes}}</p><p><a href="{{dashboard_link}}">View shoot</a></p>'],
            ['weekly-sales-report', 'Weekly Sales Report', 'Sales report: {{report_period_start}} to {{report_period_end}}',
                '<p>{{report_period_start}} to {{report_period_end}}</p><p>Shoots: {{report_total_shoots}}. Completed: {{report_completed_shoots}} ({{report_completion_rate}}%).</p><p>Revenue: ${{report_total_revenue}}. Paid: ${{report_total_paid}}. Outstanding: ${{report_outstanding_balance}}.</p><p>Average shoot value: ${{report_average_shoot_value}}.</p><p><a href="{{portal_url}}">Open dashboard</a></p>'],
        ];
        foreach (['client' => 'Invoice summary', 'rep' => 'Client invoice summary'] as $role => $label) {
            $templates[] = ['weekly-'.$role.'-invoice-summary', 'Weekly '.ucfirst($role).' Invoice Summary', $label.': {{summary_start}} to {{summary_end}}',
                '<p>{{summary_invoice_count}} invoice(s) issued this week. Total: ${{summary_total_invoiced}}. Paid: ${{summary_total_paid}}. Outstanding: ${{summary_total_outstanding}}.</p>{{summary_invoices_html}}<p><a href="{{portal_url}}">Review invoices</a></p>'];
        }
        foreach ($templates as [$slug, $name, $subject, $body]) {
            preg_match_all('/{{([a-z_]+)}}/', $subject.$body, $matches);
            MessageTemplate::firstOrCreate(['slug' => $slug], [
                'name' => $name, 'channel' => 'EMAIL', 'scope' => 'SYSTEM', 'is_system' => true,
                'is_active' => true, 'category' => 'GENERAL', 'subject' => $subject, 'body_html' => $body,
                'body_text' => str_replace('{{summary_invoices_html}}', '{{summary_invoices_text}}', strip_tags(str_replace('</p>', "\n", $body))),
                'variables_json' => array_values(array_unique(array_merge($matches[1], str_contains($body, 'summary_invoices_html') ? ['summary_invoices_text'] : []))),
            ]);
        }
        MessageTemplate::firstOrCreate(['slug' => 'shoot-payment-reminder-sms'], [
            'name' => 'Shoot Payment Reminder SMS', 'channel' => 'SMS', 'scope' => 'SYSTEM', 'is_system' => true,
            'is_active' => true, 'category' => 'PAYMENT', 'subject' => '',
            'body_text' => SmsTemplateContent::forSlug('shoot-payment-reminder-sms'),
            'variables_json' => SmsTemplateContent::variables(SmsTemplateContent::forSlug('shoot-payment-reminder-sms')),
        ]);
        foreach (SmsTemplateContent::all() as $slug => $body) {
            MessageTemplate::firstOrCreate(['channel' => 'SMS', 'slug' => $slug], [
                'name' => ucwords(str_replace('-', ' ', $slug)), 'scope' => 'SYSTEM', 'is_system' => true,
                'is_active' => true, 'category' => 'GENERAL', 'subject' => '', 'body_text' => $body,
                'variables_json' => SmsTemplateContent::variables($body),
            ]);
        }
    }
}
