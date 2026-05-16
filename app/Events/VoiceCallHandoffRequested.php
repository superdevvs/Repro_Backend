<?php

namespace App\Events;

use App\Models\VoiceCall;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VoiceCallHandoffRequested implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public VoiceCall $voiceCall)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('voice-calls')];
    }

    public function broadcastWith(): array
    {
        return ['voice_call_id' => $this->voiceCall->id];
    }
}
