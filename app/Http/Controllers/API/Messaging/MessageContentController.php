<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Models\{MessageMedia, SmsSharedMessage, SupportTicketMessage};
use App\Services\Messaging\{MessageContentAccess, MessageMediaService};
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\InputBag;

class MessageContentController extends Controller
{
    public const EMOJI = ['❤️', '👍', '👎', '😂', '😮', '😢', '🙏', '🎉', '🔥', '✅', '👏', '😍', '‼️', '❓'];
    public function index(Request $r)
    {
        $data = $r->validate(['kind' => ['required', Rule::in(['sms', 'shared', 'email', 'support'])],
            'ids' => ['required', 'array', 'max:100'], 'ids.*' => ['required', 'string', 'max:40', 'distinct']]);
        $targets = [];
        foreach ($data['ids'] as $id) { $targets[$id] = app(MessageContentAccess::class)->message($r->user(), $data['kind'], $id); }
        $files = MessageMedia::where('message_kind', $data['kind'])->whereIn('message_id', $data['ids'])->get()->groupBy('message_id');
        $reactions = DB::table('message_reactions')->where('message_kind', $data['kind'])->whereIn('message_id', $data['ids'])->get()->groupBy('message_id');
        return response()->json(['data' => collect($data['ids'])->mapWithKeys(fn ($id) => [$id => [
            'attachments' => ($files[$id] ?? collect())->map(fn ($file) => app(MessageMediaService::class)->payload($file))
                ->concat(app(MessageMediaService::class)->legacyFiles($targets[$id], $data['kind']))->values(),
            'reactions' => ($reactions[$id] ?? collect())->groupBy('emoji')->map(fn ($rows, $emoji) => [
                'emoji' => $emoji, 'count' => $rows->count(), 'mine' => $rows->contains(fn ($row) => $row->user_id === $r->user()->id),
            ])->values(),
        ]])])->header('Cache-Control', 'private, no-store');
    }
    public function react(Request $r, string $kind, string $id)
    {
        $data = $r->validate(['emoji' => ['present', 'nullable', Rule::in(self::EMOJI)], 'request_key' => ['required', 'uuid']]);
        $target = app(MessageContentAccess::class)->message($r->user(), $kind, $id, true);
        return Cache::lock('message-reaction:'.$kind.':'.$id.':'.$r->user()->id, 180)->block(3, function () use ($r, $data, $kind, $id, $target) {
            $query = DB::table('message_reactions')->where('message_kind', $kind)->where('message_id', $id)->where('user_id', $r->user()->id);
            $previous = $query->value('emoji');
            if ($previous === $data['emoji']) { return response()->json(['saved' => true]); }
            if (in_array($kind, ['sms', 'shared'])) {
                $quote = mb_substr((string) $target->body_text, 0, 200) ?: 'an attachment';
                $text = $data['emoji'] === null ? 'Removed '.$previous.' from “'.$quote.'”' : 'Reacted '.$data['emoji'].' to “'.$quote.'”';
                $fields = ['request_key' => $data['request_key'], 'channel' => $kind,
                    'conversation_id' => (string) ($kind === 'shared' ? $target->group_id : $target->thread_id), 'body' => $text];
                $forward = $r->duplicate(); $forward->replace($fields); $forward->setJson(new InputBag($fields));
                $sent = app(MessageSendController::class)->send($forward);
                if (! $sent->getData(true)['success']) { return $sent; }
                if ($kind === 'sms' && ($eventID = $sent->getData(true)['id'] ?? null)) {
                    $event = \App\Models\Message::find($eventID);
                    $event?->update(['metadata' => array_merge($event->metadata ?? [], ['app_reaction' => [
                        'emoji' => $data['emoji'] ?? $previous, 'action' => $data['emoji'] === null ? 'remove' : 'add',
                        'label' => 'Reaction', 'targetMessageId' => $id, 'quotedMessage' => $quote,
                    ]])]);
                }
            }
            LockedWrite::run(function () use ($query, $data, $kind, $id, $r) {
                if ($data['emoji'] === null) { $query->delete(); }
                else { DB::table('message_reactions')->updateOrInsert(['message_kind' => $kind, 'message_id' => $id, 'user_id' => $r->user()->id],
                    ['emoji' => $data['emoji'], 'created_at' => now(), 'updated_at' => now()]); }
            }, 'messaging.reaction');
            return response()->json(['saved' => true]);
        });
    }
}
