<?php

namespace App\Http\Controllers\API\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEmailEvent;
use App\Models\Crm\CrmEmailSend;
use App\Services\Crm\CrmSmsConsent;
use App\Services\Crm\CrmSmsSender;
use App\Services\Crm\Email\CrmMarketingConsent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations (F5): one timeline of CRM emails and the Dashboard's existing
 * texts and calls with this contact's number. Texting from the CRM needs the
 * contact's recorded SMS consent and the configured provider.
 */
class CrmConversationController extends CrmController
{
    public function show(Request $request, CrmContact $contact): JsonResponse
    {
        $items = collect();

        CrmEmailSend::query()->where('contact_id', $contact->id)->orderByDesc('id')->limit(100)->get()
            ->each(fn ($s) => $items->push([
                'channel' => 'email', 'direction' => 'out', 'at' => ($s->sent_at ?? $s->created_at)?->toIso8601String(),
                'title' => $s->subject, 'body' => mb_substr((string) $s->text, 0, 600), 'status' => $s->status,
                'opened' => $s->first_opened_at !== null, 'replied' => $s->replied_at !== null, 'id' => 'email-'.$s->id,
            ]));

        CrmEmailEvent::query()->where('type', 'replied')
            ->whereIn('send_id', CrmEmailSend::query()->select('id')->where('contact_id', $contact->id))
            ->orderByDesc('id')->limit(100)->get()->each(fn ($reply) => $items->push([
                'channel' => 'email', 'direction' => 'in', 'at' => $reply->occurred_at?->toIso8601String(),
                'title' => $reply->payload['subject'] ?? 'Email reply', 'body' => $reply->payload['text'] ?? '',
                'status' => 'received', 'id' => 'reply-'.$reply->id,
            ]));
        $digits = $contact->phone_digits ? substr($contact->phone_digits, -10) : null;
        if ($digits && strlen($digits) === 10) {
            if (Schema::hasTable('messages')) {
                DB::table('messages')->where('channel', 'SMS')
                    ->where(fn ($q) => $q->where('to_address', 'like', '%'.$digits)->orWhere('from_address', 'like', '%'.$digits))
                    ->orderByDesc('id')->limit(100)->get(['id', 'direction', 'body_text', 'status', 'sent_at', 'created_at'])
                    ->each(fn ($m) => $items->push([
                        'channel' => 'sms', 'direction' => strtoupper((string) $m->direction) === 'INBOUND' ? 'in' : 'out',
                        'at' => (string) ($m->sent_at ?? $m->created_at), 'title' => null, 'body' => mb_substr((string) $m->body_text, 0, 1000),
                        'status' => strtolower((string) $m->status), 'id' => 'sms-'.$m->id,
                    ]));
            }
            if (Schema::hasTable('voice_calls')) {
                DB::table('voice_calls')->where(fn ($q) => $q->where('from_phone', 'like', '%'.$digits)->orWhere('to_phone', 'like', '%'.$digits))
                    ->orderByDesc('id')->limit(50)->get(['id', 'direction', 'status', 'duration_seconds', 'summary', 'started_at', 'created_at'])
                    ->each(fn ($c) => $items->push([
                        'channel' => 'call', 'direction' => in_array(strtolower((string) $c->direction), ['inbound', 'in'], true) ? 'in' : 'out',
                        'at' => (string) ($c->started_at ?? $c->created_at), 'title' => $c->duration_seconds ? gmdate($c->duration_seconds >= 3600 ? 'G:i:s' : 'i:s', (int) $c->duration_seconds) : null,
                        'body' => $c->summary ? mb_substr((string) $c->summary, 0, 1000) : null, 'status' => (string) $c->status, 'id' => 'call-'.$c->id,
                    ]));
            }
        }

        return response()->json([
            'items' => $items->sortByDesc(fn ($i) => strtotime((string) $i['at']) ?: 0)->values()->all(),
            'marketing_email' => [
                'consent' => app(CrmMarketingConsent::class)->allows($contact),
                'consent_at' => $contact->marketing_consent_at?->toIso8601String(), 'source' => $contact->marketing_consent_source,
            ],
            'sms' => [
                'enabled' => (bool) config('crm.sms.enabled'),
                'timezone' => app(CrmSmsSender::class)->timezone($contact),
                'consent' => app(CrmSmsConsent::class)->allows($contact),
                'consent_at' => $contact->sms_consent_at?->toIso8601String(),
                'source' => $contact->sms_consent_source,
                'can_send' => app(CrmSmsConsent::class)->allows($contact) && strlen((string) $contact->phone_digits) === 10
                    && (bool) config('crm.sms.enabled') && $this->access()->can($this->actor($request), 'crm-contacts', 'update'),
            ],
        ]);
    }

    public function consent(Request $request, CrmContact $contact): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'update')) {
            return $denied;
        }
        $data = $request->validate(['consent' => ['required', 'boolean'], 'source' => ['required_if:consent,true', 'nullable', 'in:verbal,written,website,text_reply']]);
        if ($data['consent'] && ! $contact->phone_digits) {
            return response()->json(['message' => 'Add a phone number before recording consent.'], 422);
        }
        app(CrmSmsConsent::class)->record($contact, $data['consent'], $data['source'] ?? 'manual', $this->actor($request));

        return $this->show($request, $contact->fresh());
    }

    public function marketingConsent(Request $request, CrmContact $contact): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'update')) {
            return $denied;
        }
        $data = $request->validate(['consent' => ['required', 'boolean'],
            'source' => ['required_if:consent,true', 'nullable', 'in:verbal,written,website,email_reply']]);
        if ($data['consent'] && ! $contact->email_normalized) {
            return response()->json(['message' => 'Add an email address before recording consent.'], 422);
        }
        app(CrmMarketingConsent::class)->record($contact, $data['consent'], $data['source'] ?? 'manual', $this->actor($request));

        return $this->show($request, $contact->fresh());
    }

    public function sendSms(Request $request, CrmContact $contact, CrmSmsSender $sender): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'update')) {
            return $denied;
        }
        $data = $request->validate(['body' => ['required', 'string', 'max:1600'], 'request_id' => ['required', 'uuid'],
            'timezone' => ['nullable', 'timezone']]);
        $result = $sender->send($contact, $this->actor($request), $data);

        return response()->json($result, 200);
    }
}
