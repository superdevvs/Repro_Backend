<?php

namespace App\Services;

use App\Models\Message;
use App\Services\Messaging\DashboardMessagingPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SupportLegacyComposeService
{
    public function submit(Request $request): JsonResponse
    {
        $user = $request->user();
        $tickets = app(SupportTicketService::class);
        abort_unless($user->isAccountEligibleForAuthentication() && app(RolePermissionService::class)->userCan($user, 'support', 'view'), 403);
        $data = $request->validate([
            'request_key' => ['nullable', 'uuid'], 'subject' => ['nullable', 'string', 'max:180'],
            'body_text' => ['nullable', 'string', 'max:8000'], 'body_html' => ['nullable', 'string', 'max:20000'],
            'in_reply_to_message_id' => ['nullable', 'integer'],
            ...SupportAttachmentService::rules(),
        ]);
        $body = trim((string) ($data['body_text'] ?? ''));
        if ($body === '') {
            $body = trim(html_entity_decode(strip_tags((string) ($data['body_html'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        abort_unless($body !== '' && mb_strlen($body) <= 8000, 422, 'Write a support message of up to 8,000 characters.');
        $ticket = null;
        if (! empty($data['in_reply_to_message_id'])) {
            $original = Message::findOrFail($data['in_reply_to_message_id']);
            // Authorize the immutable initiating requester before importing anything.
            $root = $original;
            $seen = [];
            while ($parent = (int) data_get($root->metadata, 'internal_reply_to_message_id', 0)) {
                abort_if(count($seen) >= 100 || in_array($parent, $seen, true), 404);
                $seen[] = $parent;
                $root = Message::findOrFail($parent);
            }
            abort_unless($tickets->canManage($user) || ((int) ($root->sender_user_id ?: $root->created_by) === (int) $user->id
                && ! DashboardMessagingPolicy::staffRole($root->sender_role)), 404);
            $ticket = app(SupportLegacyMessageImporter::class)->import($original);
            abort_unless($ticket, 404, 'This message cannot be converted to a private support request.');
            $tickets->find($user, $ticket->id);
        }
        $input = ['request_key' => $data['request_key'] ?? (string) Str::uuid(), 'body' => $body,
            'subject' => trim((string) ($data['subject'] ?? '')) ?: 'Dashboard support request',
            'category' => 'other', 'page_path' => '/messaging/email/inbox'];
        $reply = $ticket !== null;
        $ticket = app(SupportAttachmentService::class)->submit($request, $input,
            fn ($prepared) => $reply ? $tickets->reply($user, $ticket->id, $prepared) : $tickets->create($user, $prepared));

        return response()->json(['data' => $tickets->payload($ticket, $user), 'support_ticket_id' => $ticket->id,
            'redirect_url' => '/messaging/email/inbox?tab=support&ticket='.$ticket->id,
            'message' => 'Saved in Support. The support team can reply in your dashboard.'], $reply ? 200 : 201);
    }
}
