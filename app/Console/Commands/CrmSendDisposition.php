<?php

namespace App\Console\Commands;

use App\Models\Crm\CrmEmailEvent;
use App\Models\Crm\CrmEmailSend;
use Illuminate\Console\Command;

/**
 * Producer replay (P2-12): record the reviewed outcome of an uncertain or
 * queued CRM send, after checking the provider's own log. The send itself is
 * not changed; crm:outbound-release-check reads this record.
 */
class CrmSendDisposition extends Command
{
    public const DISPOSITIONS = ['delivered', 'not_delivered', 'resend_approved', 'abandoned'];

    protected $signature = 'crm:send-disposition
        {send : crm_email_sends id}
        {disposition : delivered, not_delivered, resend_approved or abandoned}
        {--note= : What the provider log showed}';

    protected $description = 'Record the reviewed disposition of an uncertain CRM send.';

    public function handle(): int
    {
        $disposition = (string) $this->argument('disposition');
        if (! in_array($disposition, self::DISPOSITIONS, true)) {
            $this->error('Disposition must be one of: '.implode(', ', self::DISPOSITIONS).'.');

            return self::INVALID;
        }
        $send = CrmEmailSend::query()->find((int) $this->argument('send'));
        if (! $send) {
            $this->error('No CRM send with that id.');

            return self::FAILURE;
        }

        CrmEmailEvent::query()->create([
            'send_id' => $send->id, 'type' => 'disposition', 'occurred_at' => now(),
            'payload' => ['disposition' => $disposition, 'status_at_review' => $send->status, 'note' => mb_substr((string) $this->option('note'), 0, 500)],
        ]);
        $this->line('Recorded '.$disposition.' for send '.$send->id.' (status '.$send->status.').');

        return self::SUCCESS;
    }
}
