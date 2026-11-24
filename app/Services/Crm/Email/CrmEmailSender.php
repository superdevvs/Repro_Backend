<?php

namespace App\Services\Crm\Email;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEmailSend;
use App\Models\Crm\CrmEmailTemplateVersion;
use App\Models\User;
use App\Services\Crm\CrmActivityLogger;
use App\Services\Crm\Email\Transport\CrmMailTransport;
use App\Services\Crm\Email\Transport\LogCrmTransport;
use App\Services\Messaging\OutboundDeliveryGuard;
use Illuminate\Support\Str;

/**
 * The single way email leaves the CRM: one-off emails, drips, campaigns,
 * recruiting outreach and digests.
 *
 * Before sending it checks the sender's approved signature, the do-not-email
 * list, the contact's opt-out and bounce state and the daily cap. It then
 * renders the locked branded layout, records the exact content, and hands the
 * email to the configured transport. Until real delivery is switched on the
 * transport only records it.
 */
class CrmEmailSender
{
    public function __construct(
        private readonly CrmEmailRenderer $renderer,
        private readonly CrmSignatureService $signatures,
        private readonly CrmSuppression $suppression,
        private readonly CrmActivityLogger $activity,
    ) {}

    public function transport(): CrmMailTransport
    {
        if (app()->bound(CrmMailTransport::class)) {
            return app(CrmMailTransport::class);
        }

        return match (config('crm.email.transport')) {
            'resend' => app(\App\Services\Crm\Email\Transport\ResendCrmTransport::class),
            default => app(LogCrmTransport::class),
        };
    }

