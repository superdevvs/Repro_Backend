<?php

namespace App\Services\MobileNotifications;

use App\Models\Message;
use App\Models\MobileNotificationDelivery;
use App\Models\ShootActivityLog;
use App\Models\SmsSharedMessage;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\VoiceVoicemail;

/** Small lock-screen previews from allowlisted fields, never serialized records. */
class MobileNotificationContent
{
    private const FALLBACK = ['title' => 'R/E Pro', 'body' => 'You have a new update.'];

    public function alert(MobileNotificationDelivery $delivery): array
    {
        $user = User::find($delivery->user_id);
        // Content is resolved freshly at delivery time, under the same recipient
        // and preference rules as the outbox. A stale/foreign row reveals nothing.
        if (! $user || ! app(MobileNotificationService::class)->allowed($user, $delivery->kind, $delivery->source_id)) {
            return self::FALLBACK;
        }

        $content = match ($delivery->kind) {
            'sms', 'email' => $this->message($delivery),
            'shared_group' => $this->sharedGroup($delivery),
            'support' => $this->support($delivery),
            'voicemail' => $this->voicemail($delivery),
            'shoot_activity' => $this->shoot($delivery),
            'account_activity' => $this->account($delivery),
            default => null,
        };
        if (! $content) {
            return self::FALLBACK;
        }
        $title = $this->plain($content['title'], 64);
        $body = $this->plain($content['body'], 180);

        return $title !== '' && $body !== '' ? compact('title', 'body') : self::FALLBACK;
    }

    private function message(MobileNotificationDelivery $delivery): ?array
    {
        $message = Message::find($delivery->source_id);
        if (! $message) {
            return null;
        }
        // HTML-only emails still have a useful subject; hidden HTML content and
        // attachment/provider data are never mined for a lock-screen preview.
        $body = $this->plain($message->body_text, 180);
        if ($delivery->kind === 'email') {
            $subject = $this->plain($message->subject, 64);
            if ($body === '' && $subject === '') {
                return null;
            }

            return ['title' => $subject ?: 'New email',
                'body' => $body ?: 'Open Messages to read this email.'];
        }
        if ($body === '') {
            // Only canonical attachment records count, never provider metadata.
            // Do not turn redacted nonempty text into an attachment-only claim.
            if (trim((string) $message->body_text) === '' && $this->hasAttachment($message)) {
                return ['title' => 'New message', 'body' => 'Open Messages to view the attachment.'];
            }

            return null;
        }
        $sender = $this->name($message->sender_display_name ?: $message->thread?->contact?->name);

        return ['title' => $sender !== '' ? 'Message from '.$sender : 'New text message', 'body' => $body];
    }

