<?php

namespace App\Http\Controllers\API\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEmailSend;
use App\Services\Crm\CrmActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations (F5): one timeline of CRM emails and the Dashboard's existing
 * texts and calls with this contact's number. Texting from the CRM needs the
 * contact's SMS consent; sending is switched on in the final stage.
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
            'sms' => [
                'consent' => (bool) $contact->sms_consent,
                'consent_at' => $contact->sms_consent_at?->toIso8601String(),
                'source' => $contact->sms_consent_source,
                'can_send' => (bool) $contact->sms_consent && $digits !== null && (bool) config('crm.sms.enabled', false),
            ],
        ]);
    }

    public function consent(Request $request, CrmContact $contact): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'update')) {
            return $denied;
        }
        $data = $request->validate(['consent' => ['required', 'boolean'], 'source' => ['required_if:consent,true', 'nullable', 'in:verbal,written,website,text_reply']]);
        $contact->forceFill([
            'sms_consent' => $data['consent'],
            'sms_consent_at' => $data['consent'] ? now() : null,
            'sms_consent_source' => $data['consent'] ? $data['source'] : null,
        ])->save();
        app(CrmActivityLogger::class)->log($contact, 'sms_consent', $this->actor($request), $data['consent'] ? 'SMS consent recorded ('.$data['source'].')' : 'SMS consent withdrawn');

        return $this->show($request, $contact->fresh());
    }
}
