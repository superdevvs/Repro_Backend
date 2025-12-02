<?php

namespace App\Events;

use App\Http\Resources\Messaging\SmsMessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SmsMessageSent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Message $message)
    {
        $this->message->loadMissing('thread.contact');
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('sms.thread.'.$this->message->thread_id),
            new PrivateChannel('sms.thread-list'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'SmsMessageSent';
    }

    public function broadcastWith(): array
    {
        return [
            'threadId' => (string) $this->message->thread_id,
            'message' => SmsMessageResource::make($this->message)->resolve(),
        ];
    }
}

