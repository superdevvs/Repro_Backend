<?php

namespace App\Console\Commands;

use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use App\Models\SmsNumber;
use App\Services\Messaging\AutomationWorkflowValidator;
use App\Services\Messaging\SmsTemplateContent;
use App\Support\LockedWrite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ConfigureClientReminderSms extends Command
{
    protected $signature = 'messaging:configure-client-reminder-sms
        {rule : Existing SHOOT_REMINDER rule ID}
        {--sender= : Verified SMS sender ID}
        {--without-link : Use the separate plain-text template for a reviewed pilot}
        {--apply : Save the previewed change; does not send or replay messages}';

    protected $description = 'Preview or add a client SMS step to a saved appointment reminder workflow';

    public function handle(AutomationWorkflowValidator $validator): int
    {
        try {
            // Dry run uses the same locked read/validation as apply. No model is
            // written before the apply gate, including templates and timestamps.
            return LockedWrite::run(fn () => DB::transaction(function () use ($validator): int {
                $rule = AutomationRule::findOrFail($this->argument('rule'));
                if ($rule->trigger_type !== 'SHOOT_REMINDER') {
                    throw new RuntimeException('Select a SHOOT_REMINDER rule.');
                }
                $sender = SmsNumber::find($this->option('sender'));
                if (! $sender || $sender->provider !== 'TELNYX' || ! $sender->phone_number) {
                    throw new RuntimeException('Select an existing Telnyx sender with --sender. Confirm its verification separately.');
                }
                $workflow = $rule->workflow_definition_json;
                $nodes = collect($workflow['nodes'] ?? []);
                if ($nodes->contains('type', 'action.sms')) {
                    $this->warn('An SMS step already exists. Review it in the editor; no changes made.');

                    return self::SUCCESS;
                }
                $emails = $nodes->where('type', 'action.email');
                $ends = $nodes->where('type', 'end');
                if ($emails->count() !== 1 || $ends->count() !== 1
                    || $nodes->contains(fn ($node) => str_starts_with($node['type'] ?? '', 'condition.')
                        || (str_starts_with($node['type'] ?? '', 'action.') && $node['type'] !== 'action.email'))
                    || ! $validator->validate($workflow ?? [])['valid']) {
                    throw new RuntimeException('This workflow is customized or invalid. Add its client SMS step in the editor.');
                }
                $email = $emails->first();
                $end = $ends->first();
                $edges = collect($workflow['edges'] ?? []);
                $incoming = $edges->where('target', $end['id']);
                if ($incoming->count() !== 1 || $incoming->first()['source'] !== $email['id']
                    || $edges->where('source', $email['id'])->count() !== 1) {
                    throw new RuntimeException('The email must lead directly to one end node. Review this workflow in the editor.');
                }
                $withoutLink = (bool) $this->option('without-link');
                $slug = 'client-appointment-reminder'.($withoutLink ? '-plain' : '').'-sms';
                $template = MessageTemplate::where('slug', $slug)->where('channel', 'SMS')->first();
                $body = $template?->body_text ?? SmsTemplateContent::forSlug('automation-photographer-shoot-reminder-sms');
                $id = 'client_reminder_sms_'.$rule->id;
                if ($nodes->contains('id', $id)) {
                    throw new RuntimeException('The proposed step ID already exists. Review the workflow in the editor.');
                }
                $this->info('Rule '.$rule->id.': '.$rule->name.' ('.($rule->is_active ? 'active' : 'disabled; remains disabled').')');
                $this->line('Sender: '.$sender->phone_number.'; recipients: client only; saved reminder schedule unchanged.');
                $this->line('Template: '.$slug.($template && ! $template->is_active ? ' (disabled; remains disabled)' : ''));
                $this->line($body);
                if (! $this->option('apply')) {
                    $this->info('Preview only. No changes or sends. Use --apply after reviewing sender readiness and this preview.');

                    return self::SUCCESS;
                }
                $template ??= MessageTemplate::create([
                    'slug' => $slug, 'name' => 'Client Appointment Reminder'.($withoutLink ? ' (Plain Text)' : '').' SMS',
                    'channel' => 'SMS', 'scope' => 'SYSTEM', 'is_system' => true, 'is_active' => true,
                    'body_text' => $body, 'variables_json' => SmsTemplateContent::variables($body),
                ]);
                $workflow['nodes'][] = ['id' => $id, 'type' => 'action.sms', 'position' => ['x' => 700, 'y' => 280],
                    'config' => ['smsNumberId' => $sender->id, 'templateId' => $template->id,
                        'recipientMode' => 'roles', 'recipientRoles' => ['client'], 'clientAppointmentSchedule' => true]];
                foreach ($workflow['edges'] as &$edge) {
                    if ($edge['source'] === $email['id'] && $edge['target'] === $end['id']) {
                        $edge['target'] = $id;
                    }
                }
                unset($edge);
                $workflow['edges'][] = ['id' => $id.'_end', 'source' => $id, 'target' => $end['id']];
                if (! $validator->validate($workflow)['valid']) {
                    throw new RuntimeException('The updated workflow did not validate; no change was saved.');
                }
                $rule->update(['workflow_definition_json' => $workflow]);
                $this->info('Client SMS step saved. No messages sent or historical runs replayed.');

                return self::SUCCESS;
            }), 'sms.configure_client_reminders');
        } catch (\Throwable $exception) {
            $this->error($exception instanceof RuntimeException ? $exception->getMessage() : 'Unable to configure this rule. No change was saved.');

            return self::FAILURE;
        }
    }
}
