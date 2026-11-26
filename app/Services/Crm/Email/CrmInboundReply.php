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
        $emailId = (string) data_get($payload, 'data.email_id', '');
        abort_if($eventId === '' || $emailId === '', 422, 'Missing email event identity.');
        $receipt = CrmEmailEvent::query()->where('provider_event_id', $eventId)->first();
        if (! $receipt) {
            $content = $this->fetch($emailId);
            $text = trim((string) ($content['text'] ?? ''));
            if ($text === '') {
                $text = trim(CrmHtmlSanitizer::toText((string) ($content['html'] ?? '')));
            }
            $receipt = $this->record($send, $eventId, $emailId, $payload, $this->stripQuoted($text));
        }
        // Reconciliation can record the reply before permitting outbound mail.
        // A subsequent signed delivery can forward the same recorded receipt.
        if (empty($receipt->payload['forward_status']) && ! config('crm.email.defer_reply_forwarding')) {
            $this->forward($send, $receipt);
        }

        return true;
    }

    private function sendFor(array $to): ?CrmEmailSend
    {
        $domain = preg_quote((string) config('crm.email.reply_domain', 'reply.reprophotos.com'), '/');
        foreach ($to as $address) {
            $address = is_array($address) ? ($address['email'] ?? '') : (string) $address;
            if (preg_match('/(?:^|<)r\+([a-z0-9]{12,40})@'.$domain.'(?:$|>)/i', $address, $m)) {
                return CrmEmailSend::query()->where('reply_token', strtolower($m[1]))->first();
            }
        }

        return null;
    }

    private function fetch(string $emailId): array
    {
        $key = trim((string) config('crm.email.receiving_key')) ?: trim((string) config('services.resend.key'));
        abort_if($key === '', 503, 'Inbound mail access is unavailable.');
        $response = Http::withToken($key)->acceptJson()->connectTimeout(5)->timeout(15)
            ->get('https://api.resend.com/emails/receiving/'.rawurlencode($emailId));
        abort_unless($response->successful() && is_array($response->json()), 503, 'Reply content is not available yet.');

        return $response->json();
    }

    private function record(CrmEmailSend $send, string $eventId, string $emailId, array $payload, string $text): CrmEmailEvent
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($send, $eventId, $emailId, $payload, $text) {
            $receipt = CrmEmailEvent::query()->firstOrCreate(['provider_event_id' => $eventId], [
                'send_id' => $send->id, 'type' => 'replied', 'occurred_at' => now(),
                'payload' => ['from' => data_get($payload, 'data.from'), 'subject' => data_get($payload, 'data.subject'),
                    'email_id' => $emailId, 'text' => mb_substr($text, 0, 8000)],
            ]);
            if (! $receipt->wasRecentlyCreated) {
                return $receipt;
            }
            $send->forceFill(['replied_at' => $send->replied_at ?? now()])->save();
            $contact = $send->contact_id ? CrmContact::unscopedForSystem()->find($send->contact_id) : null;
            if ($contact) {
                $this->activity->log($contact, 'email_reply', null, 'Replied: '.mb_substr((string) ($receipt->payload['subject'] ?: $send->subject), 0, 180),
                    ['send_id' => $send->id, 'excerpt' => mb_substr($text, 0, 240), 'message' => mb_substr($text, 0, 1000)]);
                event(new CrmContactReplied($contact, $send->id));
            }
            $repId = $contact?->assigned_rep_id ?? $send->sender_user_id;
            if ($repId) {
                $this->notices->notify($repId, 'email_reply', ($contact?->name ?? $receipt->payload['from']).' replied to your email',
                    mb_substr($text, 0, 300), $contact?->id, ['send_id' => $send->id]);
            }

            return $receipt;
        });
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
    private function forward(CrmEmailSend $send, CrmEmailEvent $receipt): void
    {
        $contact = $send->contact_id ? CrmContact::unscopedForSystem()->find($send->contact_id) : null;
        $repId = $contact?->assigned_rep_id ?? $send->sender_user_id;
        $rep = $repId ? User::query()->find($repId) : null;
        if (! $rep) {
            return;
        }
        $reply = $receipt->payload;
        $who = $contact?->name ?? (string) $reply['from'];
        $to = (int) $rep->id === (int) $send->sender_user_id && $send->from_email
            && str_ends_with(strtolower($send->from_email), '@'.config('crm.email.sender_domain')) ? $send->from_email : $rep->email;
        $base = rtrim((string) config('crm.email.public_base_url'), '/');
        try {
            $result = app(CrmEmailSender::class)->send([
                'to_email' => $to, 'sender' => null, 'source_type' => 'reply_fwd', 'source_id' => $send->id, 'marketing' => false,
                'idempotency_key' => 'crm-reply-fwd-'.$send->id.'-'.md5($reply['email_id']),
                'content' => [
                    'subject' => mb_substr('Reply from '.$who.': '.($reply['subject'] ?: $send->subject), 0, 190),
                    'body_html' => '<p><strong>'.e($who).'</strong> ('.e($reply['from']).') replied:</p><p>'.nl2br(e($reply['text'])).'</p>'
                        .($contact ? '<p><a href="'.e($base.'/crm/contacts/'.$contact->id).'">Open the CRM contact</a></p>' : ''),
                ],
            ]);
            $status = $result->status;
            if ($status === 'queued') {
                abort(503, 'Reply forwarding is still processing.');
            }
        } catch (CrmSendBlocked $e) {
            $status = 'blocked';
        }
        $receipt->forceFill(['payload' => [...$reply, 'forward_status' => $status]])->save();
        if (in_array($status, ['uncertain', 'failed', 'blocked'], true)) {
            $this->notices->notify($rep->id, 'reply_forward_failed', 'Read this reply in Conversations',
                'Forwarding to your inbox was not confirmed.', $contact?->id, ['send_id' => $send->id]);
        }
    }
}
