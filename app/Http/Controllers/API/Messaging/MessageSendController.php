<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\{Controller, API\SupportTicketController};
use App\Models\{MessageThread, SmsSharedGroup, SupportTicketMessage};
use App\Services\Messaging\{MessageContentAccess, MessageMediaService};
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\InputBag;

/** Adds file links and replay protection around the existing, authorized sending paths. */
class MessageSendController extends Controller
{
    public function send(Request $r)
    {
        abort_if($r->attributes->get('is_impersonating') || $r->hasHeader('X-Impersonate-User-Id'), 403);
        $data = $r->validate([
            'request_key' => ['required', 'uuid'], 'channel' => ['required', Rule::in(['sms', 'shared', 'email', 'support'])],
            'conversation_id' => ['nullable', 'string', 'max:40', 'regex:/^[0-9a-fA-F-]+$/D'],
            'recipient' => ['nullable', 'string', 'max:254'], 'sender_id' => ['nullable', 'integer', 'min:1'],
            'body' => ['nullable', 'string', 'max:8000'], 'subject' => ['nullable', 'string', 'max:180'],
            'category' => ['nullable', 'string', 'max:40'],
            'title' => ['nullable', 'string', 'max:80'], 'participants' => ['sometimes', 'array', 'min:2', 'max:8'],
            'participants.*' => ['string', 'max:20', 'distinct'], 'member_user_ids' => ['sometimes', 'array', 'max:20'], 'member_user_ids.*' => ['integer', 'min:1', 'distinct'],
            'attachment_ids' => ['sometimes', 'array', 'max:10'], 'attachment_ids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $data['request_key'] = strtolower($data['request_key']);
        $data['attachment_ids'] ??= [];
        $data['body'] = trim($data['body'] ?? '');
        abort_unless($data['body'] !== '' || $data['attachment_ids'] !== [], 422, 'Add a message or attachment.');
        abort_if(in_array($data['channel'], ['sms', 'shared']) && mb_strlen($data['body']) > 1200, 422, 'Keep the message under 1,200 characters.');
        app(MessageContentAccess::class)->channel($r->user(), $data['channel'], true);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        return Cache::lock('message-send:'.$r->user()->id.':'.$data['request_key'], 180)->block(3, function () use ($r, $data, $hash) {
            $query = DB::table('message_send_receipts')->where('user_id', $r->user()->id)->where('request_key', $data['request_key']);
            if ($existing = $query->first()) {
                abort_unless(hash_equals($existing->fingerprint, $hash), 409, 'This send key belongs to a different draft.');
                if ($existing->response !== null) { return response()->json(json_decode($existing->response, true), $existing->http_status); }
                return response()->json(['success' => false, 'status' => 'uncertain', 'message' => 'Sending is not confirmed. Refresh this conversation before starting another send.'], 409);
            }
            $media = app(MessageMediaService::class);
            $files = $media->attachments($r->user(), $data['channel'], $data['attachment_ids']);
            foreach ($files as $file) { $file->update(['share_expires_at' => now()->addDays(30)]); }
            $body = $data['channel'] === 'support' ? ($data['body'] ?: 'Shared attachments.') : $media->linkedBody($data['body'], $files);
            $fields = ['body' => $body, 'body_text' => $body, 'to' => $data['recipient'] ?? '',
                'sms_number_id' => $data['sender_id'] ?? null, 'request_key' => $data['request_key'],
                'subject' => $data['subject'] ?? '', 'category' => $data['category'] ?? 'other', 'internal' => false];
            if ($data['channel'] === 'shared' && empty($data['conversation_id'])) {
                $fields += ['title' => $data['title'] ?? '', 'participants' => $data['participants'] ?? [], 'member_user_ids' => $data['member_user_ids'] ?? []];
            }
            $request = $r->duplicate(); $request->replace($fields); $request->setJson(new InputBag($fields));
            $request->attributes->set('message_media_send', true);
            // All validation/permission checks in the original controllers remain authoritative.
            // Reserve before invoking a provider. A timeout/crash is never retried automatically.
            LockedWrite::run(fn () => DB::transaction(function () use ($r, $data, $hash, $files) {
                DB::table('message_send_receipts')->insert([
                    'user_id' => $r->user()->id, 'request_key' => $data['request_key'], 'fingerprint' => $hash,
                    'status' => 'sending', 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($files as $file) {
                    abort_unless(\App\Models\MessageMedia::whereKey($file->id)->whereNull('message_id')->whereNull('send_request_key')
                        ->where('status', 'ready')->update(['send_request_key' => $data['request_key']]) === 1, 409, 'An attachment is already being sent.');
                }
            }), 'messaging.send.reserve');
            request()->attributes->set('message_media_send_key', $data['request_key']);
            try { $response = match ($data['channel']) {
                'sms' => ! empty($data['conversation_id'])
                    ? app(SmsMessagingController::class)->sendToThread(MessageThread::findOrFail($data['conversation_id']), $request)
                    : app(SmsMessagingController::class)->send($request),
                'shared' => ! empty($data['conversation_id']) ? app(SharedMmsController::class)->send($request, SmsSharedGroup::findOrFail($data['conversation_id'])) : app(SharedMmsController::class)->store($request),
                'email' => app(EmailMessagingController::class)->compose($request),
                'support' => ! empty($data['conversation_id'])
                    ? app(SupportTicketController::class)->reply($request, (int) $data['conversation_id'])
                    : app(SupportTicketController::class)->store($request),
            }; } catch (\Illuminate\Validation\ValidationException $error) {
                if (! request()->attributes->get('message_media_record_created')) {
                    LockedWrite::run(fn () => DB::transaction(function () use ($query, $r, $data) {
                        $query->delete();
                        \App\Models\MessageMedia::where('user_id', $r->user()->id)->where('send_request_key', $data['request_key'])->whereNull('message_id')->update(['send_request_key' => null]);
                    }), 'messaging.send.release');
                }
                throw $error;
            } finally {
                request()->attributes->remove('message_media_send_key');
                request()->attributes->remove('message_media_record_created');
            }
            $result = $response->getData(true);
            $status = $response->getStatusCode();
            $id = $data['channel'] === 'support'
                ? SupportTicketMessage::where('author_id', $r->user()->id)->where('request_key', $data['request_key'])->value('id')
                : data_get($result, 'message.id', $result['id'] ?? null);
            if ($id) { $media->bind($files, $data['channel'], (string) $id); }
            $payload = ['success' => $status < 400 && $id !== null && ! in_array(strtoupper((string) data_get($result, 'message.status', $result['status'] ?? '')), ['FAILED', 'BLOCKED', 'UNCERTAIN', 'SENDING']),
                'id' => $id ? (string) $id : null, 'result' => $result,
                'conversation_id' => (string) ($data['channel'] === 'support' ? data_get($result, 'data.id') : data_get($result, 'thread.id', data_get($result, 'data.id', $data['conversation_id'] ?? ''))),
                'attachments' => $files->map(fn ($file) => $media->payload($file))->values()->all()];
            if ($data['channel'] === 'shared' && $id) {
                $payload['success'] = $status < 400 && \App\Models\SmsSharedMessage::whereKey($id)->value('status') === 'accepted';
            }
            LockedWrite::run(fn () => $query->update(['status' => $payload['success'] ? 'completed' : 'unconfirmed',
                'response' => json_encode($payload, JSON_THROW_ON_ERROR), 'http_status' => $status, 'updated_at' => now()]), 'messaging.send.complete');
            return response()->json($payload, $status);
        });
    }
}