    /**
     * @param  array{
     *   contact?: ?CrmContact, to_email?: ?string, applicant_id?: ?int, sender?: ?User,
     *   source_type: string, source_id?: ?int, template_version?: ?CrmEmailTemplateVersion,
     *   content?: array, idempotency_key?: ?string, marketing?: bool, counts_as_contact?: bool,
     *   via_robbie?: bool, vars?: array
     * }  $opts
     */
    public function send(array $opts): CrmEmailSend
    {
        $contact = $opts['contact'] ?? null;
        $sender = $opts['sender'] ?? null;
        $marketing = $opts['marketing'] ?? true;
        $to = trim((string) ($opts['to_email'] ?? $contact?->email ?? ''));

        if ($key = $opts['idempotency_key'] ?? null) {
            if ($existing = CrmEmailSend::query()->where('idempotency_key', $key)->first()) {
                return $existing;
            }
        }

        $this->assertSendable($to, $contact, $sender, $marketing);

        $signature = $sender ? $this->signatures->currentApproved($sender->id) : null;
        $version = $opts['template_version'] ?? null;
        $content = $opts['content'] ?? ($version ? [
            'subject' => $version->subject, 'body_html' => $version->body_html, 'preheader' => $version->preheader,
            'image_url' => $version->image_url, 'cta_label' => $version->cta_label, 'cta_url' => $version->cta_url,
        ] : null);
        if (! $content) {
            throw new CrmSendBlocked('no_content', 'There is nothing to send.');
        }

        $send = CrmEmailSend::create([
            'contact_id' => $contact?->id,
            'applicant_id' => $opts['applicant_id'] ?? null,
            'sender_user_id' => $sender?->id,
            'source_type' => $opts['source_type'],
            'source_id' => $opts['source_id'] ?? null,
            'template_version_id' => $version?->id,
            'signature_id' => $signature?->id,
            'from_email' => $signature?->sender_email ?? (string) config('crm.email.system_from', 'crm@reprophotos.com'),
            'from_name' => $signature ? ($signature->fields['name'] ?? $sender?->name) : 'R/E Pro Photos',
            'to_email' => $to,
            'subject' => '',
            'html' => '',
            'text' => '',
            'transport' => $this->transport()->name(),
            'status' => 'queued',
            'idempotency_key' => $opts['idempotency_key'] ?? null,
            'reply_token' => Str::lower(Str::random(24)),
        ]);

        $unsubscribe = $marketing ? $this->suppression->url($to, $send->id) : null;
        $vars = $this->renderer->varsFor($contact, $sender, $opts['vars'] ?? []);
        $rendered = $this->renderer->render($content, $vars, $this->renderer->signatureHtml($signature), $unsubscribe, $marketing);

        $replyTo = config('crm.email.reply_tracking') && $signature
            ? 'r+'.$send->reply_token.'@'.config('crm.email.reply_domain')
            : ($signature?->sender_email ?? null);
        $headers = [];
        if ($unsubscribe) {
            $oneClick = rtrim((string) config('app.url'), '/').'/api/public/crm/unsubscribe/'.$this->suppression->token($to, $send->id).'/one-click';
            $headers['List-Unsubscribe'] = '<'.$oneClick.'>';
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        $send->forceFill(['subject' => $rendered['subject'], 'html' => $rendered['html'], 'text' => $rendered['text'], 'reply_to' => $replyTo])->save();

        $transport = $this->transport();
        if ($transport->name() !== 'log' && ! app(OutboundDeliveryGuard::class)->allows('email', $to)) {
            $result = ['status' => 'blocked', 'provider_message_id' => null, 'error' => 'Delivery is not allowed from this environment.'];
        } else {
            try {
                $result = $transport->send([
                    'from_email' => $send->from_email, 'from_name' => $send->from_name, 'reply_to' => $replyTo, 'to' => $to,
                    'subject' => $rendered['subject'], 'html' => $rendered['html'], 'text' => $rendered['text'],
                    'headers' => $headers, 'idempotency_key' => $send->idempotency_key ?? 'crm-send-'.$send->id,
                ]);
            } catch (\Throwable $e) {
                report($e);
                $result = ['status' => 'failed', 'provider_message_id' => null, 'error' => mb_substr($e->getMessage(), 0, 400)];
            }
        }

        $send->forceFill([
            'status' => $result['status'],
            'provider_message_id' => $result['provider_message_id'],
            'error' => $result['error'],
            'sent_at' => in_array($result['status'], ['sent', 'logged'], true) ? now() : null,
        ])->save();

        if ($contact && in_array($result['status'], ['sent', 'logged'], true)) {
            $label = match ($opts['source_type']) {
                'drip' => 'Drip email sent',
                'campaign' => 'Campaign email sent',
                'recruiting' => 'Recruiting email sent',
                default => 'Email sent',
            };
            $this->activity->log($contact, 'email_sent', $sender, $label.' · '.$rendered['subject'], [
                'send_id' => $send->id, 'from' => $send->from_email, 'source_type' => $opts['source_type'], 'delivery' => $result['status'],
            ], (bool) ($opts['via_robbie'] ?? false), 'email', $send->id);
            if ($opts['counts_as_contact'] ?? ($opts['source_type'] === 'one_off')) {
                $contact->forceFill(['last_contacted_at' => now(), 'first_contact_due_at' => null])->save();
            }
        }

        return $send;
    }

    /** Throws CrmSendBlocked with the reason a person can act on. */
    public function assertSendable(string $to, ?CrmContact $contact, ?User $sender, bool $marketing = true): void
    {
        if ($sender && ($blocker = $this->signatures->sendBlocker($sender->id))) {
            throw new CrmSendBlocked('signature', $blocker);
        }
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new CrmSendBlocked('no_email', 'This contact has no valid email address.');
        }
        if ($contact?->email_status === 'bounced' || $contact?->email_status === 'complained') {
            throw new CrmSendBlocked('bounced', $contact->email_status === 'bounced' ? 'Email to this address bounced, so it is blocked.' : 'This person marked an email as spam, so email is blocked.');
        }
        if ($marketing && ($contact?->email_opt_out_at || $this->suppression->isSuppressed($to))) {
            throw new CrmSendBlocked('opted_out', 'This person unsubscribed from email.');
        }
        if ($sender) {
            $cap = (int) config('crm.email.daily_send_cap_per_rep', 300);
            $today = CrmEmailSend::query()->where('sender_user_id', $sender->id)->where('created_at', '>=', now()->startOfDay())
                ->whereIn('status', ['sent', 'logged', 'queued'])->count();
            if ($cap > 0 && $today >= $cap) {
                throw new CrmSendBlocked('daily_cap', 'You have reached today’s limit of '.$cap.' emails.');
            }
        }
    }
}
