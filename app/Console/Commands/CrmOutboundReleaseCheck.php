<?php

namespace App\Console\Commands;

use App\Services\Crm\CrmNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Producer replay (P2-12), read-only. Before CRM_OUTBOUND_HOLD is released,
 * count what the hold may have left unreconciled since the freeze began.
 * Exit 0 only when every blocking count is zero; exit 1 otherwise.
 */
class CrmOutboundReleaseCheck extends Command
{
    protected $signature = 'crm:outbound-release-check
        {--since= : When the freeze or outbound hold began (ISO 8601)}
        {--json : Print the report as JSON}';

    protected $description = 'Read-only: count replies, bounces, opt-outs and uncertain sends to reconcile before releasing the CRM outbound hold.';

    public function handle(): int
    {
        $since = null;
        if ($this->option('since')) {
            try {
                $since = Carbon::parse((string) $this->option('since'))->setTimezone(config('app.timezone'));
            } catch (\Throwable) {
                $since = null;
            }
        }
        if (! $since) {
            $this->error('Give --since with the time the freeze or hold began (ISO 8601).');

            return 2;
        }

        $blocking = [
            'replies_not_forwarded' => $this->repliesNotForwarded($since),
            'permanent_bounces_or_complaints_not_suppressed' => $this->bouncesNotSuppressed($since),
            'uncertain_sends_without_disposition' => DB::table('crm_email_sends')->whereIn('status', ['uncertain', 'queued'])
                ->whereNotIn('id', DB::table('crm_email_events')->select('send_id')->where('type', 'disposition'))->count(),
            'due_enrolments_that_should_stop' => 0,
        ];
        $due = DB::table('crm_drip_enrollments')->where('status', 'active')->where('next_send_at', '<=', now())->get(['id', 'contact_id']);
        foreach ($due as $enrolment) {
            if ($this->shouldStop((int) $enrolment->contact_id, $since)) {
                $blocking['due_enrolments_that_should_stop']++;
            }
        }
        $report = [
            'since' => $since->toIso8601String(),
            'outbound_hold' => (bool) config('crm.outbound_hold'),
            'defer_reply_forwarding' => (bool) config('crm.email.defer_reply_forwarding'),
            'blocking' => $blocking,
            'info' => [
                'due_enrolments' => $due->count(),
                'replies_since' => DB::table('crm_email_events')->where('type', 'replied')->where('created_at', '>=', $since)->count(),
                'opt_outs_since' => DB::table('email_suppressions')->where('reason', 'unsubscribe')->where('updated_at', '>=', $since)->count(),
                'scheduled_campaigns_due' => DB::table('crm_campaigns')->where('status', 'scheduled')->where('scheduled_at', '<=', now())->count(),
            ],
            'ready' => array_sum($blocking) === 0,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ([...$report['blocking'], ...$report['info']] as $name => $count) {
                $this->line(str_pad($name, 48).$count);
            }
            $this->line($report['ready'] ? 'Ready to release the outbound hold.' : 'Not ready: reconcile the blocking counts first.');
        }

        return $report['ready'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * A reply is settled when Resend accepted its forward to the rep, or a person
     * recorded a disposition with crm:send-disposition: on the forward send, or on
     * the replied-to send when no forward send exists (no rep, or no address).
     * A forward that failed, was blocked, only logged or is still queued is not.
     */
    private function repliesNotForwarded(Carbon $since): int
    {
        return DB::table('crm_email_events')->where('type', 'replied')->where('created_at', '>=', $since)->get(['send_id', 'payload', 'created_at'])
            ->reject(function ($reply) {
                // The key CrmInboundReply::forward() gives the forward; the log transport hashes it.
                $key = 'crm-reply-fwd-'.$reply->send_id.'-'.md5((string) ((json_decode((string) $reply->payload, true) ?: [])['email_id'] ?? ''));
                $forward = DB::table('crm_email_sends')->where('source_type', 'reply_fwd')
                    ->whereIn('idempotency_key', [$key, 'preview-'.hash('sha256', $key)])->first(['id', 'status']);

                return $forward
                    ? $forward->status === 'sent' || $this->reviewed((int) $forward->id)
                    : $this->reviewed((int) $reply->send_id, (string) $reply->created_at);
            })->count();
    }

    private function reviewed(int $sendId, ?string $after = null): bool
    {
        return DB::table('crm_email_events')->where('send_id', $sendId)->where('type', 'disposition')
            ->when($after, fn ($q) => $q->where('created_at', '>=', $after))->exists();
    }

    private function bouncesNotSuppressed(Carbon $since): int
    {
        $events = DB::table('crm_email_events as e')->join('crm_email_sends as s', 's.id', '=', 'e.send_id')
            ->whereIn('e.type', ['bounced', 'complained'])->where('e.created_at', '>=', $since)
            ->get(['e.type', 'e.payload', 's.to_email']);

        return $events->filter(function ($event) {
            $bounce = (json_decode((string) $event->payload, true) ?: [])['bounce'] ?? null;
            if ($event->type === 'bounced' && strtolower((string) ($bounce['type'] ?? '')) !== 'permanent') {
                return false; // soft bounces never suppress
            }
            $email = CrmNormalizer::email($event->to_email);

            return $email && ! DB::table('email_suppressions')->where('email_normalized', $email)->exists();
        })->count();
    }

    /** A due drip step must not go out to someone who replied, bounced or opted out. */
    private function shouldStop(int $contactId, Carbon $since): bool
    {
        $contact = DB::table('crm_contacts')->where('id', $contactId)->first(['email_normalized', 'email_opt_out_at', 'email_status']);
        if (! $contact) {
            return false;
        }

        return $contact->email_opt_out_at !== null
            || in_array($contact->email_status, ['bounced', 'complained'], true)
            || ($contact->email_normalized && DB::table('email_suppressions')->where('email_normalized', $contact->email_normalized)->exists())
            || DB::table('crm_email_sends')->where('contact_id', $contactId)->where('replied_at', '>=', $since)->exists();
    }
}
