<?php

namespace App\Events;

use App\Http\Resources\Messaging\SmsThreadResource;
use App\Models\MessageThread;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SmsThreadUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public MessageThread $thread)
    {
        $this->thread->loadMissing('contact', 'assignedTo');
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('sms.thread-list')];
    }

    public function broadcastAs(): string
    {
        return 'SmsThreadUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'thread' => SmsThreadResource::make($this->thread)->resolve(),
        ];
    }
}

