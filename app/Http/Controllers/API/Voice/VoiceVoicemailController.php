<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Models\VoiceCall;
use App\Models\VoiceFollowUpTask;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VoiceVoicemailController extends Controller
{
    public function update(Request $request, VoiceCall $call): mixed
    {
        $data = $request->validate(['read' => ['sometimes', 'boolean'], 'resolved' => ['sometimes', 'boolean'], 'callback_task' => ['sometimes', 'boolean']]);
        $vm = $call->voicemail()->firstOrFail();
        LockedWrite::run(function () use ($request, $call, $vm, $data): void {
            if (array_key_exists('read', $data)) {
                $vm->read_at = $data['read'] ? now() : null;
            }
            if (array_key_exists('resolved', $data)) {
                $vm->resolved_at = $data['resolved'] ? now() : null;
                $vm->resolved_by = $data['resolved'] ? $request->user()->id : null;
                $call->update(['needs_follow_up' => ! $data['resolved']]);
                VoiceFollowUpTask::where('voice_call_id', $call->id)->update(['status' => $data['resolved'] ? 'completed' : 'open', 'completed_at' => $data['resolved'] ? now() : null]);
            }
            $vm->save();
            if ($data['callback_task'] ?? false) {
                VoiceFollowUpTask::firstOrCreate(['voice_call_id' => $call->id], ['title' => 'Return voicemail: '.$call->from_phone,
                    'assigned_to_user_id' => $request->user()->id, 'due_at' => now()->addHours(4), 'status' => 'open']);
            }
        });

        return response()->json($call->fresh()->load('voicemail'));
    }

    public function audio(VoiceCall $call): mixed
    {
        $vm = $call->voicemail()->firstOrFail();
        abort_if($vm->expires_at->isPast() || $vm->state === 'expired', 410, 'Voicemail retention period ended.');
        abort_unless($vm->state === 'saved' && $vm->recording_path && Storage::disk('voice_private')->exists($vm->recording_path), 404, 'Recording is not ready.');

        $response = response()->file(Storage::disk('voice_private')->path($vm->recording_path), ['Content-Type' => 'audio/mpeg', 'Cache-Control' => 'no-store']);
        $response->setPrivate();

        return $response;
    }
}
