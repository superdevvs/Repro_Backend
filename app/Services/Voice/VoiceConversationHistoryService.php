<?php

namespace App\Services\Voice;

use App\Models\VoiceCall;
use App\Models\VoiceCallTranscript;
use App\Services\TelnyxAi\VoiceLiveStreamService;
use App\Support\LockedWrite;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class VoiceConversationHistoryService
{
    /** Import a provider snapshot without replaying call controls or follow-up automations. */
    public function ingest(VoiceCall $call, array $data, bool $final = false): VoiceCall
    {
        $payload = $data['payload'] ?? [];
        $messages = $payload[$final ? 'messages' : 'message_history'] ?? null;
        if (! $call->call_control_id || ($payload['call_control_id'] ?? null) !== $call->call_control_id
            || ! is_array($messages) || ! array_is_list($messages) || $messages === []) {
            return $call;
        }
        $at = Carbon::parse($data['occurred_at'] ?? $call->ended_at ?? now());
        $speech = [];
        foreach ($messages as $message) {
            if (! is_array($message) || ! in_array($message['role'] ?? '', ['user', 'assistant'], true)
                || ! is_string($message['content'] ?? null) || trim($message['content']) === '') {
                continue;
            }
            $speech[] = $message;
        }
        if ($speech === []) {
            return $call;
        }

        return Cache::lock('voice-transcript:'.$call->id, 30)->block(3, fn () => LockedWrite::run(
            fn () => DB::transaction(function () use ($call, $speech, $at, $final, $data) {
                $call = $call->fresh();
                $previous = data_get($call->metadata, 'provider_history', []);
                if ((! $final && ($previous['final'] ?? false))
                    || ((! $final || ($previous['final'] ?? false)) && ! empty($previous['occurred_at'])
                        && $at->lt(Carbon::parse($previous['occurred_at'])))) {
                    return $call;
                }
                $prefix = 'telnyx-history:'.$call->id.':';
                if (! empty($data['id']) && ($previous['source_event_id'] ?? null) === $data['id']
                    && ($previous['final'] ?? false) === $final
                    && $call->transcriptRows()->where('provider_message_id', 'like', $prefix.'%')->count() === count($speech)) {
                    return $call;
                }
                $ids = [];
                foreach ($speech as $index => $message) {
                    $id = $prefix.$index;
                    $ids[] = $id;
                    $occurred = $message['timestamp'] ?? $message['created_at'] ?? $call->started_at ?? $at;
                    $values = ['speaker' => $message['role'] === 'assistant' ? 'assistant' : 'customer',
                        'transcript_type' => 'final', 'text' => trim($message['content']), 'occurred_at' => $occurred];
                    VoiceCallTranscript::updateOrCreate(['voice_call_id' => $call->id, 'provider_message_id' => $id], $values);
                    if ($call->ai_chat_session_id) {
                        $sessionMessage = $call->aiChatSession->messages()->where('metadata->provider_message_id', $id)->first();
                        $messageValues = ['sender' => $message['role'], 'content' => $values['text'],
                            'metadata' => ['provider' => 'telnyx', 'channel' => 'VOICE', 'provider_message_id' => $id,
                                'voice_call_id' => $call->id, 'occurred_at' => Carbon::parse($occurred)->toIso8601String()]];
                        $sessionMessage ? $sessionMessage->update($messageValues) : $call->aiChatSession->messages()->create($messageValues);
                    }
                }
                // Final history excludes tool messages and supersedes partial snapshots.
                // Only this derived projection is replaced; original provider events remain intact.
                $call->transcriptRows()->where('provider_message_id', 'like', $prefix.'%')->whereNotIn('provider_message_id', $ids)->delete();
                if ($call->ai_chat_session_id) {
                    $call->aiChatSession->messages()->where('metadata->provider_message_id', 'like', $prefix.'%')
                        ->whereNotIn('metadata->provider_message_id', $ids)->delete();
                }
                $metadata = $call->metadata ?? [];
                $metadata['provider_history'] = ['occurred_at' => $at->toISOString(), 'final' => $final,
                    'source_event_id' => $data['id'] ?? null, 'message_count' => count($speech)];
                $call->update(['metadata' => $metadata]);

                return app(VoiceLiveStreamService::class)->projectSavedTranscript($call->fresh());
            }), 'voice.history.snapshot'));
    }

    public function restoreSaved(VoiceCall $call): VoiceCall
    {
        foreach (['call.conversation.ended', 'call.ai_gather.message_history_updated'] as $type) {
            // Live events are ingested on receipt. Once restored, avoid scanning the
            // entire live history on each poll; a final snapshot is still checked above.
            if ($type === 'call.ai_gather.message_history_updated'
                && ($expected = data_get($call->metadata, 'provider_history.message_count', 0)) > 0
                && $call->transcriptRows()->where('provider_message_id', 'like', 'telnyx-history:'.$call->id.':%')->count() === $expected) {
                break;
            }
            $data = $this->latestSavedSnapshot($call, $type);
            if ($data) {
                $call = $this->ingest($call, $data, $type === 'call.conversation.ended');
                if (data_get($call->metadata, 'provider_history.final')) {
                    break;
                }
            }
        }

        return $call;
    }

    private function latestSavedSnapshot(VoiceCall $call, string $type): ?array
    {
        $latest = null;
        $latestAt = null;
        // Arrival IDs do not order provider time. Stream candidates to avoid loading
        // every accumulated message-history payload into memory during legacy repair.
        foreach ($call->events()->where('provider', 'telnyx')->where('event_type', $type)
            ->whereNotNull('processed_at')->orderBy('id')->cursor() as $event) {
            $data = $event->raw_payload['data'] ?? $event->raw_payload;
            $payload = $data['payload'] ?? [];
            $messages = $payload[$type === 'call.conversation.ended' ? 'messages' : 'message_history'] ?? [];
            if (! $call->call_control_id || ($payload['call_control_id'] ?? null) !== $call->call_control_id
                || ! is_array($messages) || ! array_is_list($messages)
                || ! collect($messages)->contains(fn ($message) => is_array($message)
                    && in_array($message['role'] ?? '', ['user', 'assistant'], true)
                    && is_string($message['content'] ?? null) && trim($message['content']) !== '')) {
                continue;
            }
            try {
                $at = Carbon::parse($data['occurred_at'] ?? $event->received_at ?? $call->ended_at ?? now());
            } catch (\Throwable) {
                continue;
            }
            if (! $latestAt || $at->gte($latestAt)) {
                $latestAt = $at;
                $data['occurred_at'] = $at->toISOString();
                $latest = $data;
            }
        }

        return $latest;
    }
}
