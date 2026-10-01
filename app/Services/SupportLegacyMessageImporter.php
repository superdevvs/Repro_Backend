<?php

namespace App\Services;

use App\Models\Message;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Services\Messaging\DashboardMessagingPolicy;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Imports dashboard contact history only. Never ingests external mail or sends notifications. */
class SupportLegacyMessageImporter
{
    public function import(Message $message, array $visited = []): ?SupportTicket
    {
        if (count($visited) >= 100 || in_array($message->id, $visited, true)) {
            return null;
        }
        if ($existing = SupportTicketMessage::where('source_message_id', $message->id)->first()) {
            return $existing->ticket;
        }
        if ($message->channel !== 'EMAIL' || $message->provider !== 'INTERNAL' || $message->send_source !== 'MANUAL') {
            return null;
        }
        $authorId = (int) ($message->sender_user_id ?: $message->created_by);
        $author = User::find($authorId);
        if (! $author || ($message->created_by && (int) $message->created_by !== $authorId)) {
            return null;
        }
        $ticket = null;
        $parentId = (int) data_get($message->metadata, 'internal_reply_to_message_id', 0);
        if ($parentId) {
            $parent = Message::find($parentId);
            $ticket = $parent ? $this->import($parent, [...$visited, $message->id]) : null;
            if (! $ticket) {
                return null;
            }
            $ownReply = $authorId === (int) $ticket->requester_id && $message->direction === 'INBOUND';
            $staffReply = DashboardMessagingPolicy::staffRole($message->sender_role)
                && DashboardMessagingPolicy::staffRole($author->role) && $message->direction === 'OUTBOUND'
                && strcasecmp(trim((string) $message->to_address), trim((string) $ticket->requester->email)) === 0;
            // A shared shoot/thread alone never proves a reply belongs to this requester.
            if (! $ownReply && ! $staffReply) {
                return null;
            }
        } elseif ($message->direction !== 'INBOUND' || DashboardMessagingPolicy::staffRole($message->sender_role)
            || DashboardMessagingPolicy::staffRole($author->role)
            || strcasecmp(trim((string) $message->from_address), trim((string) $author->email)) !== 0) {
            return null;
        }

        return LockedWrite::run(fn () => DB::transaction(function () use ($message, $ticket, $authorId) {
            if ($existing = SupportTicketMessage::where('source_message_id', $message->id)->first()) {
                return $existing->ticket;
            }
            $body = (string) ($message->body_text ?: html_entity_decode(strip_tags((string) $message->body_html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $opened = ! $ticket;
            $ticket ??= SupportTicket::firstOrCreate(['requester_id' => $authorId, 'request_key' => 'legacy-message-'.$message->id], [
                'subject' => Str::limit(trim((string) $message->subject) ?: 'Dashboard support request', 180, ''),
                'category' => 'other', 'status' => 'open', 'priority' => 'normal', 'version' => 1,
                'created_at' => $message->created_at, 'updated_at' => $message->created_at,
            ]);
            $ticket->messages()->create(['author_id' => $authorId, 'request_key' => 'legacy-message-'.$message->id,
                'source_message_id' => $message->id, 'body' => $body, 'attachments_json' => $message->attachments_json,
                'kind' => $opened ? 'opened' : 'reply', 'internal' => false,
                'created_at' => $message->created_at, 'updated_at' => $message->updated_at]);
            if ($message->created_at->gt($ticket->updated_at)) {
                $ticket->timestamps = false;
                $ticket->update(['updated_at' => $message->created_at]);
            }

            return $ticket;
        }), 'support.import');
    }
}
