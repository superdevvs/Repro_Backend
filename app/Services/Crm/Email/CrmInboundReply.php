<?php

namespace App\Services\Crm\Email;

use App\Events\Crm\CrmContactReplied;
use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEmailEvent;
use App\Models\Crm\CrmEmailSend;
use App\Models\User;
use App\Services\Crm\CrmActivityLogger;
use App\Services\Crm\CrmNoticeService;
use Illuminate\Support\Facades\Http;

/**
 * Replies to CRM email (owner decision 4). Reply-To is r+<token>@reply domain;
 * Resend's "email.received" webhook names that address. The reply stops the
 * contact's drip, goes on their history, and is forwarded to the rep.
 * The webhook carries metadata only, so the body is fetched from Resend.
 */
class CrmInboundReply
{
    public function __construct(
        private readonly CrmActivityLogger $activity,
        private readonly CrmNoticeService $notices,
    ) {}

    /** @return bool true when the email was a reply to a CRM send */
    public function handle(array $payload, string $eventId): bool
    {
        $send = $this->sendFor((array) data_get($payload, 'data.to', []));
        if (! $send) {
            return false;
        }
        if ($eventId !== '' && CrmEmailEvent::query()->where('provider_event_id', $eventId)->exists()) {
            return true;
        }

        $emailId = (string) data_get($payload, 'data.email_id', '');
        $content = $emailId !== '' ? $this->fetch($emailId) : [];
        $text = trim((string) ($content['text'] ?? ''));
        $excerpt = mb_substr($this->stripQuoted($text), 0, 1000);

        CrmEmailEvent::create([
            'send_id' => $send->id, 'provider_event_id' => $eventId !== '' ? $eventId : null, 'type' => 'replied',
            'payload' => ['from' => data_get($payload, 'data.from'), 'subject' => data_get($payload, 'data.subject'), 'email_id' => $emailId], 'occurred_at' => now(),
        ]);
        $send->forceFill(['replied_at' => $send->replied_at ?? now()])->save();

        $contact = $send->contact_id ? CrmContact::unscopedForSystem()->find($send->contact_id) : null;
        if ($contact) {
            $this->activity->log($contact, 'email_reply', null, 'Replied: '.mb_substr((string) data_get($payload, 'data.subject', $send->subject), 0, 180), ['send_id' => $send->id, 'excerpt' => mb_substr($excerpt, 0, 240), 'message' => $excerpt]);
            event(new CrmContactReplied($contact, $send->id));
        }
        $this->forward($send, $contact, $payload, $text);

        return true;
    }

    private function sendFor(array $to): ?CrmEmailSend
    {
        $domain = preg_quote((string) config('crm.email.reply_domain', 'reply.reprophotos.com'), '/');
        foreach ($to as $address) {
            $address = is_array($address) ? ($address['email'] ?? '') : (string) $address;
            if (preg_match('/r\+([a-z0-9]{12,40})@'.$domain.'/i', $address, $m)) {
                return CrmEmailSend::query()->where('reply_token', strtolower($m[1]))->first();
            }
        }

        return null;
    }

    private function fetch(string $emailId): array
    {
        $key = trim((string) config('services.resend.key'));
        if ($key === '') {
            return [];
        }
        try {
            $response = Http::withToken($key)->acceptJson()->connectTimeout(5)->timeout(15)
                ->get('https://api.resend.com/emails/receiving/'.rawurlencode($emailId));

            return $response->successful() ? (array) $response->json() : [];
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** Keep the new part of the reply, not the quoted original. */
    private function stripQuoted(string $text): string
    {
        $lines = preg_split('/\r?\n/', $text) ?: [];
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^(>|On .+wrote:$|-----Original Message-----|From: )/', trim($line))) {
                break;
            }
            $out[] = $line;
        }

        return trim(implode("\n", $out)) ?: $text;
    }

    /** The rep gets the reply in their own inbox, from the CRM's system address, plus a notice. */
    private function forward(CrmEmailSend $send, ?CrmContact $contact, array $payload, string $text): void
    {
        $repId = $contact?->assigned_rep_id ?? $send->sender_user_id;
        $rep = $repId ? User::query()->find($repId) : null;
        if (! $rep) {
            return;
        }
        $who = $contact?->name ?? (string) data_get($payload, 'data.from', 'Someone');
        $this->notices->notify($rep->id, 'email_reply', $who.' replied to your email', mb_substr($this->stripQuoted($text), 0, 300) ?: null, $contact?->id, ['send_id' => $send->id]);

        $to = $send->from_email && str_ends_with(strtolower($send->from_email), '@'.config('crm.email.sender_domain', 'reprophotos.com')) ? $send->from_email : $rep->email;
        $base = rtrim((string) config('crm.email.public_base_url'), '/');
        try {
            app(CrmEmailSender::class)->send([
                'to_email' => $to, 'sender' => null, 'source_type' => 'reply_fwd', 'source_id' => $send->id, 'marketing' => false,
                'idempotency_key' => 'crm-reply-fwd-'.$send->id.'-'.md5((string) data_get($payload, 'data.email_id', '')),
                'content' => [
                    'subject' => 'Reply from '.$who.': '.mb_substr((string) data_get($payload, 'data.subject', $send->subject), 0, 150),
                    'body_html' => '<p><strong>'.e($who).'</strong> ('.e((string) data_get($payload, 'data.from', '')).') replied:</p><p>'.nl2br(e(mb_substr($text, 0, 8000))).'</p>'
                        .($contact ? '<p><a href="'.e($base.'/crm/contacts/'.$contact->id).'">Open '.e($contact->name).' in the CRM</a></p>' : ''),
                    'preheader' => null, 'image_url' => null, 'cta_label' => null, 'cta_url' => null,
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
