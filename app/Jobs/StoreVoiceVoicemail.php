<?php

namespace App\Jobs;

use App\Models\VoiceVoicemail;
use App\Services\Voice\VoiceVoicemailStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class StoreVoiceVoicemail implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public int $timeout = 45;
    public function __construct(public int $voicemailId)
    {
        // Recording I/O must not delay timed incoming offers or general mail/media.
        $this->onConnection(config('voice_calls.transcript_connection'));
        $this->onQueue(config('voice_calls.transcript_queue'));
        $this->afterCommit();
    }
    public function backoff(): array { return [10, 30, 120, 300]; }
    public function handle(VoiceVoicemailStorage $storage): void
    {
        if ($vm = VoiceVoicemail::find($this->voicemailId)) {
            $storage->store($vm);
        }
    }
    public function failed(?\Throwable $exception): void
    {
        if ($vm = VoiceVoicemail::find($this->voicemailId)) {
            app(\App\Services\Voice\VoiceVoicemailService::class)->fail($vm, 'Recording received, but private storage failed. A callback is required.');
        }
    }
}