    private function hasAttachment(Message $message): bool
    {
        foreach ((array) $message->attachments_json as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }
            foreach (['storage_path', 'url'] as $field) {
                if (is_string($attachment[$field] ?? null) && trim($attachment[$field]) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    private function sharedGroup(MobileNotificationDelivery $delivery): ?array
    {
        $message = SmsSharedMessage::find($delivery->source_id);
        if (! $message || ($body = $this->plain($message->body_text, 180)) === '') {
            return null;
        }

        return ['title' => 'New group message', 'body' => $body];
    }

    private function support(MobileNotificationDelivery $delivery): ?array
    {
        $message = SupportTicketMessage::with('ticket')->find($delivery->source_id);
        if (! $message?->ticket) {
            return null;
        }
        $subject = $this->plain($message->ticket->subject, 90);
        // Internal notes may contain operational details even for an authorized
        // manager. The ticket subject provides context without exposing the note.
        $body = $message->internal ? '' : $this->plain($message->body, 180);

        return ['title' => match ($message->kind) {
            'opened' => 'New support request',
            'reply' => 'Support reply',
            default => 'Support update',
        }, 'body' => implode(' — ', array_filter([$subject, $body]))];
    }

    private function voicemail(MobileNotificationDelivery $delivery): ?array
    {
        $voicemail = VoiceVoicemail::with('voiceCall.callerUser', 'voiceCall.callerContact')->find($delivery->source_id);
        if (! $voicemail?->voiceCall) {
            return null;
        }
        $caller = $this->name($voicemail->voiceCall->callerUser?->name ?: $voicemail->voiceCall->callerContact?->name);

        return ['title' => 'New voicemail', 'body' => $caller !== ''
            ? $caller.' left a voicemail. Open Calls to listen.'
            : 'A caller left a voicemail. Open Calls to listen.'];
    }

    private function shoot(MobileNotificationDelivery $delivery): ?array
    {
        $event = ShootActivityLog::with('shoot')->find($delivery->source_id);
        if (! $event?->shoot) {
            return null;
        }
        $title = match ($event->action) {
            'shoot_requested' => 'Shoot requested',
            'shoot_created' => 'Shoot booked',
            'shoot_scheduled' => 'Shoot scheduled',
            'shoot_approved' => 'Shoot approved',
            'shoot_declined' => 'Shoot declined',
            'shoot_updated' => 'Shoot updated',
            'shoot_deleted' => 'Shoot deleted',
            'shoot_started' => 'Shoot started',
            'shoot_completed' => 'Shoot completed',
            'shoot_delivered', 'shoot_finalized_delivered' => 'Photos delivered',
            'shoot_cancelled' => 'Shoot cancelled',
            'cancellation_requested' => 'Cancellation requested',
            'cancellation_rejected' => 'Cancellation request declined',
            'shoot_put_on_hold', 'hold_approved' => 'Shoot on hold',
            'shoot_resumed_from_hold' => 'Shoot resumed',
            'hold_requested' => 'Hold requested',
            'hold_rejected' => 'Hold request declined',
            'shoot_editing_started' => 'Editing started',
            'shoot_submitted_for_review' => 'Shoot ready for review',
            'shoot_submitted_for_editing_review', 'shoot_submitted_edited' => 'Edits ready for review',
            'shoot_submitted_raw' => 'Raw files ready for editing',
            'payment_done', 'payment_received' => 'Payment received',
            'payment_completed' => 'Shoot fully paid',
            'payment_failed' => 'Payment failed',
            'payment_refunded' => 'Payment refunded',
            'payment_marked_paid' => 'Shoot marked paid',
            'media_uploaded' => 'New shoot media',
            'media_upload_initiated' => 'Media upload started',
            'photographer_assigned' => 'Photographer assigned',
            'editor_assigned' => 'Editor assigned',
            'rescheduled' => 'Shoot rescheduled',
            'reschedule_requested' => 'Reschedule requested',
            'bright_mls_synced' => 'Media synced to Bright MLS',
            'tour_links_generated' => 'Tour links ready',
            'hero_image_updated' => 'Cover photo updated',
            'raw_downloaded_by_editor' => 'Editor downloaded raw files',
            'share_link_generated' => 'Share link ready',
            'share_link_revoked' => 'Share link revoked',
            default => null,
        };
        if (! $title) {
            return null;
        }
        $address = $this->plain($event->shoot->address, 120);

        // Raw descriptions/metadata can contain prices, internal notes or access
        // instructions. Only the known event and property label belong here.
        return ['title' => $title, 'body' => $address !== ''
            ? $title.' for '.$address.'.' : $title.'. Open Shoots for details.'];
    }

    private function account(MobileNotificationDelivery $delivery): ?array
    {
        $event = UserActivityLog::with('user')->find($delivery->source_id);
        if (! $event?->user) {
            return null;
        }
        $content = match ($event->event_type) {
            'account_created' => ['New account', 'An account was created.'],
            'email_bounced' => ['Email delivery failed', 'Review the account email address.'],
            'email_delivery_risky' => ['Email address needs attention', 'Review the account email address before sending.'],
            'email_verification_requested' => ['Email verification requested', 'Email verification is pending.'],
            'email_corrected_after_bounce' => ['Email address updated', 'The account email address was corrected.'],
            'email_verified' => ['Email verified', 'The account email address was verified.'],
            default => null,
        };
        if (! $content) {
            return null;
        }
        $name = $this->name($event->user->name);

        return ['title' => $content[0], 'body' => ($name !== '' ? $name.': ' : '').$content[1]];
    }

    private function name(mixed $value): string
    {
        $name = $this->plain($value, 48);

        return ! preg_match('/\p{L}/u', $name) || str_contains($name, '[email]') ? '' : $name;
    }

    private function plain(mixed $value, int $limit): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }
        $value = mb_substr(mb_scrub($value, 'UTF-8'), 0, 8000);
        if (is_array(json_decode($value, true))) {
            return ''; // A structured payload is not a human notification.
        }
        for ($pass = 0; $pass < 2; $pass++) {
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $value = preg_replace('/<(script|style|head|template)\b[^>]*>.*?(?:<\/\1\s*>|\z)/isu', ' ', $value) ?? '';
            $value = strip_tags($value);
        }
        $value = preg_replace('/\p{Cf}+/u', '', $value) ?? '';
        $value = preg_replace('/\p{Cc}+/u', ' ', $value) ?? '';
        $value = preg_replace('~(?:https?://|www\.)\S+~iu', ' ', $value) ?? '';
        $value = preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/u', '[email]', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if (is_array(json_decode($value, true)) || preg_match('/\A(?:a|O|s):\d+:/', $value)) {
            return '';
        }
        // Do not place credential, access-code, card/bank or amount-bearing text
        // on a lock screen. The recipient can read it inside the authorized app.
        if (preg_match('/(?:PRIVATE KEY|Bearer\s+\S+|\bsk-[A-Za-z0-9_-]{12,}|\beyJ[\w-]+\.[\w-]+\.[\w-]+|\b[A-Za-z0-9_-]{40,}\b|[$€£₹]\s*\d|\b(?:USD|EUR|GBP|INR)\s*\d|\d[\d,.]*\s*(?:USD|EUR|GBP|INR)\b|\b(?:bank account|routing number|credit card)\b|(?:\d[ -]?){13,19})/iu', $value)
            || preg_match('/\b(?:password|passcode|otp|(?:verification|security|access|door|lockbox|gate|one[ -]?time|login|sign[ -]?in)\s+code|(?:api|access|refresh)[ _-]?(?:key|token)|authorization|secret)\b[\s"\':=]+(?:is\s+)?\S{3,}/iu', $value)
            || preg_match('/[?&](?:token|code|key|password|secret|signature|access_token|refresh_token|api_key)=[^\s&]+/iu', $value)) {
            return '';
        }

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1).'…' : $value;
    }
}
