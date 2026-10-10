<?php

namespace App\Http\Controllers\API;

use App\Exceptions\Messaging\EmailProviderRejectedException;
use App\Http\Controllers\Controller;
use App\Models\ContactSubmission;
use App\Models\MessageTemplate;
use App\Models\Shoot;
use App\Services\MailService;
use App\Services\Messaging\MessagingService;
use App\Services\SystemEmails\DirectEmailTemplates;
use App\Support\LockedWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Server-to-server office notifications; never an arbitrary email relay. */
class WebsiteEmailController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:contact,booking'],
            'reference' => ['required', 'uuid'],
            'shoot_id' => ['required_if:type,booking', 'integer', 'min:1'],
            'contact' => ['required_if:type,contact', 'array:first_name,last_name,email,phone,message,sms_consent,created_at'],
            'contact.first_name' => ['required_if:type,contact', 'string', 'max:100'],
            'contact.last_name' => ['required_if:type,contact', 'string', 'max:100'],
            'contact.email' => ['required_if:type,contact', 'email', 'max:254'],
            'contact.phone' => ['required_if:type,contact', 'string', 'max:40'],
            'contact.message' => ['required_if:type,contact', 'string', 'max:5000'],
            'contact.sms_consent' => ['required_if:type,contact', 'boolean'],
            'contact.created_at' => ['required_if:type,contact', 'date'],
        ]);

        $shoot = null;
        if ($data['type'] === 'booking') {
            $shoot = Shoot::find($data['shoot_id']);
            if (! $shoot || data_get($shoot->external_booking_payload, 'source') !== 'reprophotos.com'
                || data_get($shoot->external_booking_payload, 'external_reference') !== $data['reference']) {
                return response()->json(['ok' => false, 'outcome' => 'failed', 'error' => 'booking_reference_mismatch'], 422);
            }
        }

        // Hash immutable request fields, not the current editable template or shoot state.
        $identity = $data['type'] === 'contact' ? $data['contact'] : ['shoot_id' => $data['shoot_id']];
        ksort($identity);
        $hash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        $key = ['type' => $data['type'], 'reference' => strtolower($data['reference'])];
        LockedWrite::run(fn () => DB::table('website_email_deliveries')->insertOrIgnore([
            ...$key, 'payload_hash' => $hash, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]), 'website-email-register');
        $row = DB::table('website_email_deliveries')->where($key)->first();
        if (! hash_equals($row->payload_hash, $hash)) {
            return response()->json(['ok' => false, 'outcome' => 'failed', 'error' => 'reference_conflict'], 409);
        }
        if (in_array($row->status, ['sending', 'uncertain', 'delivered'], true)) {
            return $this->result($row->status === 'delivered' ? 'delivered' : 'uncertain', $row->message_id, true);
        }

        try {
            if ($data['type'] === 'contact') {
                $contact = $data['contact'];
                $submission = new ContactSubmission([
                    'sender_name' => trim($contact['first_name'].' '.$contact['last_name']),
                    'sender_email' => $contact['email'], 'sender_phone' => $contact['phone'],
                    'message' => $contact['message'], 'source' => 'reprophotos.com',
                ]);
                $submission->created_at = $contact['created_at'];
                $rendered = app(DirectEmailTemplates::class)->render('emails.contact_notification', [
                    'submission' => $submission, 'recipientName' => 'R/E Pro Photos Office',
                    'isWebsiteInquiry' => true, 'smsConsent' => (bool) $contact['sms_consent'],
                    'inquiryReference' => $key['reference'],
                ], 'New website inquiry — '.$submission->sender_name);
                if ($rendered === null) {
                    return $this->result('failed');
                }
                $templateId = MessageTemplate::where('slug', 'contact-notification')->where('channel', 'EMAIL')->value('id');
                $payload = ['subject' => $rendered['subject'], 'body_html' => $rendered['html'], 'body_text' => $rendered['text'],
                    'template_id' => $templateId, 'reply_to' => $contact['email'],
                    'contact_email' => $contact['email'], 'contact_name' => $submission->sender_name];
            } else {
                $built = app(MailService::class)->buildWebsiteBookingEmail($shoot);
                $payload = ['subject' => $built['subject'], 'body_html' => $built['body_html'], 'body_text' => $built['body_text'],
                    'related_shoot_id' => $shoot->id, 'related_account_id' => $shoot->client_id,
                    'reply_to' => $shoot->client?->email, 'contact_email' => 'contact@reprophotos.com', 'contact_type' => 'admin'];
            }
        } catch (\Throwable $exception) {
            // Rendering has no provider side effect; this can safely be corrected and retried.
            Log::error('Website email rendering failed.', ['reference' => $key['reference'], 'exception' => get_class($exception)]);

            return $this->result('failed');
        }

        $claimed = LockedWrite::run(fn () => DB::table('website_email_deliveries')->where($key)
            ->whereIn('status', ['pending', 'failed'])->update(['status' => 'sending', 'updated_at' => now()]), 'website-email-claim');
        if (! $claimed) {
            $row = DB::table('website_email_deliveries')->where($key)->first();

            return $this->result($row->status === 'delivered' ? 'delivered' : 'uncertain', $row->message_id, true);
        }

        // Network calls stay outside SQLite transactions. An ambiguous attempt is never resent.
        $messageId = null;
        try {
            $message = app(MessagingService::class)->sendEmail([
                ...$payload, 'from' => 'contact@reprophotos.com', 'to' => 'contact@reprophotos.com',
                'sender_name' => 'R/E Pro Photos', 'send_source' => 'WEBSITE_'.strtoupper($data['type']),
                'metadata' => ['website_reference' => $key['reference'], 'website_type' => $data['type'],
                    'template_view' => $data['type'] === 'contact' ? 'emails.contact_notification' : 'emails.shoot_requested'],
            ]);
            $messageId = $message->id;
            $outcome = in_array(strtoupper($message->status), ['SENT', 'DELIVERED'], true) ? 'delivered' : 'failed';
        } catch (EmailProviderRejectedException $exception) {
            $outcome = 'failed';
        } catch (\Throwable $exception) {
            $outcome = 'uncertain';
            Log::error('Website email acceptance could not be confirmed.', ['reference' => $key['reference'], 'exception' => get_class($exception)]);
        }
        LockedWrite::run(fn () => DB::table('website_email_deliveries')->where($key)->update([
            'status' => $outcome, 'message_id' => $messageId, 'updated_at' => now(),
        ]), 'website-email-outcome');

        return $this->result($outcome, $messageId);
    }

    private function result(string $outcome, ?int $messageId = null, bool $duplicate = false): JsonResponse
    {
        return response()->json(['ok' => $outcome === 'delivered', 'outcome' => $outcome, 'message_id' => $messageId, 'duplicate' => $duplicate]);
    }
}
